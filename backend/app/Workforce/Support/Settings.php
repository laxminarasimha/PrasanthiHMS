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
        'numbering' => ['employee_prefix' => 'EMP-', 'employee_digits' => 6],
        'attendance' => [
            // Staff marking their own attendance from their login. Off until the hospital decides its safeguards are enough.
            'self_check_in' => false,
            // The weekly off for someone who has none of their own set.
            'default_weekly_off' => ['sun'],
        ],
        'leave' => [
            'year_starts_month' => 1, // 1 = January, 4 = April
            'count_days_off' => false, // whether weekly offs and holidays inside a leave are counted as leave days
            'allow_negative' => false, // whether leave may be approved beyond the balance
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
