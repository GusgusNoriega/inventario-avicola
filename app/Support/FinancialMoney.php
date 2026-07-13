<?php

namespace App\Support;

use InvalidArgumentException;

final class FinancialMoney
{
    public const SCALE = 2;

    public static function normalize(mixed $value): string
    {
        if (is_int($value)) {
            return number_format($value, self::SCALE, '.', '');
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('El importe no es valido.');
            }

            return number_format($value, self::SCALE, '.', '');
        }

        $value = trim((string) $value);
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException('El importe no es valido.');
        }

        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '-');
        [$integer, $decimals] = array_pad(explode('.', $unsigned, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $decimals = str_pad($decimals, self::SCALE, '0');
        $normalized = $integer.'.'.$decimals;

        return $negative && bccomp($normalized, '0.00', self::SCALE) !== 0
            ? '-'.$normalized
            : $normalized;
    }

    public static function add(string $left, string $right): string
    {
        return bcadd(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function subtract(string $left, string $right): string
    {
        return bcsub(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function compare(string $left, string $right): int
    {
        return bccomp(self::normalize($left), self::normalize($right), self::SCALE);
    }
}
