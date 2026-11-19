<?php

namespace App\Workforce\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Money in payroll is never a floating-point number. Every sum goes through here: exact decimal arithmetic, rounded
 * to paise only where a figure is written down, half a paisa going up.
 */
final class Money
{
    public static function of(mixed $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }

        // A float is turned into the short decimal a person would write for it, never its binary tail.
        return BigDecimal::of(is_float($value) ? rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') : (string) $value);
    }

    /** To paise. */
    public static function paise(mixed $value): BigDecimal
    {
        return self::of($value)->toScale(2, RoundingMode::HalfUp);
    }

    /** As stored: "1234.50". */
    public static function text(mixed $value): string
    {
        return (string) self::paise($value);
    }

    /** One amount as a share of another: amount × top ÷ bottom, to paise. */
    public static function share(mixed $amount, mixed $top, mixed $bottom): BigDecimal
    {
        return self::of($amount)->multipliedBy(self::of($top))->dividedBy(self::of($bottom), 2, RoundingMode::HalfUp);
    }

    /** A percentage of an amount, to paise. */
    public static function percent(mixed $amount, mixed $percent): BigDecimal
    {
        return self::share($amount, $percent, 100);
    }

    /** As read by a person: ₹12,34,567.50, grouped the Indian way. */
    public static function show(mixed $value): string
    {
        $amount = self::paise($value);
        [$whole, $paise] = explode('.', (string) $amount->abs());
        $last = substr($whole, -3);
        $rest = substr($whole, 0, -3);
        $grouped = $rest === '' ? $last : preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).','.$last;

        return ($amount->isNegative() ? '-' : '').'₹'.$grouped.'.'.$paise;
    }

    /** A count of days or hours as a person writes it: 2, 2.5, never 2.50. */
    public static function count(float|int|string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
