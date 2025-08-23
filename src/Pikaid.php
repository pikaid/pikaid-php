<?php

declare(strict_types=1);

namespace Pikaid;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Pikaid: 26-char, lowercase Base36 identifier.
 * Layout: [7 chars timestamp][19 chars randomness]
 * Fallback order for base36<->bytes: GMP -> BCMath -> pure PHP.
 */
final class Pikaid
{
  public const LENGTH      = 26;
  private const TS_LENGTH  = 7;
  private const RAND_BYTES = 12;
  private const RAND_LEN36 = 19;

  private static ?bool $hasGmp     = null;
  private static ?bool $hasBcmath  = null;
  private static ?DateTimeZone $utc = null;

  /* ------------------------------ Public API ------------------------------ */

  public static function generate(): string
  {
    // Timestamp (fast C-path): base_convert + str_pad
    $ts36 = str_pad(base_convert((string) time(), 10, 36), self::TS_LENGTH, '0', STR_PAD_LEFT);

    // Randomness
    $randBytes = random_bytes(self::RAND_BYTES);
    $rand36    = self::bytesToBase36($randBytes);
    $rand36    = str_pad($rand36, self::RAND_LEN36, '0', STR_PAD_LEFT);

    return $ts36 . $rand36;
  }

  public static function isValid(string $id): bool
  {
    return strlen($id) === self::LENGTH
      && strspn($id, '0123456789abcdefghijklmnopqrstuvwxyz') === self::LENGTH;
  }

  /**
   * @return array{timestamp: DateTimeImmutable, randomness: string}
   */
  public static function parse(string $id): array
  {
    if (!self::isValid($id)) {
      throw new InvalidArgumentException('Invalid pikaid format');
    }

    $tsPart   = substr($id, 0, self::TS_LENGTH);
    $randPart = substr($id, self::TS_LENGTH);

    $seconds = self::base36ToInt($tsPart);
    $date    = (new DateTimeImmutable('@' . $seconds))->setTimezone(self::utc());

    // Strict 96-bit bound
    $raw = self::base36ToBytesRaw($randPart); // no padding
    if (strlen($raw) > self::RAND_BYTES) {
      throw new InvalidArgumentException('Randomness exceeds 96-bit budget');
    }
    $bytes = str_pad($raw, self::RAND_BYTES, "\0", STR_PAD_LEFT);

    return [
      'timestamp'  => $date,
      'randomness' => bin2hex($bytes),
    ];
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

    // (Micro) skipping ltrim of zeros is fine; GMP handles it.
    if (self::$hasGmp) {
      $num = gmp_import($bin, 1, GMP_BIG_ENDIAN);
      return gmp_strval($num, 36);
    }

    if (self::$hasBcmath) {
      // Decimal accumulator: dec = dec*256 + byte
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
    $bin = self::base36ToBytesRaw($str);               // no padding
    return str_pad($bin, self::RAND_BYTES, "\0", STR_PAD_LEFT);
  }

  private static function base36ToBytesRaw(string $str): string
  {
    self::initExtensions();

    if (self::$hasGmp) {
      $num = gmp_init($str, 36);
      return gmp_export($num, 1, GMP_BIG_ENDIAN); // may be ""
    }

    if (self::$hasBcmath) {
      // Base36 -> decimal
      $dec = '0';
      for ($i = 0, $L = strlen($str); $i < $L; $i++) {
        $dec = bcmul($dec, '36', 0);
        $dec = bcadd($dec, (string) self::value($str[$i]), 0);
      }
      // Decimal -> bytes via /256 (no fixed length)
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
      // Merge carry at the front in one shot
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
    if ($ch >= 'A' && $ch <= 'Z') return ord($ch) - 55;
    throw new InvalidArgumentException('Invalid Base36 digit');
  }
}