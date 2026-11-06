<?php

declare(strict_types=1);

use Pikaid\Pikaid;
use Pikaid\PikaidException;
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
        foreach ([-1, Pikaid::MAX_TIMESTAMP + 1] as $timestamp) {
            try {
                Pikaid::generateWithTimestamp($timestamp);
                self::fail('Out-of-range timestamp accepted.');
            } catch (PikaidException $exception) {
                self::assertGreaterThan(0, $exception->getCode());
            }
        }
    }

    public function testValidationRejectsOverflowAndPreservesWellFormedDistinction(): void
    {
        $overflow = '0000000' . str_repeat('z', 19);
        self::assertTrue(Pikaid::isWellFormed($overflow));
        self::assertFalse(Pikaid::isValid($overflow));
        self::assertFalse(Pikaid::isWellFormed(strtoupper($overflow)));
        self::assertFalse(Pikaid::isWellFormed($overflow . '0'));
    }

    public function testParseHasCanonicalAndCompatibilityKeys(): void
    {
        $id = Pikaid::fromBinary(hex2bin('006553f10000112233445566778899aabb'));
        $parsed = Pikaid::parse($id);
        self::assertSame(1700000000, $parsed['timestamp']->getTimestamp());
        self::assertSame('UTC', $parsed['timestamp']->getTimezone()->getName());
        self::assertSame('00112233445566778899aabb', $parsed['randomness']);
        self::assertSame($parsed['randomness'], $parsed['entropy_hex']);
        self::assertSame([1700000000, hex2bin('00112233445566778899aabb')], Pikaid::decode($id));
    }

    public function testFromDateTimeUsesTimestampButFreshRandomness(): void
    {
        $timestamp = 1700000000;
        $id = Pikaid::fromDateTime(new DateTimeImmutable('@' . $timestamp));
        self::assertSame($timestamp, Pikaid::getTimestamp($id));
        self::assertTrue(Pikaid::isValid($id));
    }

    public function testAvailableMathBackendsAgree(): void
    {
        $gmp = new ReflectionProperty(Pikaid::class, 'hasGmp');
        $bcmath = new ReflectionProperty(Pikaid::class, 'hasBcmath');
        $originalGmp = $gmp->getValue();
        $originalBcmath = $bcmath->getValue();
        $originalScale = function_exists('bcscale') ? bcscale() : null;

        $backends = [[false, false]]; // Always verify the portable implementation.
        if (extension_loaded('bcmath')) {
            $backends[] = [false, true];
        }
        if (extension_loaded('gmp')) {
            $backends[] = [true, false];
        }

        try {
            if ($originalScale !== null) {
                bcscale(4); // Challenge ambient BCMath configuration.
            }
            foreach ($backends as [$useGmp, $useBcmath]) {
                $gmp->setValue(null, $useGmp);
                $bcmath->setValue(null, $useBcmath);
                foreach ([
                    str_repeat("\0", 17),
                    hex2bin('006553f10000112233445566778899aabb'),
                    hex2bin('123ede3fffffffffffffffffffffffffff'),
                ] as $binary) {
                    self::assertSame($binary, Pikaid::toBinary(Pikaid::fromBinary($binary)));
                }
                self::assertFalse(Pikaid::isValid('0000000' . str_repeat('z', 19)));
            }
        } finally {
            $gmp->setValue(null, $originalGmp);
            $bcmath->setValue(null, $originalBcmath);
            if ($originalScale !== null) {
                bcscale($originalScale);
            }
        }
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
    }
}
