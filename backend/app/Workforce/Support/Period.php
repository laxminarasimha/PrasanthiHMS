<?php

namespace App\Workforce\Support;

use Carbon\CarbonImmutable;

/** A salary month, written YYYY-MM. */
final class Period
{
    public static function start(string $period): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $period.'-01');
    }

    public static function end(string $period): CarbonImmutable
    {
        return self::start($period)->endOfMonth()->startOfDay();
    }

    public static function days(string $period): int
    {
        return self::start($period)->daysInMonth;
    }

    public static function after(string $period, int $months = 1): string
    {
        return self::start($period)->addMonthsNoOverflow($months)->format('Y-m');
    }

    /** "November 2026". */
    public static function name(string $period): string
    {
        return self::start($period)->format('F Y');
    }
}
