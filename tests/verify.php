<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Pikaid\Pikaid;
use Pikaid\PikaidException;
use Pikaid\Tests\IndependentOracle;

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
$rejectsWithCode = static function (callable $operation, int $code, string $description) use ($verify): void {
    try {
        $operation();
    } catch (PikaidException $e) {
        $verify($e->getCode() === $code, $description . ' (expected code ' . $code . ', got ' . $e->getCode() . ')');
        return;
    }
    $verify(false, $description . ' (not rejected)');
};

$vectorsPath = __DIR__ . '/vectors/pikaid-v1.json';
$rawVectors = file_get_contents($vectorsPath);
if ($rawVectors === false) {
    throw new RuntimeException('Cannot read conformance vectors.');
}

// Lock the official fixture bytes so accidental edits cannot silently weaken conformance.
$verify(
    hash('sha256', $rawVectors) === 'b0c9dfdd063613424414fef5c9065fbd2f81d645d0ab42b34e27cde715613500',
    'pikaid-v1.json checksum'
);
$verify(
    hash('sha256', (string) file_get_contents(__DIR__ . '/vectors/pikaid-v1.schema.json'))
        === '4441d4dbaa6c5450ae9cbfb2438c53bc0421bba2b7fcc9ef9b158e5d1de0759e',
    'pikaid-v1.schema.json checksum'
);

$vectors = json_decode($rawVectors, true, 512, JSON_THROW_ON_ERROR);
$verify($vectors['meta']['spec_revision'] === '1.0.4', 'revision metadata');
$verify($vectors['meta']['string_length'] === 26, 'string length metadata');
$verify($vectors['meta']['entropy']['bytes'] === 12, 'entropy byte metadata');
$verify($vectors['meta']['binary']['bytes'] === 17, 'binary byte metadata');
$verify($vectors['meta']['timestamp']['max'] === Pikaid::MAX_TIMESTAMP, 'timestamp maximum metadata');
foreach ($vectors['valid'] as $case) {
    $id = $case['id'];
    $binary = hex2bin($case['bin_hex']);
    $entropy = hex2bin($case['entropy_hex']);
    $verify(is_string($binary), 'binary vector hex');
    $verify(is_string($entropy), 'entropy vector hex');
    $verify(Pikaid::isValid($id), $case['name'] . '/valid');
    $verify(Pikaid::toBinary($id) === $binary, $case['name'] . '/toBinary');
    $verify(Pikaid::fromBinary($binary) === $id, $case['name'] . '/fromBinary');
    $verify(Pikaid::getTimestamp($id) === $case['timestamp'], $case['name'] . '/timestamp');
    $verify(Pikaid::getEntropyHex($id) === $case['entropy_hex'], $case['name'] . '/randomness');
    $verify(Pikaid::getEntropyBytes($id) === $entropy, $case['name'] . '/entropy bytes');
    $verify(IndependentOracle::encode($case['timestamp'], $entropy) === $id, $case['name'] . '/independent string oracle');
    $verify(IndependentOracle::binary($case['timestamp'], $entropy) === $binary, $case['name'] . '/independent binary oracle');
    $parsed = Pikaid::parse($id);
    $verify(array_keys($parsed) === ['timestamp', 'randomness', 'entropy_hex'], $case['name'] . '/parse keys');
    $verify($parsed['randomness'] === $case['entropy_hex'], $case['name'] . '/parse randomness');
    $verify($parsed['entropy_hex'] === $parsed['randomness'], $case['name'] . '/legacy alias');
    $verify($parsed['timestamp'] instanceof DateTimeImmutable, $case['name'] . '/immutable timestamp');
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

// Public error-code contract (1-6 are reachable; 7 remains reserved for backward compatibility).
$verify(
    [
        PikaidException::CODE_INVALID_LENGTH,
        PikaidException::CODE_INVALID_CHARACTER,
        PikaidException::CODE_TIMESTAMP_OVERFLOW,
        PikaidException::CODE_TIMESTAMP_NEGATIVE,
        PikaidException::CODE_ENTROPY_OVERFLOW,
        PikaidException::CODE_INVALID_BINARY_LENGTH,
        PikaidException::CODE_INVALID_BINARY_TYPE,
    ] === [1, 2, 3, 4, 5, 6, 7],
    'public error code constants'
);
$verify((new PikaidException('test', 7)) instanceof InvalidArgumentException, 'exception type');
$rejectsWithCode(static function (): void { Pikaid::validateOrThrow('short'); }, 1, 'code 1 invalid length');
$rejectsWithCode(
    static function (): void { Pikaid::validateOrThrow(str_repeat('0', 25) . 'A'); },
    2,
    'code 2 invalid character'
);
$rejectsWithCode(
    static function (): void { Pikaid::fromBinary(hex2bin('123ede4000' . str_repeat('00', 12))); },
    3,
    'code 3 binary timestamp overflow'
);
$rejectsWithCode(
    static function (): void { Pikaid::generateWithTimestamp(Pikaid::MAX_TIMESTAMP + 1); },
    3,
    'code 3 generation timestamp overflow'
);
$rejectsWithCode(
    static function (): void { Pikaid::generateWithTimestamp(-1); },
    4,
    'code 4 negative timestamp'
);
$overflow = '0000000' . str_repeat('z', 19);
$rejectsWithCode(
    static function () use ($overflow): void { Pikaid::validateOrThrow($overflow); },
    5,
    'code 5 entropy overflow'
);
$rejectsWithCode(
    static function (): void { Pikaid::fromBinary(str_repeat("\0", 16)); },
    6,
    'code 6 binary length 16'
);
$rejectsWithCode(
    static function (): void { Pikaid::fromBinary(str_repeat("\0", 18)); },
    6,
    'code 6 binary length 18'
);
$verify(PikaidException::CODE_INVALID_BINARY_TYPE === 7, 'code 7 reserved constant');

$verify(Pikaid::isWellFormed($overflow), 'overflow is well-formed');
$verify(!Pikaid::isValid($overflow), 'overflow is not valid');
$verify(!Pikaid::isWellFormed(strtoupper($overflow)), 'uppercase is not well-formed');
$verify(!Pikaid::isWellFormed('é' . str_repeat('0', 24)), 'non-ASCII is not well-formed');
$verify(!Pikaid::isWellFormed(substr($overflow, 0, 25)), 'short input is not well-formed');
$verify(!Pikaid::isWellFormed(''), 'empty input is not well-formed');

// Exact binary boundaries and leading-zero preservation.
$zeroBinary = str_repeat("\0", 17);
$verify(Pikaid::fromBinary($zeroBinary) === '00000000000000000000000000', 'zero binary');
$verify(Pikaid::toBinary('00000000000000000000000000') === $zeroBinary, 'zero binary round-trip');
$oneBinary = hex2bin('0000000001' . str_repeat('00', 12));
$verify(is_string($oneBinary), 'one binary hex');
$verify(Pikaid::fromBinary($oneBinary) === '0000001' . str_repeat('0', 19), 'leading zero preservation');
$maxBinary = hex2bin('123ede3fffffffffffffffffffffffffff');
$verify(is_string($maxBinary), 'max binary hex');
$verify(Pikaid::fromBinary($maxBinary) === 'zzzzzzz7oiylpimjg5u2ca1ypr', 'max binary');
$verify(Pikaid::toBinary('zzzzzzz7oiylpimjg5u2ca1ypr') === $maxBinary, 'max binary round-trip');
$rejectsWithCode(
    static function (): void { Pikaid::fromBinary(hex2bin('123ede4000' . str_repeat('00', 12))); },
    3,
    'first binary timestamp above maximum'
);

foreach ([0, 1, 1700000000, Pikaid::MAX_TIMESTAMP] as $timestamp) {
    $id = Pikaid::generateWithTimestamp($timestamp);
    $verify(Pikaid::getTimestamp($id) === $timestamp, 'timestamp bounds');
    $binary = Pikaid::generateBinaryWithTimestamp($timestamp);
    $verify(strlen($binary) === 17, 'binary length');
    $verify(Pikaid::getTimestamp(Pikaid::fromBinary($binary)) === $timestamp, 'binary generation timestamp');
}
$rejectsWithCode(
    static function (): void { Pikaid::generateWithTimestamp(-1); },
    4,
    'negative generation timestamp'
);
$rejectsWithCode(
    static function (): void { Pikaid::generateWithTimestamp(Pikaid::MAX_TIMESTAMP + 1); },
    3,
    'overflow generation timestamp'
);

// Independent oracle checks over deterministic and random inputs.
$oracleCases = [
    [0, str_repeat("\0", 12)],
    [1, str_repeat("\0", 12)],
    [1700000000, hex2bin('00112233445566778899aabb')],
    [Pikaid::MAX_TIMESTAMP - 1, str_repeat("\xff", 12)],
    [Pikaid::MAX_TIMESTAMP, str_repeat("\xff", 12)],
    [Pikaid::MAX_TIMESTAMP, hex2bin('800000000000000000000001')],
];
foreach ($oracleCases as [$timestamp, $entropy]) {
    $binary = IndependentOracle::binary($timestamp, $entropy);
    $expected = IndependentOracle::encode($timestamp, $entropy);
    $verify(Pikaid::fromBinary($binary) === $expected, 'oracle deterministic string');
    $verify(Pikaid::toBinary($expected) === $binary, 'oracle deterministic binary');
    $verify(Pikaid::getTimestamp($expected) === $timestamp, 'oracle deterministic timestamp');
}
for ($i = 0; $i < 64; ++$i) {
    $timestamp = random_int(0, Pikaid::MAX_TIMESTAMP);
    $entropy = random_bytes(12);
    $binary = IndependentOracle::binary($timestamp, $entropy);
    $expected = IndependentOracle::encode($timestamp, $entropy);
    $verify(Pikaid::fromBinary($binary) === $expected, 'oracle random string');
    $verify(Pikaid::toBinary($expected) === $binary, 'oracle random binary');
    $verify(Pikaid::getTimestamp($expected) === $timestamp, 'oracle random timestamp');
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

// Force each available backend, including BCMath, and vary its ambient scale.
//
// A backend may only be forced when every function that backend calls exists.
// Enumerating from a single sentinel (historically bcscale()) could select
// BCMath while bcadd()/bcmul()/... were unavailable, so a restricted runtime
// with selectively disabled functions aborted instead of falling back. These
// sets mirror tests/BackendParityTest.php and the calls made in src/Pikaid.php.
$gmpFunctions = ['gmp_import', 'gmp_export', 'gmp_init', 'gmp_strval'];
$bcmathFunctions = ['bcadd', 'bcmul', 'bccomp', 'bcmod', 'bcdiv'];
$hasGmpFunctions = true;
foreach ($gmpFunctions as $function) {
    if (!function_exists($function)) {
        $hasGmpFunctions = false;
        break;
    }
}
$hasBcmathFunctions = true;
foreach ($bcmathFunctions as $function) {
    if (!function_exists($function)) {
        $hasBcmathFunctions = false;
        break;
    }
}

$gmpProperty = new ReflectionProperty(Pikaid::class, 'hasGmp');
$bcmathProperty = new ReflectionProperty(Pikaid::class, 'hasBcmath');
if (PHP_VERSION_ID < 80100) {
    $gmpProperty->setAccessible(true);
    $bcmathProperty->setAccessible(true);
}
$originalGmp = $gmpProperty->getValue();
$originalBcmath = $bcmathProperty->getValue();
// Varying the ambient scale is only meaningful when BCMath is usable and the
// setter itself is available; otherwise the scale block is skipped entirely.
$originalScale = $hasBcmathFunctions && function_exists('bcscale') ? bcscale() : null;
$backends = [[false, false]];
if ($hasBcmathFunctions) {
    $backends[] = [false, true];
}
if ($hasGmpFunctions) {
    $backends[] = [true, false];
    $backends[] = [true, true];
}
$scales = $originalScale === null ? [null] : [0, 4, 20];

try {
    foreach ($scales as $scale) {
        if ($scale !== null) {
            bcscale($scale);
        }
        foreach ($backends as $backend) {
            $gmpProperty->setValue(null, $backend[0]);
            $bcmathProperty->setValue(null, $backend[1]);
            $label = 'backend gmp=' . ($backend[0] ? '1' : '0')
                . ' bcmath=' . ($backend[1] ? '1' : '0')
                . ' scale=' . (string) $scale;

            foreach ($oracleCases as [$timestamp, $entropy]) {
                $binary = IndependentOracle::binary($timestamp, $entropy);
                $expected = IndependentOracle::encode($timestamp, $entropy);
                $verify(Pikaid::fromBinary($binary) === $expected, $label . '/string');
                $verify(Pikaid::toBinary($expected) === $binary, $label . '/binary');
            }

            $verify(!Pikaid::isValid($overflow), $label . '/overflow invalid');
            $rejectsWithCode(
                static function () use ($overflow): void { Pikaid::validateOrThrow($overflow); },
                5,
                $label . '/overflow code'
            );

            $generated = Pikaid::generateWithTimestamp(1700000000);
            $verify(Pikaid::isValid($generated), $label . '/generated valid');
            $verify(Pikaid::getTimestamp($generated) === 1700000000, $label . '/generated timestamp');
            $verify(Pikaid::fromBinary(Pikaid::toBinary($generated)) === $generated, $label . '/generated round-trip');
        }
    }
} finally {
    $gmpProperty->setValue(null, $originalGmp);
    $bcmathProperty->setValue(null, $originalBcmath);
    if ($originalScale !== null) {
        bcscale($originalScale);
    }
}

// Report the arithmetic backends that are usable in this runtime, not merely
// whether the extensions are loaded: disable_functions can remove individual
// functions while extension_loaded() still reports yes.
echo sprintf(
    "PASS: %d checks (PHP %s; GMP %s; BCMath %s)\n",
    $checks,
    PHP_VERSION,
    $hasGmpFunctions ? 'yes' : 'no',
    $hasBcmathFunctions ? 'yes' : 'no'
);
