<?php

declare(strict_types=1);

namespace Pikaid\Tests;

/**
 * Extension-free reference oracle used to re-derive pikaid values.
 *
 * The implementation deliberately shares no code with src/Pikaid.php: it uses
 * decimal-string arithmetic only. That makes it a genuinely independent check
 * of the base36 and binary encodings, including leading zeros.
 */
final class IndependentOracle
{
    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';

    /**
     * Encode a timestamp and exactly 12 entropy bytes into the 26-character form.
     */
    public static function encode(int $timestamp, string $entropy): string
    {
        if (\strlen($entropy) !== 12) {
            throw new \InvalidArgumentException('Entropy must be exactly 12 bytes.');
        }

        $timestampPart = \str_pad(self::decimalToBase36((string) $timestamp), 7, '0', \STR_PAD_LEFT);
        $entropyPart = \str_pad(self::decimalToBase36(self::bytesToDecimal($entropy)), 19, '0', \STR_PAD_LEFT);

        return $timestampPart . $entropyPart;
    }

    /**
     * Encode a timestamp and exactly 12 entropy bytes into the 17-byte form.
     */
    public static function binary(int $timestamp, string $entropy): string
    {
        if (\strlen($entropy) !== 12) {
            throw new \InvalidArgumentException('Entropy must be exactly 12 bytes.');
        }

        return \chr(($timestamp >> 32) & 0xFF)
            . \pack('N', $timestamp & 0xFFFFFFFF)
            . $entropy;
    }

    /**
     * Convert big-endian bytes to a decimal string.
     */
    public static function bytesToDecimal(string $bytes): string
    {
        $decimal = '0';
        $length = \strlen($bytes);

        for ($i = 0; $i < $length; ++$i) {
            $decimal = self::decimalMultiplyAdd($decimal, 256, \ord($bytes[$i]));
        }

        return $decimal;
    }

    /**
     * Convert a non-negative decimal string to lowercase base36.
     */
    public static function decimalToBase36(string $decimal): string
    {
        $decimal = \ltrim($decimal, '0');
        if ($decimal === '') {
            return '0';
        }

        $result = '';

        while ($decimal !== '0') {
            $quotient = '';
            $remainder = 0;
            $length = \strlen($decimal);

            for ($i = 0; $i < $length; ++$i) {
                $value = $remainder * 10 + (int) $decimal[$i];
                $digit = \intdiv($value, 36);
                $remainder = $value % 36;

                if ($quotient !== '' || $digit !== 0) {
                    $quotient .= (string) $digit;
                }
            }

            $result = self::ALPHABET[$remainder] . $result;
            $decimal = $quotient === '' ? '0' : $quotient;
        }

        return $result;
    }

    /**
     * decimal = decimal * multiplier + addend, without native big integers.
     */
    private static function decimalMultiplyAdd(string $decimal, int $multiplier, int $addend): string
    {
        $carry = $addend;
        $result = '';

        for ($i = \strlen($decimal) - 1; $i >= 0; --$i) {
            $value = (int) $decimal[$i] * $multiplier + $carry;
            $result = (string) ($value % 10) . $result;
            $carry = \intdiv($value, 10);
        }

        while ($carry > 0) {
            $result = (string) ($carry % 10) . $result;
            $carry = \intdiv($carry, 10);
        }

        $result = \ltrim($result, '0');

        return $result === '' ? '0' : $result;
    }
}
