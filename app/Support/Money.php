<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * Exact decimal arithmetic for money and quantities.
 *
 * All values cross the boundary as numeric strings, never floats.
 */
final class Money
{
    public const SCALE = 2;

    public const QTY_SCALE = 3;

    public const COST_SCALE = 4;

    public static function of(BigNumber|int|string|float|null $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }

        if (is_float($value)) {
            $value = number_format($value, 6, '.', '');
        }

        return BigDecimal::of($value);
    }

    /** Round to money scale and return as string. */
    public static function round(BigNumber|int|string|float|null $value, int $scale = self::SCALE): string
    {
        return (string) self::of($value)->toScale($scale, RoundingMode::HalfUp);
    }

    public static function qty(BigNumber|int|string|float|null $value): string
    {
        return self::round($value, self::QTY_SCALE);
    }

    public static function add(BigNumber|int|string|float|null ...$values): string
    {
        $sum = BigDecimal::zero();
        foreach ($values as $value) {
            $sum = $sum->plus(self::of($value));
        }

        return self::round($sum);
    }

    public static function sub(BigNumber|int|string|float|null $a, BigNumber|int|string|float|null $b): string
    {
        return self::round(self::of($a)->minus(self::of($b)));
    }

    public static function mul(BigNumber|int|string|float|null $a, BigNumber|int|string|float|null $b, int $scale = self::SCALE): string
    {
        return self::round(self::of($a)->multipliedBy(self::of($b)), $scale);
    }

    public static function div(BigNumber|int|string|float|null $a, BigNumber|int|string|float|null $b, int $scale = self::SCALE): string
    {
        return (string) self::of($a)->dividedBy(self::of($b), $scale, RoundingMode::HalfUp);
    }

    public static function cmp(BigNumber|int|string|float|null $a, BigNumber|int|string|float|null $b): int
    {
        return self::of($a)->compareTo(self::of($b));
    }

    public static function gt($a, $b): bool
    {
        return self::cmp($a, $b) > 0;
    }

    public static function gte($a, $b): bool
    {
        return self::cmp($a, $b) >= 0;
    }

    public static function lt($a, $b): bool
    {
        return self::cmp($a, $b) < 0;
    }

    public static function lte($a, $b): bool
    {
        return self::cmp($a, $b) <= 0;
    }

    public static function eq($a, $b): bool
    {
        return self::cmp($a, $b) === 0;
    }

    public static function isZero($a): bool
    {
        return self::of($a)->isZero();
    }

    public static function isPositive($a): bool
    {
        return self::of($a)->isPositive();
    }

    public static function isNegative($a): bool
    {
        return self::of($a)->isNegative();
    }

    public static function min($a, $b): string
    {
        return self::lte($a, $b) ? self::round($a) : self::round($b);
    }

    public static function max($a, $b): string
    {
        return self::gte($a, $b) ? self::round($a) : self::round($b);
    }

    public static function neg($a): string
    {
        return self::round(self::of($a)->negated());
    }

    /** Human formatting using store currency settings. */
    public static function format(BigNumber|int|string|float|null $value, bool $withCurrency = true): string
    {
        $decimals = (int) settings('currency.decimals', 0);
        $formatted = number_format((float) (string) self::of($value)->toScale($decimals, RoundingMode::HalfUp), $decimals, '.', ',');

        if (! $withCurrency) {
            return $formatted;
        }

        $symbol = (string) settings('currency.symbol', '');

        return trim($formatted.' '.$symbol);
    }

    public static function formatQty(BigNumber|int|string|float|null $value): string
    {
        $v = self::of($value)->toScale(self::QTY_SCALE, RoundingMode::HalfUp);
        $s = rtrim(rtrim((string) $v, '0'), '.');

        return $s === '' || $s === '-' ? '0' : $s;
    }
}
