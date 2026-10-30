<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PikaidConformanceTest extends TestCase
{
    private static array $vectors;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        $path = __DIR__ . '/vectors/pikaid-v1.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Unable to read vectors file: {$path}");
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::$vectors = $data;
    }

    public function testValidVectorsRoundTripAndParse(): void
    {
        $class = $this->resolvePikaidClass();

        foreach (self::$vectors['valid'] as $v) {
            $id = $v['id'];
            $bin = hex2bin($v['bin_hex']);
            $this->assertIsString($bin, 'hex2bin failed for valid vector');

            // isValid()
            $this->assertTrue($class::isValid($id), "Expected isValid(true) for vector {$v['name']}");

            // toBinary() and fromBinary() roundtrip
            $this->assertSame($bin, $class::toBinary($id), "toBinary mismatch for {$v['name']}");
            $this->assertSame($id, $class::fromBinary($bin), "fromBinary mismatch for {$v['name']}");

            // parse() extracts the expected timestamp and entropy
            $parsed = $class::parse($id);

            $this->assertArrayHasKey('timestamp', $parsed);
            $this->assertInstanceOf(DateTimeInterface::class, $parsed['timestamp']);
            $this->assertSame($v['timestamp'], $parsed['timestamp']->getTimestamp(), "timestamp mismatch for {$v['name']}");

            $entropyKey = array_key_exists('entropy_hex', $parsed) ? 'entropy_hex' : (array_key_exists('randomness', $parsed) ? 'randomness' : null);
            $this->assertNotNull($entropyKey, "parse() must return entropy_hex or randomness for {$v['name']}");
            $this->assertSame($v['entropy_hex'], $parsed[$entropyKey], "entropy hex mismatch for {$v['name']}");
        }
    }

    public function testInvalidStringVectorsAreRejected(): void
    {
        $class = $this->resolvePikaidClass();

        foreach (self::$vectors['invalid']['string'] as $v) {
            $id = $v['id'];

            $this->assertFalse($class::isValid($id), "Expected isValid(false) for invalid string vector {$v['name']}");

            try {
                $class::parse($id);
                $this->fail("parse() must throw on invalid string vector {$v['name']}");
            } catch (Throwable $e) {
                $this->assertTrue(true);
            }

            try {
                $class::toBinary($id);
                $this->fail("toBinary() must throw on invalid string vector {$v['name']}");
            } catch (Throwable $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function testInvalidBinaryVectorsAreRejected(): void
    {
        $class = $this->resolvePikaidClass();

        foreach (self::$vectors['invalid']['binary'] as $v) {
            $bin = hex2bin($v['bin_hex']);
            $this->assertIsString($bin, 'hex2bin failed for invalid binary vector');

            try {
                $class::fromBinary($bin);
                $this->fail("fromBinary() must throw on invalid binary vector {$v['name']}");
            } catch (Throwable $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function testGenerateProducesWellFormedId(): void
    {
        $class = $this->resolvePikaidClass();

        $id = $class::generate();
        $this->assertIsString($id);
        $this->assertSame(26, strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $id);
        $this->assertTrue($class::isValid($id));
    }

    private function resolvePikaidClass(): string
    {
        // Supports both namespaces used by different drafts
        $candidates = [
            'Pikaid\\Pikaid',
            'Pikaid\\PikaID',
            'Pikaid',
        ];

        foreach ($candidates as $c) {
            if (class_exists($c)) {
                return $c;
            }
        }

        $this->fail('Could not locate the Pikaid class (expected Pikaid\\Pikaid).');
    }
}
