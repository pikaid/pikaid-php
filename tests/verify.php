<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Pikaid\Pikaid;
use Pikaid\PikaidException;

$checks = 0;
$verify = static function (bool $condition, string $description) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException("Verification failed: {$description}");
    }
};
$rejects = static function (callable $operation, string $description) use ($verify): void {
    $rejected = false;
    try {
        $operation();
    } catch (PikaidException $e) {
        $rejected = true;
    }
    $verify($rejected, $description);
};

$vectors = json_decode(file_get_contents(__DIR__ . '/vectors/pikaid-v1.json'), true, 512, JSON_THROW_ON_ERROR);
$verify($vectors['meta']['spec_revision'] === '1.0.4', 'revision metadata');
foreach ($vectors['valid'] as $case) {
    $id = $case['id'];
    $binary = hex2bin($case['bin_hex']);
    $verify(is_string($binary), 'binary vector hex');
    $verify(Pikaid::isValid($id), $case['name'] . '/valid');
    $verify(Pikaid::toBinary($id) === $binary, $case['name'] . '/toBinary');
    $verify(Pikaid::fromBinary($binary) === $id, $case['name'] . '/fromBinary');
    $verify(Pikaid::getTimestamp($id) === $case['timestamp'], $case['name'] . '/timestamp');
    $verify(Pikaid::getEntropyHex($id) === $case['entropy_hex'], $case['name'] . '/randomness');
    $parsed = Pikaid::parse($id);
    $verify($parsed['randomness'] === $case['entropy_hex'], $case['name'] . '/parse randomness');
    $verify($parsed['entropy_hex'] === $parsed['randomness'], $case['name'] . '/legacy alias');
    $verify($parsed['timestamp']->getTimezone()->getName() === 'UTC', $case['name'] . '/utc');
}
foreach ($vectors['invalid']['string'] as $case) {
    $id = $case['id'];
    $verify(!Pikaid::isValid($id), $case['name'] . '/invalid string');
    $rejects(static function () use ($id): void { Pikaid::parse($id); }, $case['name'] . '/parse');
    $rejects(static function () use ($id): void { Pikaid::toBinary($id); }, $case['name'] . '/toBinary');
}
foreach ($vectors['invalid']['binary'] as $case) {
    $binary = hex2bin($case['bin_hex']);
    $verify(is_string($binary), 'invalid binary vector hex');
    $rejects(static function () use ($binary): void { Pikaid::fromBinary($binary); }, $case['name'] . '/invalid binary');
}
$overflow = '0000000' . str_repeat('z', 19);
$verify(Pikaid::isWellFormed($overflow), 'overflow is well-formed');
$verify(!Pikaid::isValid($overflow), 'overflow is not valid');
foreach ([0, 1, 1700000000, Pikaid::MAX_TIMESTAMP] as $timestamp) {
    $id = Pikaid::generateWithTimestamp($timestamp);
    $verify(Pikaid::getTimestamp($id) === $timestamp, 'timestamp bounds');
    $binary = Pikaid::generateBinaryWithTimestamp($timestamp);
    $verify(strlen($binary) === 17, 'binary length');
    $verify(Pikaid::getTimestamp(Pikaid::fromBinary($binary)) === $timestamp, 'binary generation timestamp');
}
foreach ([-1, Pikaid::MAX_TIMESTAMP + 1] as $timestamp) {
    $rejects(static function () use ($timestamp): void { Pikaid::generateWithTimestamp($timestamp); }, 'out-of-range generation');
}
$prior = null;
$seen = [];
for ($i = 0; $i < 1800; ++$i) {
    $timestamp = random_int(0, Pikaid::MAX_TIMESTAMP);
    $binary = Pikaid::generateBinaryWithTimestamp($timestamp);
    $id = Pikaid::fromBinary($binary);
    $verify(strlen($id) === 26, 'random string size');
    $verify(Pikaid::toBinary($id) === $binary, 'random round-trip');
    $verify(Pikaid::getTimestamp($id) === $timestamp, 'random timestamp');
    $verify(Pikaid::compare($id, $id) === 0, 'self compare');
    if ($prior !== null && $timestamp !== $prior[0]) {
        $comparison = Pikaid::compare($prior[1], $id);
        $verify(($comparison <=> 0) === ($prior[0] <=> $timestamp), 'timestamp sort');
        $verify(($comparison <=> 0) === (strcmp(Pikaid::toBinary($prior[1]), $binary) <=> 0), 'binary sort');
    }
    $prior = [$timestamp, $id];
    if ($i < 250) {
        $seen[$id] = true;
    }
}
$verify(count($seen) === 250, 'generation uniqueness sample');
$verify(Pikaid::isValid(Pikaid::fromDateTime(new DateTimeImmutable('@1700000000'))), 'DateTime generation');

if (function_exists('bcscale') && !function_exists('gmp_import')) {
    $originalScale = bcscale();
    bcscale(4);
    try {
        $binary = hex2bin('006553f10000112233445566778899aabb');
        $verify(Pikaid::toBinary(Pikaid::fromBinary($binary)) === $binary, 'BCMath explicit scale');
    } finally {
        bcscale($originalScale);
    }
}

echo sprintf("PASS: %d checks (PHP %s; GMP %s; BCMath %s)\n", $checks, PHP_VERSION, extension_loaded('gmp') ? 'yes' : 'no', extension_loaded('bcmath') ? 'yes' : 'no');
