<?php

declare(strict_types=1);

use Pikaid\Pikaid;
use Pikaid\PikaidException;
use Pikaid\Tests\IndependentOracle;
use PHPUnit\Framework\TestCase;

final class PikaidBehaviorTest extends TestCase
{
    public function testGenerationIsValidAndRandom(): void
    {
        $unique = [];
        for ($i = 0; $i < 250; ++$i) {
            $id = Pikaid::generate();
            self::assertSame(26, strlen($id));
            self::assertTrue(Pikaid::isValid($id));
            $unique[$id] = true;
        }
        self::assertCount(250, $unique);
    }

    public function testTimestampBoundsAndBinaryGeneration(): void
    {
        foreach ([0, 1, 1700000000, Pikaid::MAX_TIMESTAMP] as $timestamp) {
            $id = Pikaid::generateWithTimestamp($timestamp);
            $binary = Pikaid::generateBinaryWithTimestamp($timestamp);
            self::assertSame($timestamp, Pikaid::getTimestamp($id));
            self::assertSame($timestamp, Pikaid::getTimestamp(Pikaid::fromBinary($binary)));
            self::assertSame(17, strlen($binary));
            self::assertSame($id, Pikaid::fromBinary(Pikaid::toBinary($id)));
        }
    }

    public function testTimestampBoundsRejectOutOfRange(): void
    {
        self::assertPikaidError(4, static function (): void {
            Pikaid::generateWithTimestamp(-1);
        }, 'negative timestamp');
        self::assertPikaidError(3, static function (): void {
            Pikaid::generateWithTimestamp(Pikaid::MAX_TIMESTAMP + 1);
        }, 'timestamp above maximum');
    }

    public function testPublicErrorCodesAreStable(): void
    {
        self::assertSame(
            [1, 2, 3, 4, 5, 6, 7],
            [
                PikaidException::CODE_INVALID_LENGTH,
                PikaidException::CODE_INVALID_CHARACTER,
                PikaidException::CODE_TIMESTAMP_OVERFLOW,
                PikaidException::CODE_TIMESTAMP_NEGATIVE,
                PikaidException::CODE_ENTROPY_OVERFLOW,
                PikaidException::CODE_INVALID_BINARY_LENGTH,
                PikaidException::CODE_INVALID_BINARY_TYPE,
            ]
        );

        self::assertPikaidError(1, static function (): void {
            Pikaid::validateOrThrow('short');
        }, 'invalid length');
        self::assertPikaidError(2, static function (): void {
            Pikaid::validateOrThrow(str_repeat('0', 25) . 'A');
        }, 'invalid character');
        self::assertPikaidError(3, static function (): void {
            Pikaid::fromBinary(hex2bin('123ede4000' . str_repeat('00', 12)));
        }, 'binary timestamp overflow');
        self::assertPikaidError(5, static function (): void {
            Pikaid::validateOrThrow('0000000' . str_repeat('z', 19));
        }, 'entropy overflow');
        self::assertPikaidError(6, static function (): void {
            Pikaid::fromBinary(str_repeat("\0", 16));
        }, 'binary length below 17');
        self::assertPikaidError(6, static function (): void {
            Pikaid::fromBinary(str_repeat("\0", 18));
        }, 'binary length above 17');
        self::assertSame(7, PikaidException::CODE_INVALID_BINARY_TYPE, 'reserved binary type code');
        self::assertInstanceOf(InvalidArgumentException::class, new PikaidException('test', 7));
    }

    public function testWellFormedIsShapeOnly(): void
    {
        $valid = Pikaid::fromBinary(hex2bin('006553f10000112233445566778899aabb'));
        $overflow = '0000000' . str_repeat('z', 19);

        self::assertTrue(Pikaid::isWellFormed($valid));
        self::assertTrue(Pikaid::isWellFormed($overflow));
        self::assertFalse(Pikaid::isValid($overflow));
        self::assertFalse(Pikaid::isWellFormed(strtoupper($valid)));
        self::assertFalse(Pikaid::isWellFormed(substr($valid, 0, 25)));
        self::assertFalse(Pikaid::isWellFormed($valid . '0'));
        self::assertFalse(Pikaid::isWellFormed(str_replace('0', '-', $valid)));
        self::assertFalse(Pikaid::isWellFormed('é' . str_repeat('0', 24)));
        self::assertFalse(Pikaid::isWellFormed(''));
    }

    public function testParseHasCanonicalAndCompatibilityKeys(): void
    {
        $binary = hex2bin('006553f10000112233445566778899aabb');
        $id = Pikaid::fromBinary($binary);
        $parsed = Pikaid::parse($id);

        self::assertSame(['timestamp', 'randomness', 'entropy_hex'], array_keys($parsed));
        self::assertInstanceOf(DateTimeImmutable::class, $parsed['timestamp']);
        self::assertSame(1700000000, $parsed['timestamp']->getTimestamp());
        self::assertSame('UTC', $parsed['timestamp']->getTimezone()->getName());
        self::assertSame('00112233445566778899aabb', $parsed['randomness']);
        self::assertSame($parsed['randomness'], $parsed['entropy_hex']);
        self::assertSame(24, strlen($parsed['randomness']));
        self::assertSame(Pikaid::getEntropyHex($id), $parsed['randomness']);
        self::assertSame(Pikaid::getEntropyBytes($id), hex2bin($parsed['randomness']));
        self::assertSame([1700000000, hex2bin('00112233445566778899aabb')], Pikaid::decode($id));
        self::assertPikaidError(1, static function (): void {
            Pikaid::parse('short');
        }, 'parse rejects invalid input');
    }

    public function testFromDateTimeUsesTimestampButFreshRandomness(): void
    {
        $timestamp = 1700000000;
        $id = Pikaid::fromDateTime(new DateTimeImmutable('@' . $timestamp));
        self::assertSame($timestamp, Pikaid::getTimestamp($id));
        self::assertTrue(Pikaid::isValid($id));
    }

    public function testIndependentOracleMatchesOfficialVectors(): void
    {
        $vectors = json_decode(
            file_get_contents(__DIR__ . '/vectors/pikaid-v1.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($vectors['valid'] as $vector) {
            $entropy = hex2bin($vector['entropy_hex']);
            self::assertIsString($entropy);
            $binary = hex2bin($vector['bin_hex']);
            self::assertIsString($binary);
            self::assertSame($vector['id'], IndependentOracle::encode($vector['timestamp'], $entropy));
            self::assertSame($binary, IndependentOracle::binary($vector['timestamp'], $entropy));
            self::assertSame($vector['id'], Pikaid::fromBinary($binary));
            self::assertSame($binary, Pikaid::toBinary($vector['id']));
        }
    }

    public function testAvailableMathBackendsAgree(): void
    {
        $gmp = new ReflectionProperty(Pikaid::class, 'hasGmp');
        $bcmath = new ReflectionProperty(Pikaid::class, 'hasBcmath');
        $originalGmp = $gmp->getValue();
        $originalBcmath = $bcmath->getValue();
        $originalScale = function_exists('bcscale') ? bcscale() : null;

        $backends = [[false, false]]; // The portable implementation is always available.
        if (function_exists('bcscale')) {
            $backends[] = [false, true];
        }
        if (function_exists('gmp_import')) {
            $backends[] = [true, false];
            $backends[] = [true, true];
        }

        $scales = $originalScale === null ? [null] : [0, 4, 20];
        $cases = [
            [0, str_repeat("\0", 12)],
            [1, str_repeat("\0", 12)],
            [1700000000, hex2bin('00112233445566778899aabb')],
            [Pikaid::MAX_TIMESTAMP, str_repeat("\xff", 12)],
            [Pikaid::MAX_TIMESTAMP, hex2bin('800000000000000000000001')],
        ];
        $overflow = '0000000' . str_repeat('z', 19);

        try {
            foreach ($scales as $scale) {
                if ($scale !== null) {
                    bcscale($scale); // Challenge ambient BCMath configuration.
                }

                foreach ($backends as [$useGmp, $useBcmath]) {
                    $gmp->setValue(null, $useGmp);
                    $bcmath->setValue(null, $useBcmath);
                    $label = sprintf('backend gmp=%d bcmath=%d scale=%s', $useGmp, $useBcmath, (string) $scale);

                    foreach ($cases as [$timestamp, $entropy]) {
                        self::assertIsString($entropy, $label);
                        $binary = IndependentOracle::binary($timestamp, $entropy);
                        $expected = IndependentOracle::encode($timestamp, $entropy);
                        self::assertSame($expected, Pikaid::fromBinary($binary), $label);
                        self::assertSame($binary, Pikaid::toBinary($expected), $label);
                        self::assertSame($timestamp, Pikaid::getTimestamp($expected), $label);
                    }

                    self::assertFalse(Pikaid::isValid($overflow), $label);
                    self::assertPikaidError(5, static function () use ($overflow): void {
                        Pikaid::validateOrThrow($overflow);
                    }, $label);

                    $generated = Pikaid::generateWithTimestamp(1700000000);
                    self::assertTrue(Pikaid::isValid($generated), $label);
                    self::assertSame(1700000000, Pikaid::getTimestamp($generated), $label);
                    self::assertSame(17, strlen(Pikaid::toBinary($generated)), $label);
                    self::assertSame($generated, Pikaid::fromBinary(Pikaid::toBinary($generated)), $label);
                }
            }
        } finally {
            $gmp->setValue(null, $originalGmp);
            $bcmath->setValue(null, $originalBcmath);
            if ($originalScale !== null) {
                bcscale($originalScale);
            }
        }
    }

    public function testBinaryBoundariesAndLeadingZeros(): void
    {
        $zero = str_repeat("\0", 17);
        self::assertSame('00000000000000000000000000', Pikaid::fromBinary($zero));
        self::assertSame($zero, Pikaid::toBinary('00000000000000000000000000'));

        $one = hex2bin('0000000001' . str_repeat('00', 12));
        self::assertIsString($one);
        self::assertSame('0000001' . str_repeat('0', 19), Pikaid::fromBinary($one));

        $max = hex2bin('123ede3fffffffffffffffffffffffffff');
        self::assertIsString($max);
        self::assertSame('zzzzzzz7oiylpimjg5u2ca1ypr', Pikaid::fromBinary($max));
        self::assertSame($max, Pikaid::toBinary('zzzzzzz7oiylpimjg5u2ca1ypr'));

        self::assertPikaidError(3, static function (): void {
            Pikaid::fromBinary(hex2bin('123ede4000' . str_repeat('00', 12)));
        }, 'first binary timestamp above maximum');
        self::assertPikaidError(6, static function (): void {
            Pikaid::fromBinary(str_repeat("\0", 16));
        }, '16-byte binary');
        self::assertPikaidError(6, static function (): void {
            Pikaid::fromBinary(str_repeat("\0", 18));
        }, '18-byte binary');
    }

    public function testSpecInvalidBinaryTimestampExample(): void
    {
        $binary = hex2bin('ffffffffff' . str_repeat('00', 12));
        self::assertIsString($binary);
        try {
            Pikaid::fromBinary($binary);
        } catch (PikaidException $exception) {
            self::assertSame(PikaidException::CODE_TIMESTAMP_OVERFLOW, $exception->getCode());
            return;
        }

        self::fail('The canonical specification overflow example must be rejected.');
    }

    public function testBytewiseCompareAndOrderingAcrossDifferentSeconds(): void
    {
        $a = Pikaid::generateWithTimestamp(100);
        $b = Pikaid::generateWithTimestamp(101);
        self::assertLessThan(0, strcmp($a, $b));
        self::assertSame(-1, Pikaid::compare($a, $b));
        self::assertSame(1, Pikaid::compare($b, $a));
        self::assertSame(0, Pikaid::compare($a, $a));
        self::assertLessThan(0, strcmp(Pikaid::toBinary($a), Pikaid::toBinary($b)));

        $low = Pikaid::fromBinary(IndependentOracle::binary(200, str_repeat("\0", 12)));
        $high = Pikaid::fromBinary(IndependentOracle::binary(200, str_repeat("\xff", 12)));
        self::assertLessThan(0, strcmp($low, $high));
        self::assertSame(-1, Pikaid::compare($low, $high));
        self::assertLessThan(0, strcmp(Pikaid::toBinary($low), Pikaid::toBinary($high)));
    }

    public function testCsprngFailureIsFailClosed(): void
    {
        $root = dirname(__DIR__);
        $script = <<<'PHP'
require %s;
require %s;
try {
    \Pikaid\Pikaid::generateWithTimestamp(-1);
    echo "GENERATED_INVALID\n";
    exit(20);
} catch (\Pikaid\PikaidException $e) {
    if ($e->getCode() !== 4) {
        echo "WRONG_CODE_", $e->getCode(), "\n";
        exit(21);
    }
    echo "REJECTED_BEFORE_ENTROPY\n";
}
try {
    \Pikaid\Pikaid::generate();
    echo "GENERATED_WITHOUT_CSPRNG\n";
    exit(30);
} catch (\Throwable $e) {
    echo "FAIL_CLOSED_", get_class($e), "\n";
}
PHP;
        $script = sprintf(
            $script,
            var_export($root . '/src/PikaidException.php', true),
            var_export($root . '/src/Pikaid.php', true)
        );

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            [PHP_BINARY, '-n', '-d', 'disable_functions=random_bytes', '-r', $script],
            $descriptors,
            $pipes
        );
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(0, $status, $stdout . $stderr);
        self::assertSame("REJECTED_BEFORE_ENTROPY\nFAIL_CLOSED_Error", trim($stdout));
        self::assertSame('', trim($stderr));
    }

    private static function assertPikaidError(int $expectedCode, callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (PikaidException $exception) {
            self::assertInstanceOf(InvalidArgumentException::class, $exception, $message);
            self::assertSame($expectedCode, $exception->getCode(), $message);
            return;
        }

        self::fail($message . ': expected PikaidException with code ' . $expectedCode);
    }
}
