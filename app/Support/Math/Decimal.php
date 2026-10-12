<?php

declare(strict_types=1);

namespace App\Support\Math;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode as BrickRounding;
use App\Support\Enums\RoundingMode;

/**
 * Exact decimal arithmetic for quantities, costs and money (Chapter 05 §5.5).
 * PHP floats are never used for business numbers. Values travel as numeric strings.
 */
final class Decimal
{
    public const QTY_SCALE = 6;

    public const COST_SCALE = 8;

    public const MONEY_SCALE = 4;

    public static function of(string|int|BigDecimal|null $v): BigDecimal
    {
        if ($v === null || $v === '') {
            return BigDecimal::zero();
        }

        return BigDecimal::of($v);
    }

    public static function add(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b, int $scale = self::QTY_SCALE): string
    {
        return (string) self::of($a)->plus(self::of($b))->toScale($scale, BrickRounding::Unnecessary);
    }

    public static function sub(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b, int $scale = self::QTY_SCALE): string
    {
        return (string) self::of($a)->minus(self::of($b))->toScale($scale, BrickRounding::Unnecessary);
    }

    public static function mul(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b, int $scale, RoundingMode $mode = RoundingMode::HALF_EVEN): string
    {
        return (string) self::of($a)->multipliedBy(self::of($b))->toScale($scale, self::brick($mode));
    }

    public static function div(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b, int $scale, RoundingMode $mode = RoundingMode::HALF_EVEN): string
    {
        $divisor = self::of($b);
        if ($divisor->isZero()) {
            return (string) BigDecimal::zero()->toScale($scale);
        }

        return (string) self::of($a)->dividedBy($divisor, $scale, self::brick($mode));
    }

    public static function round(string|int|BigDecimal|null $a, int $scale, RoundingMode $mode = RoundingMode::HALF_EVEN): string
    {
        return (string) self::of($a)->toScale($scale, self::brick($mode));
    }

    /** Normalize to a fixed scale, refusing silent rounding. */
    public static function exact(string|int|BigDecimal|null $a, int $scale = self::QTY_SCALE): string
    {
        return (string) self::of($a)->toScale($scale, BrickRounding::Unnecessary);
    }

    public static function cmp(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): int
    {
        return self::of($a)->compareTo(self::of($b));
    }

    public static function gt(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): bool
    {
        return self::cmp($a, $b) > 0;
    }

    public static function gte(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): bool
    {
        return self::cmp($a, $b) >= 0;
    }

    public static function lt(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): bool
    {
        return self::cmp($a, $b) < 0;
    }

    public static function lte(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): bool
    {
        return self::cmp($a, $b) <= 0;
    }

    public static function eq(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): bool
    {
        return self::cmp($a, $b) === 0;
    }

    public static function isZero(string|int|BigDecimal|null $a): bool
    {
        return self::of($a)->isZero();
    }

    public static function isPositive(string|int|BigDecimal|null $a): bool
    {
        return self::of($a)->isPositive();
    }

    public static function isNegative(string|int|BigDecimal|null $a): bool
    {
        return self::of($a)->isNegative();
    }

    public static function isWhole(string|int|BigDecimal|null $a): bool
    {
        return self::of($a)->getFractionalPart()->isZero();
    }

    public static function min(string $a, string $b): string
    {
        return self::lte($a, $b) ? $a : $b;
    }

    public static function max(string $a, string $b): string
    {
        return self::gte($a, $b) ? $a : $b;
    }

    public static function neg(string|int|BigDecimal|null $a, int $scale = self::QTY_SCALE): string
    {
        return (string) self::of($a)->negated()->toScale($scale, BrickRounding::Unnecessary);
    }

    /** @param  iterable<string|int|BigDecimal|null>  $values */
    public static function sum(iterable $values, int $scale = self::QTY_SCALE): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $v) {
            $total = $total->plus(self::of($v));
        }

        return (string) $total->toScale($scale, BrickRounding::Unnecessary);
    }

    /** Human display without trailing zeros (never used for storage). */
    public static function trim(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }

    private static function brick(RoundingMode $mode): BrickRounding
    {
        return match ($mode) {
            RoundingMode::HALF_UP => BrickRounding::HalfUp,
            RoundingMode::HALF_DOWN => BrickRounding::HalfDown,
            RoundingMode::HALF_EVEN => BrickRounding::HalfEven,
            RoundingMode::CEILING => BrickRounding::Ceiling,
            RoundingMode::FLOOR => BrickRounding::Floor,
            RoundingMode::TRUNCATE => BrickRounding::Down,
        };
    }
}
