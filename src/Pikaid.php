<?php

declare(strict_types=1);

namespace Pikaid;

/**
 * Pikaid - Spec-compliant 26-character lowercase Base36 identifiers.
 *
 * Layout: [7 chars timestamp][19 chars randomness]
 * Binary: [5 bytes timestamp BE][12 bytes entropy] = 17 bytes total
 *
 * INVARIANTS:
 * - String form is exactly 26 lowercase base36 characters [0-9a-z]
 * - Binary form is exactly 17 bytes
 * - Timestamp is 0 <= ts <= 36^7-1 (fits in 5 bytes big-endian)
 * - Randomness is exactly 12 bytes (96 bits), encoded as 19 base36 chars
 * - Lexicographic order follows timestamps across different seconds
 * - String <-> Binary conversion is perfectly reversible
 *
 * @see https://github.com/pikaid/pikaid-specs for the canonical specification
 */
final class Pikaid
{
    // =========================================================================
    // Public Constants
    // =========================================================================

    /** Total length of a pikaid string */
    public const LENGTH = 26;

    /** Total length of binary representation */
    public const BINARY_LENGTH = 17;

    /** Maximum valid timestamp value (36^7 - 1), covers until ~year 4453 */
    public const MAX_TIMESTAMP = 78364164095;

    // =========================================================================
    // Internal Layout Constants
    // =========================================================================

    private const TS_CHARS = 7;       // timestamp portion in string form
    private const TS_BYTES = 5;       // timestamp portion in binary form
    private const ENTROPY_CHARS = 19; // randomness portion in string form
    private const ENTROPY_BYTES = 12; // randomness portion in binary form

    /** Base36 alphabet - lowercase only per spec, no uppercase tolerance */
    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';

    /**
     * ASCII ordinal -> digit value lookup.
     * Valid chars: '0'-'9' (48-57) map to 0-9, 'a'-'z' (97-122) map to 10-35.
     * Anything else is absent = invalid character.
     */
    private const CHAR_VALUES = [
        48 => 0, 49 => 1, 50 => 2, 51 => 3, 52 => 4,
        53 => 5, 54 => 6, 55 => 7, 56 => 8, 57 => 9,
        97 => 10, 98 => 11, 99 => 12, 100 => 13, 101 => 14,
        102 => 15, 103 => 16, 104 => 17, 105 => 18, 106 => 19,
        107 => 20, 108 => 21, 109 => 22, 110 => 23, 111 => 24,
        112 => 25, 113 => 26, 114 => 27, 115 => 28, 116 => 29,
        117 => 30, 118 => 31, 119 => 32, 120 => 33, 121 => 34,
        122 => 35,
    ];

    /**
     * Pre-computed zero-padding strings to avoid str_repeat() in hot paths.
     * Index = number of zeros needed. Covers 0-19 (max needed for entropy).
     */
    private const PADDING = [
        '', '0', '00', '000', '0000', '00000', '000000', '0000000',
        '00000000', '000000000', '0000000000', '00000000000', '000000000000',
        '0000000000000', '00000000000000', '000000000000000', '0000000000000000',
        '00000000000000000', '000000000000000000', '0000000000000000000',
    ];

    /** Cached extension availability - null means not checked yet */
    /** @var bool|null */
    private static $hasGmp = null;
    /** @var bool|null */
    private static $hasBcmath = null;

    // =========================================================================
    // MUST APIs - Core spec-required functionality
    // =========================================================================

    /**
     * Generate a new pikaid using current system time.
     *
     * Uses random_bytes() for entropy (OS CSPRNG: CryptGenRandom/getrandom/etc).
     * Validates timestamp at generation time to catch clock issues early.
     *
     * @throws PikaidException If system clock is outside valid range (clock skew protection)
     */
    public static function generate(): string
    {
        return self::generateWithTimestamp(\time());
    }

    /**
     * Generate a pikaid with a specific timestamp.
     *
     * Useful for testing, migrations, or when you need deterministic timestamps.
     * Entropy is still cryptographically generated.
     *
     * @param int $timestamp Unix timestamp (seconds since epoch)
     * @throws PikaidException If timestamp is outside valid range
     */
    public static function generateWithTimestamp(int $timestamp): string
    {
        self::assertTimestampValid($timestamp);

        $ts = self::encodeTimestampPart($timestamp);
        $entropy = self::bytesToBase36(\random_bytes(self::ENTROPY_BYTES));

        return self::PADDING[self::TS_CHARS - \strlen($ts)] . $ts
             . self::PADDING[self::ENTROPY_CHARS - \strlen($entropy)] . $entropy;
    }

    /**
     * Generate a pikaid directly as 17-byte binary.
     *
     * Use this when storing in BINARY(17) columns - saves base36 encoding overhead.
     * Binary form preserves lexicographic = chronological ordering.
     *
     * @throws PikaidException If system clock is outside valid range
     */
    public static function generateBinary(): string
    {
        return self::generateBinaryWithTimestamp(\time());
    }

    /**
     * Generate binary pikaid with a specific timestamp.
     *
     * @param int $timestamp Unix timestamp (seconds since epoch)
     * @throws PikaidException If timestamp is outside valid range
     */
    public static function generateBinaryWithTimestamp(int $timestamp): string
    {
        self::assertTimestampValid($timestamp);

        // Pack 5-byte big-endian timestamp: high byte first, then low 4 bytes
        return \chr(($timestamp >> 32) & 0xFF)
             . \pack('N', $timestamp & 0xFFFFFFFF)
             . \random_bytes(self::ENTROPY_BYTES);
    }

    /**
     * Central validation function - the single source of truth.
     *
     * Every public method that accepts a pikaid string MUST call this.
     * Returns parsed components on success, throws on any spec violation.
     *
     * @param string $id The pikaid string to validate
     * @return array{timestamp: int, entropy: string} Parsed timestamp (int) and raw entropy bytes
     * @throws PikaidException On any validation failure
     */
    public static function validateOrThrow(string $id): array
    {
        // Check length first - fast fail
        if (\strlen($id) !== self::LENGTH) {
            throw PikaidException::invalidLength(\strlen($id), self::LENGTH);
        }

        // Validate each character is in [0-9a-z]
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $ord = \ord($id[$i]);
            if (!isset(self::CHAR_VALUES[$ord])) {
                throw PikaidException::invalidCharacter($id[$i], $i);
            }
        }

        // Decode and validate timestamp
        $timestamp = self::base36ToInt($id, 0, self::TS_CHARS);
        // Seven Base36 digits cannot exceed 36^7 - 1 (MAX_TIMESTAMP).
        // Decode entropy - this validates the base36 value fits in 12 bytes
        $entropy = self::base36ToBytes(\substr($id, self::TS_CHARS), self::ENTROPY_BYTES);

        return [
            'timestamp' => $timestamp,
            'entropy' => $entropy,
        ];
    }

    /**
     * Check if a string is a fully spec-compliant pikaid.
     *
     * This is not just a format check - it validates the timestamp range too.
     * Returns false for anything that would be rejected by validateOrThrow().
     */
    public static function isValid(string $id): bool
    {
        try {
            self::validateOrThrow($id);
            return true;
        } catch (PikaidException $e) {
            return false;
        }
    }

    /**
     * Check if a string has valid pikaid format (lighter check).
     *
     * Only validates: length = 26, all characters in [0-9a-z].
     * Does NOT validate randomness overflow - use isValid() for full spec compliance.
     *
     * Use this when you need fast format validation and will do full validation later,
     * or when dealing with strings that might be pikaids but you're not sure.
     */
    public static function isWellFormed(string $id): bool
    {
        if (\strlen($id) !== self::LENGTH) {
            return false;
        }

        for ($i = 0; $i < self::LENGTH; ++$i) {
            $ord = \ord($id[$i]);
            if (!isset(self::CHAR_VALUES[$ord])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convert a pikaid string to 17-byte binary form.
     *
     * Binary form maintains lexicographic = chronological ordering.
     * Useful for database storage in BINARY(17) columns.
     *
     * @throws PikaidException If the input is not a valid pikaid
     */
    public static function toBinary(string $id): string
    {
        $parts = self::validateOrThrow($id);

        // Pack 5-byte big-endian timestamp + 12 bytes entropy
        return \chr(($parts['timestamp'] >> 32) & 0xFF)
             . \pack('N', $parts['timestamp'] & 0xFFFFFFFF)
             . $parts['entropy'];
    }

    /**
     * Convert 17-byte binary back to the 26-character string form.
     *
     * @throws PikaidException If binary input is malformed
     */
    public static function fromBinary(string $binary): string
    {
        // Strict binary validation
        if (\strlen($binary) !== self::BINARY_LENGTH) {
            throw PikaidException::invalidBinaryLength(\strlen($binary), self::BINARY_LENGTH);
        }

        // Extract 5-byte big-endian timestamp
        $timestamp = (\ord($binary[0]) << 32) | \unpack('N', $binary, 1)[1];

        if ($timestamp > self::MAX_TIMESTAMP) {
            throw PikaidException::timestampOverflow($timestamp, self::MAX_TIMESTAMP);
        }

        // Encode back to base36 string
        $ts = self::encodeTimestampPart($timestamp);
        $entropy = self::bytesToBase36(\substr($binary, self::TS_BYTES, self::ENTROPY_BYTES));

        return self::PADDING[self::TS_CHARS - \strlen($ts)] . $ts
             . self::PADDING[self::ENTROPY_CHARS - \strlen($entropy)] . $entropy;
    }

    // =========================================================================
    // Low-level, allocation-light APIs
    // =========================================================================

    /**
     * Decode a pikaid into its primitive components.
     *
     * Returns the timestamp as an integer and entropy as raw bytes.
     * This is the most allocation-light way to extract pikaid data.
     *
     * @return array{0: int, 1: string} [timestamp, entropy_bytes]
     * @throws PikaidException If the input is not a valid pikaid
     */
    public static function decode(string $id): array
    {
        $parts = self::validateOrThrow($id);
        return [$parts['timestamp'], $parts['entropy']];
    }

    /**
     * Extract the timestamp from a pikaid as a Unix timestamp.
     *
     * This is more efficient than parse() when you only need the timestamp
     * and don't want DateTime object allocation overhead.
     *
     * @throws PikaidException If the input is not a valid pikaid
     */
    public static function getTimestamp(string $id): int
    {
        // We still need to validate the whole thing to ensure spec compliance
        return self::validateOrThrow($id)['timestamp'];
    }

    /**
     * Extract the entropy portion as raw bytes.
     *
     * Returns exactly 12 bytes of the random portion.
     *
     * @throws PikaidException If the input is not a valid pikaid
     */
    public static function getEntropyBytes(string $id): string
    {
        return self::validateOrThrow($id)['entropy'];
    }

    /**
     * Extract the entropy portion as a hexadecimal string.
     *
     * Returns 24-character hex string (12 bytes * 2 hex chars per byte).
     *
     * @throws PikaidException If the input is not a valid pikaid
     */
    public static function getEntropyHex(string $id): string
    {
        return \bin2hex(self::validateOrThrow($id)['entropy']);
    }

    // =========================================================================
    // Convenience APIs (not on hot paths, so DateTime allocation is fine)
    // =========================================================================

    /**
     * Parse a pikaid into human-readable components.
     *
     * Returns the timestamp in UTC and randomness as lowercase hexadecimal.
     * The entropy_hex key is retained for backward compatibility.
     * Use decode() for primitive integer and binary values.
     *
     * @return array{timestamp: \DateTimeImmutable, randomness: string, entropy_hex: string}
     * @throws PikaidException If the input is not a valid pikaid
     */
    public static function parse(string $id): array
    {
        $parts = self::validateOrThrow($id);

        $randomness = \bin2hex($parts['entropy']);

        return [
            'timestamp' => (new \DateTimeImmutable('@' . $parts['timestamp']))
                ->setTimezone(new \DateTimeZone('UTC')),
            'randomness' => $randomness,
            'entropy_hex' => $randomness, // Backward-compatible alias.
        ];
    }

    /**
     * Generate a pikaid from a DateTimeInterface.
     *
     * Convenience wrapper around generateWithTimestamp() for when you have a DateTime.
     *
     * @throws PikaidException If timestamp is outside valid range
     */
    public static function fromDateTime(\DateTimeInterface $dateTime): string
    {
        return self::generateWithTimestamp($dateTime->getTimestamp());
    }

    /**
     * Compare two valid pikaids by their canonical bytewise string order.
     *
     * Values with different timestamps sort chronologically. Within the same
     * second, the random suffix determines order (not generation sequence).
     * Returns -1, 0, or 1 and avoids PHP numeric-string comparison rules.
     *
     * @throws PikaidException If either input is not a valid pikaid
     */
    public static function compare(string $a, string $b): int
    {
        // Validate both to ensure we're comparing valid pikaids
        self::validateOrThrow($a);
        self::validateOrThrow($b);

        return \strcmp($a, $b) <=> 0;
    }

    // =========================================================================
    // Internal: Timestamp validation and encoding
    // =========================================================================

    /**
     * Validate a timestamp is within the allowed range.
     *
     * @throws PikaidException If timestamp is negative or exceeds max
     */
    private static function assertTimestampValid(int $timestamp): void
    {
        if ($timestamp < 0) {
            throw PikaidException::timestampNegative($timestamp);
        }

        if ($timestamp > self::MAX_TIMESTAMP) {
            throw PikaidException::timestampOverflow($timestamp, self::MAX_TIMESTAMP);
        }
    }

    /**
     * Encode a timestamp to base36.
     *
     * Timestamp fits in native PHP int, so base_convert is safe here.
     */
    private static function encodeTimestampPart(int $timestamp): string
    {
        return \base_convert((string) $timestamp, 10, 36);
    }

    /**
     * Decode base36 substring to integer (inline for timestamp).
     *
     * Works directly on the string with offset/length to avoid substr() allocation.
     * Only safe for values that fit in PHP int - timestamps are fine.
     */
    private static function base36ToInt(string $str, int $offset, int $length): int
    {
        $result = 0;
        $end = $offset + $length;

        for ($i = $offset; $i < $end; ++$i) {
            $result = $result * 36 + self::CHAR_VALUES[\ord($str[$i])];
        }

        return $result;
    }

    // =========================================================================
    // Internal: Big integer conversions (bytes <-> base36)
    //
    // The entropy portion is 96 bits - doesn't fit in native PHP int (64-bit).
    // We need arbitrary precision math. Strategy:
    //   1. GMP if available (fastest, native bigint)
    //   2. BCMath if available (slower but still good)
    //   3. Pure PHP byte array math (works everywhere)
    //
    // All three paths MUST produce identical output - this is tested.
    // =========================================================================

    /**
     * Convert raw bytes to base36 string.
     *
     * Automatically selects the fastest available math library.
     */
    private static function bytesToBase36(string $bytes): string
    {
        self::detectExtensions();

        if (self::$hasGmp) {
            // GMP is significantly faster than BCMath/Pure for this
            return \gmp_strval(\gmp_import($bytes, 1, \GMP_BIG_ENDIAN), 36);
        }

        if (self::$hasBcmath) {
            return self::bytesToBase36Bcmath($bytes);
        }

        return self::bytesToBase36Pure($bytes);
    }

    /**
     * Convert base36 string to raw bytes with exact output length.
     *
     * Output is left-padded with zero bytes if the value is smaller than expected.
     * Throws if the value doesn't fit in the target byte length.
     *
     * @throws PikaidException If value exceeds target byte size
     */
    private static function base36ToBytes(string $str, int $targetLength): string
    {
        self::detectExtensions();

        if (self::$hasGmp) {
            $raw = \gmp_export(\gmp_init($str, 36), 1, \GMP_BIG_ENDIAN);
            $rawLen = \strlen($raw);

            if ($rawLen > $targetLength) {
                throw PikaidException::entropyOverflow($rawLen, $targetLength);
            }

            return $rawLen < $targetLength
                ? \str_repeat("\0", $targetLength - $rawLen) . $raw
                : $raw;
        }

        if (self::$hasBcmath) {
            return self::base36ToBytesBcmath($str, $targetLength);
        }

        return self::base36ToBytesPure($str, $targetLength);
    }

    /**
     * One-time extension availability check.
     */
    private static function detectExtensions(): void
    {
        if (self::$hasGmp === null) {
            self::$hasGmp = \function_exists('gmp_import');
            self::$hasBcmath = \function_exists('bcadd');
        }
    }

    // =========================================================================
    // BCMath implementation
    //
    // BCMath does arbitrary precision using decimal strings. Slower than GMP
    // but commonly available and faster than pure PHP.
    // =========================================================================

    /**
     * Bytes to base36 using BCMath.
     */
    private static function bytesToBase36Bcmath(string $bytes): string
    {
        $length = \strlen($bytes);
        $decimal = '0';

        // Build decimal: for each byte, decimal = decimal * 256 + byte_value
        for ($i = 0; $i < $length; ++$i) {
            $decimal = \bcadd(\bcmul($decimal, '256', 0), (string) \ord($bytes[$i]), 0);
        }

        if ($decimal === '0') {
            return '0';
        }

        // Convert decimal to base36 by repeated division
        $result = '';
        while (\bccomp($decimal, '0', 0) > 0) {
            $remainder = (int) \bcmod($decimal, '36', 0);
            $result = self::ALPHABET[$remainder] . $result;
            $decimal = \bcdiv($decimal, '36', 0); // 0 = truncate, no decimals
        }

        return $result;
    }

    /**
     * Base36 to bytes using BCMath.
     *
     * @throws PikaidException If value doesn't fit in target length
     */
    private static function base36ToBytesBcmath(string $str, int $targetLength): string
    {
        $strLen = \strlen($str);
        $decimal = '0';

        // Build decimal from base36 digits
        for ($i = 0; $i < $strLen; ++$i) {
            $decimal = \bcadd(\bcmul($decimal, '36', 0), (string) self::CHAR_VALUES[\ord($str[$i])], 0);
        }

        if ($decimal === '0') {
            return \str_repeat("\0", $targetLength);
        }

        // Convert decimal to bytes
        $result = '';
        while (\bccomp($decimal, '0', 0) > 0) {
            $result = \chr((int) \bcmod($decimal, '256', 0)) . $result;
            $decimal = \bcdiv($decimal, '256', 0);
        }

        $resultLen = \strlen($result);

        if ($resultLen > $targetLength) {
            throw PikaidException::entropyOverflow($resultLen, $targetLength);
        }

        return $resultLen < $targetLength
            ? \str_repeat("\0", $targetLength - $resultLen) . $result
            : $result;
    }

    // =========================================================================
    // Pure PHP implementation
    //
    // No extensions needed. Uses byte arrays for arbitrary precision.
    // Slower than GMP/BCMath but works on any PHP installation.
    // =========================================================================

    /**
     * Bytes to base36 using pure PHP.
     *
     * Standard base conversion: long division from base-256 to base-36.
     */
    private static function bytesToBase36Pure(string $bytes): string
    {
        $length = \strlen($bytes);

        // Skip leading zero bytes (they don't change the numeric value)
        $start = 0;
        while ($start < $length - 1 && $bytes[$start] === "\0") {
            ++$start;
        }

        // All zeros case
        if ($start === $length - 1 && $bytes[$start] === "\0") {
            return '0';
        }

        // Copy significant bytes into working array
        $workLen = $length - $start;
        $work = [];
        for ($i = $start; $i < $length; ++$i) {
            $work[] = \ord($bytes[$i]);
        }

        // Repeated division by 36, collecting remainders as base36 digits
        $result = '';

        while ($workLen > 0) {
            $remainder = 0;
            $writeIdx = 0;

            // Process each byte: combine with remainder, divide by 36
            for ($i = 0; $i < $workLen; ++$i) {
                $value = ($remainder << 8) + $work[$i];
                $quotient = (int) ($value / 36);
                $remainder = $value - $quotient * 36; // faster than modulo

                // Keep non-zero quotient digits (skip leading zeros)
                if ($writeIdx > 0 || $quotient > 0) {
                    $work[$writeIdx++] = $quotient;
                }
            }

            $result = self::ALPHABET[$remainder] . $result;
            $workLen = $writeIdx;
        }

        return $result === '' ? '0' : $result;
    }

    /**
     * Base36 to bytes using pure PHP.
     *
     * Standard base conversion: multiply-and-add from base-36 to base-256.
     *
     * @throws PikaidException If value doesn't fit in target length
     */
    private static function base36ToBytesPure(string $str, int $targetLength): string
    {
        $strLen = \strlen($str);

        // Work with fixed-size array, filled right-to-left
        // This avoids array_unshift which is O(n) per call
        $bytes = \array_fill(0, $targetLength, 0);
        $usedBytes = 1; // Track active bytes from right side

        for ($i = 0; $i < $strLen; ++$i) {
            $carry = self::CHAR_VALUES[\ord($str[$i])];

            // Multiply current value by 36 and add digit
            // Process right-to-left (least significant byte first)
            for ($j = $targetLength - 1; $j >= $targetLength - $usedBytes; --$j) {
                $value = $bytes[$j] * 36 + $carry;
                $bytes[$j] = $value & 0xFF;
                $carry = $value >> 8;
            }

            // Propagate remaining carry into higher bytes
            while ($carry > 0 && $j >= 0) {
                $value = $bytes[$j] * 36 + $carry;
                $bytes[$j] = $value & 0xFF;
                $carry = $value >> 8;
                --$j;
                ++$usedBytes;
            }

            // If there's still carry, the value doesn't fit
            if ($carry > 0) {
                throw PikaidException::entropyOverflow($targetLength + 1, $targetLength);
            }
        }

        return \pack('C*', ...$bytes);
    }
}
