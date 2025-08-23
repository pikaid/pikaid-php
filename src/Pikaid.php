<?php

declare(strict_types=1);

namespace Pikaid;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Pikaid: 26-char, lowercase Base36 identifier.
 * Layout (string): [7 chars timestamp][19 chars randomness]
 * Binary layout (17 bytes, v2): [0]=ts high byte, [1..4]=ts low (uint32 BE), [5..16]=96-bit entropy
 * Ordering: binary lexicographic order == chronological order (seconds).
 * Fallback order for base36<->bytes: GMP -> BCMath -> pure PHP.
 */
final class Pikaid
{
  public const LENGTH        = 26;
  public const BINARY_LENGTH = 17; // v2: 5B timestamp + 12B entropy

  private const TS_LENGTH    = 7;   // base36 timestamp length
  private const RAND_BYTES   = 12;  // 96-bit entropy
  private const RAND_LEN36   = 19;  // base36 length for 96-bit, left-padded
  private const MAX_TS_BASE36 = 78364164095; // 36^7 - 1

  private static ?bool $hasGmp      = null;
  private static ?bool $hasBcmath   = null;
  private static ?DateTimeZone $utc = null;

  /* ------------------------------ Public API ------------------------------ */

  /**
   * Generate a 26-char pikaID string (base36).
   */
  public static function generate(): string
  {
    $ts36 = str_pad(base_convert((string) time(), 10, 36), self::TS_LENGTH, '0', STR_PAD_LEFT);

    $randBytes = random_bytes(self::RAND_BYTES);
    $rand36    = self::bytesToBase36($randBytes);
    $rand36    = str_pad($rand36, self::RAND_LEN36, '0', STR_PAD_LEFT);

    return $ts36 . $rand36;
  }

  /**
   * Generate a 17-byte binary pikaID directly (v2).
   * Binary layout (big-endian): [5 bytes timestamp seconds][12 bytes entropy].
   */
  public static function generateBinary(): string
  {
    $sec = time(); // fits in 40 bits for the spec horizon (~year 4453)
    $ts5 = chr(($sec >> 32) & 0xFF) . pack('N', $sec & 0xFFFFFFFF); // 5 bytes BE
    $randBytes = random_bytes(self::RAND_BYTES); // 12 bytes
    return $ts5 . $randBytes; // 17 bytes total
  }

  /**
   * Convert a 26-char pikaID string to 17-byte binary (v2).
   */
  public static function toBinary(string $id): string
  {
    if (!self::isValid($id)) {
      throw new InvalidArgumentException('Invalid pikaid format.');
    }
    $parsed  = self::parse($id); // ['timestamp'=>DateTimeImmutable, 'randomness'=>hex]
    $seconds = $parsed['timestamp']->getTimestamp();

    // 40-bit bound check (0 .. 2^40-1)
    if ($seconds < 0 || $seconds > 0xFFFFFFFFFF) {
      throw new InvalidArgumentException('Timestamp out of 40-bit range for binary encoding.');
    }

    $ts5 = chr(($seconds >> 32) & 0xFF) . pack('N', $seconds & 0xFFFFFFFF);

    $entropy = hex2bin($parsed['randomness']); // 12 bytes
    if ($entropy === false || strlen($entropy) !== self::RAND_BYTES) {
      throw new InvalidArgumentException('Randomness hex must represent exactly 12 bytes.');
    }

    return $ts5 . $entropy; // 17 bytes
  }

  /**
   * Convert a 17-byte binary pikaID (v2) to 26-char string.
   */
  public static function fromBinary(string $bin): string
  {
    if (strlen($bin) !== self::BINARY_LENGTH) {
      throw new InvalidArgumentException('Binary length must be 17 bytes.');
    }

    $hi = ord($bin[0]);
    $lo = unpack('N', substr($bin, 1, 4))[1];
    $seconds = ($hi << 32) | $lo;

    if ($seconds > 36 ** 7 - 1) {
      throw new InvalidArgumentException('Timestamp out of range for 7-char Base36 (36^7 - 1).');
    }

    // NEW: enforce 7-char Base36 ceiling
    if ($seconds > self::MAX_TS_BASE36) {
      throw new InvalidArgumentException('Timestamp out of range for 7-char Base36 (36^7 - 1).');
    }

    $entropy = substr($bin, 5, self::RAND_BYTES);
    $ts36 = str_pad(base_convert((string)$seconds, 10, 36), self::TS_LENGTH, '0', STR_PAD_LEFT);
    $r36  = str_pad(self::bytesToBase36($entropy), self::RAND_LEN36, '0', STR_PAD_LEFT);
    return $ts36 . $r36;
  }

  /**
   * Accept only lowercase base36, exactly 26 chars.
   */
  public static function isValid(string $id): bool
  {
    return (bool) preg_match('/^[0-9a-z]{26}$/', $id);
  }

  /**
   * Parse a pikaID string.
   *
   * @return array{
   *   timestamp: DateTimeImmutable,
   *   randomness: string  // lowercase hex, 24 chars (12 bytes)
   * }
   */
  public static function parse(string $id): array
  {
    if (!self::isValid($id)) {
      throw new InvalidArgumentException('Invalid pikaid format');
    }

    // We already validated lowercase; no normalization needed
    $tsPart   = substr($id, 0, self::TS_LENGTH);
    $randPart = substr($id, self::TS_LENGTH);

    $seconds = self::base36ToInt($tsPart);
    $date    = (new DateTimeImmutable('@' . $seconds))->setTimezone(self::utc());

    // Decode base36 randomness to raw 12 bytes, then return hex (24 chars)
    $raw = self::base36ToBytesRaw($randPart); // may be shorter; no padding
    if (strlen($raw) > self::RAND_BYTES) {
      throw new InvalidArgumentException('Randomness exceeds 96-bit budget');
    }
    $bytes = str_pad($raw, self::RAND_BYTES, "\0", STR_PAD_LEFT);
    $hex   = bin2hex($bytes); // lowercase, 24 chars

    return [
      'timestamp'  => $date,
      'randomness' => $hex,
    ];
  }

  /**
   * Generate a pikaID string using a provided DateTime timestamp (seconds precision).
   */
  public static function fromDateTime(DateTimeInterface $t): string
  {
    $seconds = $t->getTimestamp();
    // String format allows up to 36^7-1 seconds (fits in 40 bits).
    $ts36 = str_pad(base_convert((string) $seconds, 10, 36), self::TS_LENGTH, '0', STR_PAD_LEFT);

    $randBytes = random_bytes(self::RAND_BYTES);
    $rand36    = self::bytesToBase36($randBytes);
    $rand36    = str_pad($rand36, self::RAND_LEN36, '0', STR_PAD_LEFT);

    return $ts36 . $rand36;
  }

  /* --------------------------- Timestamp helpers -------------------------- */

  private static function base36ToInt(string $s): int
  {
    $n = 0;
    for ($i = 0, $L = strlen($s); $i < $L; $i++) {
      $n = $n * 36 + self::value($s[$i]);
    }
    return $n;
  }

  /* -------------- Randomness (12 bytes) <-> Base36 (<=19 chars) ---------- */

  private static function bytesToBase36(string $bin): string
  {
    self::initExtensions();

    if (self::$hasGmp) {
      $num = gmp_import($bin, 1, GMP_BIG_ENDIAN);
      return gmp_strval($num, 36);
    }

    if (self::$hasBcmath) {
      $dec = '0';
      $len = strlen($bin);
      for ($i = 0; $i < $len; $i++) {
        $dec = bcmul($dec, '256', 0);
        $dec = bcadd($dec, (string) ord($bin[$i]), 0);
      }
      if (bccomp($dec, '0', 0) === 0) return '0';

      $digits = [];
      while (bccomp($dec, '0', 0) === 1) {
        $mod = (int) bcmod($dec, '36');
        $digits[] = self::digit($mod);
        $dec = bcdiv($dec, '36', 0);
      }
      $digits = array_reverse($digits);
      return implode('', $digits);
    }

    // Pure-PHP: divide big-endian byte array by 36 repeatedly.
    $arr = array_values(unpack('C*', $bin));
    self::trimLeadingZerosBytes($arr);

    $digits = [];
    while (!self::bytesIsZero($arr)) {
      $rem = self::divModBytes($arr, 36);
      $digits[] = self::digit($rem);
      self::trimLeadingZerosBytes($arr);
    }
    if (!$digits) return '0';
    return implode('', array_reverse($digits));
  }

  private static function base36ToBytes(string $str): string
  {
    $bin = self::base36ToBytesRaw($str); // no padding
    return str_pad($bin, self::RAND_BYTES, "\0", STR_PAD_LEFT);
  }

  private static function base36ToBytesRaw(string $str): string
  {
    self::initExtensions();

    // Accept only lowercase here; caller ensures validation already.
    if (self::$hasGmp) {
      $num = gmp_init($str, 36);
      return gmp_export($num, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN); // may be ""
    }

    if (self::$hasBcmath) {
      $dec = '0';
      for ($i = 0, $L = strlen($str); $i < $L; $i++) {
        $dec = bcmul($dec, '36', 0);
        $dec = bcadd($dec, (string) self::value($str[$i]), 0);
      }
      $out = [];
      while (bccomp($dec, '0', 0) === 1) {
        $out[] = chr((int) bcmod($dec, '256'));
        $dec = bcdiv($dec, '256', 0);
      }
      if (!$out) return "";
      return implode('', array_reverse($out));
    }

    // Pure-PHP: big = big*36 + digit
    $big = [0]; // big-endian bytes
    for ($i = 0, $L = strlen($str); $i < $L; $i++) {
      self::mulAddBytes($big, 36, self::value($str[$i]));
    }
    return self::bytesToString($big);
  }

  /* -------------------- Byte-array big-int (pure PHP) -------------------- */

  private static function divModBytes(array &$bytes, int $divisor): int
  {
    $rem = 0;
    $n   = count($bytes);
    for ($i = 0; $i < $n; $i++) {
      $acc       = ($rem << 8) + $bytes[$i];
      $bytes[$i] = intdiv($acc, $divisor);
      $rem       = $acc % $divisor;
    }
    return $rem;
  }

  private static function mulAddBytes(array &$bytes, int $mul, int $add): void
  {
    $carry = $add;
    for ($i = count($bytes) - 1; $i >= 0; $i--) {
      $val       = $bytes[$i] * $mul + $carry;
      $bytes[$i] = $val & 0xFF;
      $carry     = intdiv($val, 256);
    }
    if ($carry > 0) {
      $head = [];
      while ($carry > 0) {
        $head[] = $carry & 0xFF;
        $carry  = intdiv($carry, 256);
      }
      $bytes = array_merge(array_reverse($head), $bytes);
    }
    self::trimLeadingZerosBytes($bytes);
  }

  private static function trimLeadingZerosBytes(array &$bytes): void
  {
    $i = 0;
    $n = count($bytes);
    while ($i < $n - 1 && $bytes[$i] === 0) $i++;
    if ($i > 0) $bytes = array_slice($bytes, $i);
  }

  private static function bytesIsZero(array $bytes): bool
  {
    foreach ($bytes as $b) if ($b !== 0) return false;
    return true;
  }

  private static function bytesToString(array $bytes): string
  {
    if (!$bytes) $bytes = [0];
    return pack('C*', ...$bytes);
  }

  /* ---------------------------- Small utils ----------------------------- */

  private static function utc(): DateTimeZone
  {
    return self::$utc ??= new DateTimeZone('UTC');
  }

  private static function initExtensions(): void
  {
    if (self::$hasGmp === null) {
      self::$hasGmp    = extension_loaded('gmp');
      self::$hasBcmath = extension_loaded('bcmath');
    }
  }

  private static function digit(int $v): string
  {
    return ($v < 10) ? chr(48 + $v) : chr(87 + $v); // 0-9 / a-z
  }

  private static function value(string $ch): int
  {
    if ($ch >= '0' && $ch <= '9') return ord($ch) - 48;
    if ($ch >= 'a' && $ch <= 'z') return ord($ch) - 87;
    // Uppercase not expected at call sites, but we can be defensive:
    if ($ch >= 'A' && $ch <= 'Z') return ord($ch) - 55;
    throw new InvalidArgumentException('Invalid Base36 digit');
  }
}
