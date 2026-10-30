<?php

declare(strict_types=1);

namespace Pikaid;

/**
 * Exception for pikaid validation and generation errors.
 *
 * Uses static factory methods for consistent, descriptive error messages.
 * All pikaid operations that fail throw this exception type, making
 * error handling predictable.
 */
class PikaidException extends \InvalidArgumentException
{
    // Error codes for programmatic handling if needed
    public const CODE_INVALID_LENGTH = 1;
    public const CODE_INVALID_CHARACTER = 2;
    public const CODE_TIMESTAMP_OVERFLOW = 3;
    public const CODE_TIMESTAMP_NEGATIVE = 4;
    public const CODE_ENTROPY_OVERFLOW = 5;
    public const CODE_INVALID_BINARY_LENGTH = 6;
    public const CODE_INVALID_BINARY_TYPE = 7;

    /**
     * String length doesn't match expected 26 characters.
     */
    public static function invalidLength(int $actual, int $expected): self
    {
        return new self(
            \sprintf(
                'Pikaid must be exactly %d characters, got %d.',
                $expected,
                $actual
            ),
            self::CODE_INVALID_LENGTH
        );
    }

    /**
     * String contains a character not in [0-9a-z].
     */
    public static function invalidCharacter(string $char, int $position): self
    {
        // Show the character in a readable way
        $ord = \ord($char);
        $display = ($ord >= 32 && $ord <= 126) ? "'{$char}'" : "0x" . \dechex($ord);

        return new self(
            \sprintf(
                'Invalid character %s at position %d. Only lowercase base36 [0-9a-z] allowed.',
                $display,
                $position
            ),
            self::CODE_INVALID_CHARACTER
        );
    }

    /**
     * Timestamp exceeds the maximum allowed value (36^7 - 1).
     */
    public static function timestampOverflow(int $timestamp, int $max): self
    {
        return new self(
            \sprintf(
                'Timestamp %d exceeds maximum allowed value %d (36^7-1, ~year 4453).',
                $timestamp,
                $max
            ),
            self::CODE_TIMESTAMP_OVERFLOW
        );
    }

    /**
     * Timestamp is negative (before Unix epoch).
     */
    public static function timestampNegative(int $timestamp): self
    {
        return new self(
            \sprintf(
                'Timestamp %d is negative. Pikaid only supports timestamps >= 0.',
                $timestamp
            ),
            self::CODE_TIMESTAMP_NEGATIVE
        );
    }

    /**
     * Entropy value is too large for target byte length.
     *
     * This happens if someone crafts a base36 string with a value
     * that doesn't fit in 12 bytes (> 2^96 - 1).
     */
    public static function entropyOverflow(int $actualBytes, int $maxBytes): self
    {
        return new self(
            \sprintf(
                'Entropy value requires %d bytes but maximum is %d bytes.',
                $actualBytes,
                $maxBytes
            ),
            self::CODE_ENTROPY_OVERFLOW
        );
    }

    /**
     * Binary input has wrong length.
     */
    public static function invalidBinaryLength(int $actual, int $expected): self
    {
        return new self(
            \sprintf(
                'Binary pikaid must be exactly %d bytes, got %d.',
                $expected,
                $actual
            ),
            self::CODE_INVALID_BINARY_LENGTH
        );
    }

    /**
     * Binary input is not a string type.
     *
     * PHP allows passing non-strings to string-typed parameters in some cases,
     * and strlen() on non-strings can have weird behavior. Belt and suspenders.
     */
    public static function invalidBinaryType(string $actualType): self
    {
        return new self(
            \sprintf(
                'Binary pikaid must be a string, got %s.',
                $actualType
            ),
            self::CODE_INVALID_BINARY_TYPE
        );
    }
}
