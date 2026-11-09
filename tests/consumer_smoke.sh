#!/usr/bin/env bash
#
# Packaging hygiene and external consumer smoke test.
#
# Builds the distributable exactly as Packagist would (`composer archive`),
# asserts which files are (and are not) shipped, then installs the archive into
# a throwaway consumer project with its own composer.json and autoloader. The
# consumer exercises only the installed package, never this checkout.
#
# Requires: bash, composer, unzip, php.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/pikaid-consumer-XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

command -v composer >/dev/null 2>&1 || fail "composer is required"
command -v unzip >/dev/null 2>&1 || fail "unzip is required"
command -v php >/dev/null 2>&1 || fail "php is required"

echo "== Building distributable archive"
cd "$ROOT"
composer archive --format=zip --dir="$WORK" --file=pikaid-php-dist --no-interaction >/dev/null
ZIP="$WORK/pikaid-php-dist.zip"
[ -f "$ZIP" ] || fail "composer archive did not create $ZIP"

ENTRIES="$(unzip -Z1 "$ZIP")"

for required in composer.json LICENSE README.md CHANGELOG.md src/Pikaid.php src/PikaidException.php; do
  grep -qx "$required" <<<"$ENTRIES" || fail "archive is missing $required"
done

for forbidden in '^tests/' '^benchmarks/' '^\.github/' '^\.ai/' '^vendor/' '^phpunit\.xml\.dist$' '^composer\.lock$' '^opencode\.jsonc$' '\.DS_Store$'; do
  if grep -Eq "$forbidden" <<<"$ENTRIES"; then
    fail "archive contains development entry matching $forbidden: $(grep -E "$forbidden" <<<"$ENTRIES" | head -n 1)"
  fi
done

echo "== Archive hygiene OK ($(wc -l <<<"$ENTRIES" | tr -d ' ') entries)"

unzip -q "$ZIP" -d "$WORK/pkg"
[ -f "$WORK/pkg/src/Pikaid.php" ] || fail "archive did not extract src/Pikaid.php"

echo "== Installing archive into an isolated consumer project"
CONSUMER="$WORK/consumer"
mkdir -p "$CONSUMER"
cat > "$CONSUMER/composer.json" <<JSON
{
  "name": "pikaid/external-consumer-smoke",
  "description": "Throwaway consumer used by the pikaid-php release checks",
  "type": "project",
  "repositories": [
    {
      "type": "path",
      "url": "$WORK/pkg",
      "options": { "symlink": false }
    },
    { "packagist.org": false }
  ],
  "require": {
    "pikaid/pikaid-php": "@dev"
  },
  "minimum-stability": "dev",
  "prefer-stable": false
}
JSON

cd "$CONSUMER"
composer update --no-interaction --no-progress --prefer-dist >/dev/null
[ -f "$CONSUMER/vendor/autoload.php" ] || fail "consumer autoloader was not installed"

cat > "$CONSUMER/smoke.php" <<'PHP'
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pikaid\Pikaid;
use Pikaid\PikaidException;

$failures = [];
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$location = (new ReflectionClass(Pikaid::class))->getFileName();
$check(is_string($location) && strpos($location, '/vendor/pikaid/pikaid-php/') !== false, 'class loads from consumer vendor directory');

$zero = '00000000000000000000000000';
$zeroBinary = hex2bin(str_repeat('00', 17));
$check(Pikaid::isValid($zero), 'known all-zero id is valid');
$check(Pikaid::fromBinary($zeroBinary) === $zero, 'known all-zero binary round-trip');
$check(Pikaid::toBinary($zero) === $zeroBinary, 'known all-zero string round-trip');

$max = 'zzzzzzz7oiylpimjg5u2ca1ypr';
$maxBinary = hex2bin('123ede3fffffffffffffffffffffffffff');
$check(Pikaid::getTimestamp($max) === Pikaid::MAX_TIMESTAMP, 'max timestamp');
$check(Pikaid::toBinary($max) === $maxBinary, 'max binary encoding');
$check(Pikaid::fromBinary($maxBinary) === $max, 'max binary decoding');

$id = Pikaid::generate();
$check(strlen($id) === 26 && Pikaid::isValid($id), 'generated id is a valid 26-character pikaid');
$check(Pikaid::fromBinary(Pikaid::toBinary($id)) === $id, 'generated id round-trips');
$check(is_int(Pikaid::getTimestamp($id)), 'timestamp is an integer');
$check(count(Pikaid::decode($id)) === 2, 'decode returns timestamp and entropy');
$check(Pikaid::parse($id)['timestamp'] instanceof DateTimeImmutable, 'parse returns an immutable UTC timestamp');

$binary = Pikaid::generateBinary();
$check(strlen($binary) === 17 && Pikaid::isValid(Pikaid::fromBinary($binary)), 'generated binary id is 17 bytes and valid');

$check(Pikaid::compare($zero, $max) === -1 && Pikaid::compare($max, $zero) === 1, 'compare orders by timestamp');

try {
    Pikaid::toBinary('not-a-pikaid');
    $failures[] = 'invalid input was not rejected';
} catch (PikaidException $exception) {
    $check($exception instanceof InvalidArgumentException, 'rejection is an InvalidArgumentException');
}

if ($failures !== []) {
    fwrite(STDERR, "CONSUMER FAILURES:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "PASS: external consumer exercised the installed package (" . PHP_VERSION . ")\n";
PHP

php "$CONSUMER/smoke.php"
echo "== Packaging and external consumer smoke test passed"
