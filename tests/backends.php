<?php

declare(strict_types=1);

/**
 * Backend parity probe.
 *
 * Runs the pinned conformance matrix through the production public API exactly
 * once. The surrounding BackendParityTest starts this script with selected
 * extension functions disabled so that the GMP, BCMath and pure-PHP arithmetic
 * paths are each exercised on identical inputs, including failure paths.
 *
 * Output is a single JSON line describing which backend was actually used and
 * how many checks passed. Any mismatch throws, which makes the process fail.
 */

require_once __DIR__ . '/bootstrap.php';

use Pikaid\Pikaid;
use Pikaid\PikaidException;

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $label);
    }
};

$rejects = static function (int $expectedCode, callable $operation, string $label) use ($check): void {
    try {
        $operation();
    } catch (PikaidException $exception) {
        $check($exception->getCode() === $expectedCode, $label . ': expected code ' . $expectedCode . ', got ' . $exception->getCode());
        return;
    }
    throw new RuntimeException('FAILED: ' . $label . ' was not rejected');
};

$raw = file_get_contents(__DIR__ . '/vectors/conformance-matrix.json');
if ($raw === false) {
    throw new RuntimeException('Cannot read the conformance matrix.');
}
$matrix = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

$byName = [];
foreach ($matrix['valid'] as $case) {
    $byName[$case['name']] = $case;
    $label = 'valid/' . $case['name'];
    $binary = hex2bin($case['bin_hex']);
    $entropy = hex2bin($case['entropy_hex']);
    if ($binary === false || $entropy === false) {
        throw new RuntimeException('Invalid hex in matrix case ' . $case['name']);
    }

    $check(Pikaid::toBinary($case['id']) === $binary, $label . '/toBinary');
    $check(Pikaid::fromBinary($binary) === $case['id'], $label . '/fromBinary');
    $check(Pikaid::getTimestamp($case['id']) === $case['timestamp'], $label . '/timestamp');
    $check(Pikaid::getEntropyHex($case['id']) === $case['entropy_hex'], $label . '/entropy-hex');
    $check(Pikaid::getEntropyBytes($case['id']) === $entropy, $label . '/entropy-bytes');
    $check(Pikaid::decode($case['id']) === [$case['timestamp'], $entropy], $label . '/decode');
    $check(Pikaid::getTimestamp(Pikaid::fromBinary($binary)) === $case['timestamp'], $label . '/binary-timestamp');

    $parsed = Pikaid::parse($case['id']);
    $check($parsed['timestamp']->getTimestamp() === $case['timestamp'], $label . '/parse-timestamp');
    $check($parsed['randomness'] === $case['entropy_hex'], $label . '/parse-randomness');
}

foreach ($matrix['invalid']['string'] as $case) {
    $label = 'invalid-string/' . $case['name'];
    $check(Pikaid::isValid($case['id']) === false, $label . '/isValid');
    $check(Pikaid::isWellFormed($case['id']) === $case['shape_valid'], $label . '/isWellFormed');
    $rejects($case['code'], static function () use ($case): void {
        Pikaid::validateOrThrow($case['id']);
    }, $label . '/validateOrThrow');
    $rejects($case['code'], static function () use ($case): void {
        Pikaid::toBinary($case['id']);
    }, $label . '/toBinary');
    $rejects($case['code'], static function () use ($case): void {
        Pikaid::parse($case['id']);
    }, $label . '/parse');
}

foreach ($matrix['invalid']['binary'] as $case) {
    $binary = hex2bin($case['bin_hex']);
    if ($binary === false) {
        throw new RuntimeException('Invalid hex in matrix case ' . $case['name']);
    }
    $rejects($case['code'], static function () use ($binary): void {
        Pikaid::fromBinary($binary);
    }, 'invalid-binary/' . $case['name']);
}

// Generation entrypoints must work on every backend at both timestamp extremes.
foreach ([0, Pikaid::MAX_TIMESTAMP] as $timestamp) {
    $id = Pikaid::generateWithTimestamp($timestamp);
    $check(Pikaid::isValid($id), 'generate/' . $timestamp . '/valid');
    $check(Pikaid::getTimestamp($id) === $timestamp, 'generate/' . $timestamp . '/timestamp');
    $check(strlen(Pikaid::toBinary($id)) === Pikaid::BINARY_LENGTH, 'generate/' . $timestamp . '/toBinary');

    $binary = Pikaid::generateBinaryWithTimestamp($timestamp);
    $check(strlen($binary) === Pikaid::BINARY_LENGTH, 'generate-binary/' . $timestamp . '/length');
    $check(Pikaid::getTimestamp(Pikaid::fromBinary($binary)) === $timestamp, 'generate-binary/' . $timestamp . '/round-trip');
}

// Ordering is part of the arithmetic contract, so it is exercised per backend.
$previous = null;
foreach ($matrix['ordering'] as $name) {
    if (!isset($byName[$name])) {
        throw new RuntimeException('Missing ordering case ' . $name);
    }
    $id = $byName[$name]['id'];
    if ($previous !== null) {
        $check(strcmp($previous, $id) < 0, 'ordering/string/' . $name);
        $check(strcmp(Pikaid::toBinary($previous), Pikaid::toBinary($id)) < 0, 'ordering/binary/' . $name);
        $check(Pikaid::compare($previous, $id) === -1, 'ordering/compare/' . $name);
    }
    $previous = $id;
}

$check(is_subclass_of(PikaidException::class, InvalidArgumentException::class), 'exception-hierarchy');

// Force extension detection before introspection (conversions above already did).
$properties = (new ReflectionClass(Pikaid::class))->getStaticProperties();
$usedGmp = (bool) ($properties['hasGmp'] ?? false);
$usedBcmath = (bool) ($properties['hasBcmath'] ?? false);

echo json_encode([
    'gmp' => $usedGmp,
    'bcmath' => $usedBcmath,
    'checks' => $checks,
    'php' => PHP_VERSION,
    'extension_gmp' => extension_loaded('gmp'),
    'extension_bcmath' => extension_loaded('bcmath'),
], JSON_THROW_ON_ERROR), "\n";
