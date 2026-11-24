<?php

namespace App\Workforce\Support;

use App\Workforce\Models\Setting;

/**
 * The module's choices for a branch, each with what applies until the hospital chooses otherwise. Nothing here is
 * about any one hospital: a new branch starts from these and changes them under Workforce → Setup.
 */
final class Settings
{
    public const DEFAULTS = [
        // How staff are numbered when added here: a prefix and a count padded to so many digits.
        'numbering' => ['employee_prefix' => 'EMP-', 'employee_digits' => 6, 'payslip_prefix' => 'PS-', 'advance_prefix' => 'ADV-'],
        'attendance' => [
            // Staff marking their own attendance from their login. Off until the hospital decides its safeguards are enough.
            'self_check_in' => false,
            // The hospital's own internet connections. With any listed, staff can mark their own attendance only while
            // on one of them (the hospital Wi-Fi). Empty: from anywhere.
            'self_check_in_networks' => [],
            // The weekly off for someone who has none of their own set.
            'default_weekly_off' => ['sun'],
        ],
        'leave' => [
            'year_starts_month' => 1, // 1 = January, 4 = April
            'count_days_off' => false, // whether weekly offs and holidays inside a leave are counted as leave days
            'allow_negative' => false, // whether leave may be approved beyond the balance
        ],
        'payroll' => [
            // What a day's pay is, for someone paid by the month: the month's own days, or a fixed 30 or 26.
            'month_days' => 'calendar', // calendar | fixed_30 | fixed_26
            // Whether a day with no attendance and no leave takes a day's pay off. Off for a hospital not yet keeping attendance here.
            'deduct_absence' => true,
            // Whether net pay is rounded to the whole rupee (the difference is shown as its own line) or left in paise.
            'round_net_to' => 'rupee', // rupee | paise
            // For someone paid by the day: whether weekly offs and holidays are paid days too.
            'daily_paid_days_off' => false,
            // Whether a new salary waits for a second person's approval before payroll uses it.
            'salary_needs_approval' => true,
        ],
    ];

    /** @return array<string, mixed> */
    public static function get(string $group): array
    {
        $saved = Setting::query()->where('key', $group)->value('value');

        return array_merge(self::DEFAULTS[$group] ?? [], is_array($saved) ? $saved : []);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function put(string $group, array $values): array
    {
        $kept = array_intersect_key($values, self::DEFAULTS[$group] ?? []);
        Setting::query()->updateOrCreate(['key' => $group], ['value' => array_merge(self::get($group), $kept), 'updated_by' => auth()->id()]);

        return self::get($group);
    }
}
