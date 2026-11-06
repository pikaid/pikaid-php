<?php

declare(strict_types=1);

use Pikaid\Pikaid;
use Pikaid\PikaidException;
use PHPUnit\Framework\TestCase;

final class PikaidConformanceTest extends TestCase
{
    private static array $vectors;

    public static function setUpBeforeClass(): void
    {
        $json = file_get_contents(__DIR__ . '/vectors/pikaid-v1.json');
        if ($json === false) {
            throw new RuntimeException('Cannot load conformance vectors.');
        }

        self::$vectors = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testValidVectorsRoundTripAndParse(): void
    {
        foreach (self::$vectors['valid'] as $vector) {
            $id = $vector['id'];
            $binary = hex2bin($vector['bin_hex']);
            self::assertIsString($binary);
            self::assertTrue(Pikaid::isWellFormed($id), $vector['name']);
            self::assertTrue(Pikaid::isValid($id), $vector['name']);
            self::assertSame($binary, Pikaid::toBinary($id), $vector['name']);
            self::assertSame($id, Pikaid::fromBinary($binary), $vector['name']);
            self::assertSame($vector['timestamp'], Pikaid::getTimestamp($id), $vector['name']);
            self::assertSame($vector['entropy_hex'], Pikaid::getEntropyHex($id), $vector['name']);
            self::assertSame($vector['entropy_hex'], bin2hex(Pikaid::getEntropyBytes($id)), $vector['name']);

            $parsed = Pikaid::parse($id);
            self::assertSame('UTC', $parsed['timestamp']->getTimezone()->getName());
            self::assertSame($vector['timestamp'], $parsed['timestamp']->getTimestamp());
            self::assertSame($vector['entropy_hex'], $parsed['randomness']);
            self::assertSame($parsed['randomness'], $parsed['entropy_hex']); // Legacy contract.
        }
    }

    public function testInvalidStringVectorsAreRejected(): void
    {
        foreach (self::$vectors['invalid']['string'] as $vector) {
            $id = $vector['id'];
            self::assertFalse(Pikaid::isValid($id), $vector['name']);
            $this->assertRejects(static function () use ($id): void {
                Pikaid::toBinary($id);
            }, $vector['name'] . '/toBinary');
            $this->assertRejects(static function () use ($id): void {
                Pikaid::parse($id);
            }, $vector['name'] . '/parse');
        }
    }

    public function testInvalidBinaryVectorsAreRejected(): void
    {
        foreach (self::$vectors['invalid']['binary'] as $vector) {
            $binary = hex2bin($vector['bin_hex']);
            self::assertIsString($binary);
            $this->assertRejects(static function () use ($binary): void {
                Pikaid::fromBinary($binary);
            }, $vector['name']);
        }
    }

    private function assertRejects(callable $operation, string $case): void
    {
        try {
            $operation();
        } catch (PikaidException $e) {
            self::assertGreaterThan(0, $e->getCode(), $case);
            return;
        }

        self::fail("Expected PikaidException: {$case}");
    }
}
