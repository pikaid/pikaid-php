<?php

declare(strict_types=1);

namespace Pikaid\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Pikaid\Pikaid;
use DateTimeImmutable;
use InvalidArgumentException;

final class PikaidTest extends TestCase
{
  /* ----------------------------- Validation ----------------------------- */

  #[DataProvider('invalidIdProvider')]
  public function testIsValidRejectsInvalidIds(string $invalid): void
  {
    $this->assertFalse(
      Pikaid::isValid($invalid),
      sprintf('"%s" should be invalid', $invalid)
    );
  }

  public static function invalidIdProvider(): array
  {
    return [
      'empty'            => [''],
      'too short'        => [str_repeat('a', 25)],
      'too long'         => [str_repeat('a', 27)],
      'uppercase'        => [str_repeat('Z', 26)],
      'non-alphanumeric' => [str_repeat('z', 25) . '!'],
      'space'            => [str_repeat('0', 25) . ' '],
    ];
  }

  public function testGenerateCreatesValidFormat(): void
  {
    $id = Pikaid::generate();
    $this->assertIsString($id);
    $this->assertMatchesRegularExpression(
      '/^[0-9a-z]{26}$/',
      $id,
      'Generated ID must be 26 lowercase base36 chars'
    );
  }

  public function testRandomnessPartIs19CharsBase36(): void
  {
    $id       = Pikaid::generate();
    $randPart = substr($id, 7);

    $this->assertSame(19, strlen($randPart));
    $this->assertMatchesRegularExpression('/^[0-9a-z]{19}$/', $randPart);
  }

  public function testMultipleGenerationsAreUnique(): void
  {
    $ids = [];
    for ($i = 0; $i < 1000; $i++) {
      $ids[] = Pikaid::generate();
    }

    $uniqueCount = count(array_unique($ids));
    $this->assertSame(
      1000,
      $uniqueCount,
      'All generated IDs in a batch should be unique'
    );
  }

  /* ------------------------------- Parsing ------------------------------ */

  public function testTimestampWithinGenerationBounds(): void
  {
    $start = time() - 1;
    $id    = Pikaid::generate();
    $end   = time() + 1;

    $timestamp = Pikaid::parse($id)['timestamp']->getTimestamp();
    $this->assertGreaterThanOrEqual(
      $start,
      $timestamp,
      'Parsed timestamp should not be before generation start'
    );
    $this->assertLessThanOrEqual(
      $end,
      $timestamp,
      'Parsed timestamp should not be after generation end'
    );
  }

  #[DataProvider('knownTimestampProvider')]
  public function testParseKnownTimestamps(
    int $seconds,
    string $rand36,
    string $expectedHex
  ): void {
    $ts36 = base_convert((string) $seconds, 10, 36);
    $ts36 = str_pad($ts36, 7, '0', STR_PAD_LEFT);
    $id   = $ts36 . $rand36;

    $data = Pikaid::parse($id);
    $this->assertSame(
      $seconds,
      $data['timestamp']->getTimestamp(),
      'Parsed timestamp should match expected'
    );
    $this->assertSame(
      $expectedHex,
      $data['randomness'],
      'Parsed randomness hex should match expected'
    );
    $this->assertSame(
      24,
      strlen($data['randomness']),
      'Randomness hex length must be 24'
    );
  }

  public static function knownTimestampProvider(): array
  {
    $zeroHex    = str_repeat('0', 24);
    $zeroBase36 = str_repeat('0', 19);
    $maxSeconds = (int) (pow(36, 7) - 1); // highest 7-char base36 timestamp

    return [
      'epoch'       => [0, $zeroBase36, $zeroHex],
      'max seconds' => [$maxSeconds, $zeroBase36, $zeroHex],
    ];
  }

  public function testParseThrowsOnInvalidInput(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Pikaid::parse('invalid_pikaid_format_!!!!!!!!!!!!');
  }

  /* ---------------------------- Binary helpers -------------------------- */

  public function testGenerateBinaryReturns17Bytes(): void
  {
    $bin = Pikaid::generateBinary();
    $this->assertSame(17, strlen($bin), 'Binary ID must be 17 bytes (v2)');
  }

  public function testToBinaryFromBinaryRoundTrip(): void
  {
    $id  = Pikaid::generate();
    $bin = Pikaid::toBinary($id);
    $this->assertSame(17, strlen($bin), 'Binary length must be 17 bytes (v2)');

    $id2 = Pikaid::fromBinary($bin);
    $this->assertSame(
      $id,
      $id2,
      'fromBinary(toBinary(ID)) should reconstruct the original ID'
    );
  }

  public function testFromBinaryRejectsInvalidLength(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Pikaid::fromBinary(str_repeat("\x00", 16)); // not 17 bytes
  }

  public function testToBinaryRejectsTimestampOver40Bits(): void
  {
    // Build a pikaID with timestamp > 0xFFFFFFFFFF (2^40 - 1)
    $seconds = 1099511627776; // 2^40
    $ts36 = str_pad(base_convert((string) $seconds, 10, 36), 7, '0', STR_PAD_LEFT);
    $id   = $ts36 . str_repeat('0', 19); // randomness = 0

    $this->expectException(InvalidArgumentException::class);
    Pikaid::toBinary($id);
  }

  public function testBinaryOrderingMatchesChronologicalSeconds(): void
  {
    // Two IDs with different known seconds and zero randomness
    $s1  = 1000;
    $s2  = 1001;
    $id1 = str_pad(base_convert((string) $s1, 10, 36), 7, '0', STR_PAD_LEFT) . str_repeat('0', 19);
    $id2 = str_pad(base_convert((string) $s2, 10, 36), 7, '0', STR_PAD_LEFT) . str_repeat('0', 19);

    $b1 = Pikaid::toBinary($id1);
    $b2 = Pikaid::toBinary($id2);

    // Lexicographic order on binary strings should match chronological order
    $this->assertTrue(
      strcmp($b1, $b2) < 0,
      'Binary lexicographic order must match chronological order (seconds)'
    );
  }

  public function testStringOrderingMatchesChronologicalSeconds(): void
  {
    // Same as above but with string comparison
    $s1  = 2000;
    $s2  = 2001;
    $id1 = str_pad(base_convert((string) $s1, 10, 36), 7, '0', STR_PAD_LEFT) . str_repeat('0', 19);
    $id2 = str_pad(base_convert((string) $s2, 10, 36), 7, '0', STR_PAD_LEFT) . str_repeat('0', 19);

    $this->assertTrue(
      $id1 < $id2,
      'String lexicographic order must match chronological order (seconds)'
    );
  }

  public function testFromDateTimeUsesProvidedSeconds(): void
  {
    $t  = new DateTimeImmutable('@1234567890'); // fixed timestamp
    $id = Pikaid::fromDateTime($t);

    $parsedSeconds = Pikaid::parse($id)['timestamp']->getTimestamp();
    $this->assertSame(1234567890, $parsedSeconds, 'fromDateTime() must encode provided seconds');
  }

  public function testBinaryToStringToBinaryRoundTrip(): void
  {
    $bin  = Pikaid::generateBinary();
    $str  = Pikaid::fromBinary($bin);
    $bin2 = Pikaid::toBinary($str);

    $this->assertSame(
      $bin,
      $bin2,
      'toBinary(fromBinary(bin)) should reconstruct the original binary'
    );
  }

  /* ------------------------ Additional MUST/MUST NOT ------------------------ */

  public function testTimestampLeftPaddingSevenChars(): void
  {
    $id = Pikaid::fromDateTime(new DateTimeImmutable('@1')); // 1 second
    $prefix = substr($id, 0, 7);
    $this->assertSame('0000001', $prefix, 'Timestamp MUST be left-padded to 7 chars');
  }

  public function testRandomnessLeftPaddingFromBinary(): void
  {
    // Build a binary ID with tiny randomness = 0x...01 so base36 would be "1" without padding
    $seconds = 123;
    $hi = intdiv($seconds, 4294967296);
    $lo = $seconds - $hi * 4294967296;

    $rand12 = str_repeat("\x00", 11) . "\x01"; // 11 zero bytes + 0x01
    $bin = chr($hi) . pack('N', $lo) . $rand12;

    $id = Pikaid::fromBinary($bin);
    $rand36 = substr($id, 7);
    $this->assertSame(19, strlen($rand36), 'Randomness MUST be 19 chars base36');
    $this->assertSame(str_pad('1', 19, '0', STR_PAD_LEFT), $rand36, 'Randomness MUST be left-padded to 19 chars');

    $parsed = Pikaid::parse($id);
    $this->assertSame(str_repeat('0', 23) . '1', $parsed['randomness'], 'Randomness hex MUST preserve 12 bytes verbatim');
  }

  public function testToBinaryRejectsInvalidStringLength(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Pikaid::toBinary('not26chars');
  }

  public function testToBinaryRejectsInvalidCharsetDash(): void
  {
    $invalid = '0000000' . str_repeat('0', 18) . '-';
    $invalid = substr($invalid, 0, 26);
    $this->expectException(InvalidArgumentException::class);
    Pikaid::toBinary($invalid);
  }

  public function testToBinaryRejectsUppercase(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Pikaid::toBinary(str_repeat('A', 26));
  }

  public function testFromBinaryRejects16BytesLength(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Pikaid::fromBinary(str_repeat("\x00", 16));
  }

  public function testFromBinaryRejects18BytesLength(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Pikaid::fromBinary(str_repeat("\x00", 18));
  }

  public function testFromBinaryRejectsTimestampBeyondBase36Range(): void
  {
    // timestamp = 36^7 (one beyond max representable by 7-char base36)
    $ts = (int) pow(36, 7);
    $hi = intdiv($ts, 4294967296);
    $lo = $ts - $hi * 4294967296;

    $bin = chr($hi) . pack('N', $lo) . str_repeat("\x00", 12);

    $this->expectException(InvalidArgumentException::class);
    Pikaid::fromBinary($bin);
  }

  public function testManualBigEndianMappingIndependently(): void
  {
    // Build a binary with timestamp 0x01_02_03_04_05 to assert big-endian mapping
    $ts = (1 << 32) + 0x02030405; // 0x0102030405
    $bin = chr(0x01) . pack('N', 0x02030405) . random_bytes(12);

    $id = Pikaid::fromBinary($bin);
    $parsedTs = Pikaid::parse($id)['timestamp']->getTimestamp();
    $this->assertSame($ts, $parsedTs, 'Timestamp MUST be big-endian (u40) in binary form');
  }

  public function testParseReturnsUtcTimezone(): void
  {
    $id = Pikaid::generate();
    $dt = Pikaid::parse($id)['timestamp'];
    $this->assertInstanceOf(DateTimeImmutable::class, $dt);
    $this->assertSame('UTC', $dt->getTimezone()->getName(), 'Parsed timestamp MUST be UTC');
  }

  public function testRandomnessVerbatimPreservedInBinaryRoundTrip(): void
  {
    $seconds = 424242;
    $hi = intdiv($seconds, 4294967296);
    $lo = $seconds - $hi * 4294967296;

    // Known 12-byte pattern
    $rand = "\xDE\xAD\xBE\xEF\x01\x23\x45\x67\x89\xAB\xCD\xEF";
    $bin = chr($hi) . pack('N', $lo) . $rand;

    $id = Pikaid::fromBinary($bin);
    $this->assertSame(bin2hex($rand), Pikaid::parse($id)['randomness'], 'Randomness MUST be preserved verbatim');

    $this->assertSame($bin, Pikaid::toBinary($id), 'Binary round-trip MUST be lossless');
  }
}
