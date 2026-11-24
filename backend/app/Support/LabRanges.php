<?php

namespace App\Support;

use App\Models\Patient;
use Illuminate\Support\Carbon;

/**
 * Normal ranges that depend on who the patient is.
 *
 * A field of a lab template has one plain normal range, and may have extra ones, each for a sex, an age band,
 * or both ("Female: 12 - 15", "0 to 12 years: 11 - 14"). When a test is ordered, the first extra range that fits
 * the patient is printed on the report; if none fits, the plain one is. A report already made keeps the range it
 * was made with.
 */
class LabRanges
{
    public const UNITS = ['years', 'months', 'days'];

    /**
     * The extra ranges of one field, in one fixed shape, so two templates can be compared.
     *
     * @return list<array{sex: ?string, age_from: ?int, age_to: ?int, age_unit: string, range: string}>
     */
    public static function clean(mixed $rows): array
    {
        $clean = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $range = is_array($row) ? trim((string) ($row['range'] ?? '')) : '';
            if ($range === '') {
                continue;
            }
            $number = fn ($value) => is_numeric($value) ? (int) $value : null;
            $clean[] = [
                'sex' => in_array($row['sex'] ?? null, ['male', 'female'], true) ? $row['sex'] : null,
                'age_from' => $number($row['age_from'] ?? null),
                'age_to' => $number($row['age_to'] ?? null),
                'age_unit' => in_array($row['age_unit'] ?? null, self::UNITS, true) ? $row['age_unit'] : 'years',
                'range' => $range,
            ];
        }

        return $clean;
    }

    /**
     * The normal range to print for this patient: the first extra range that fits, otherwise the plain one.
     * An extra range for an age band fits nobody whose date of birth is not known.
     */
    public static function pick(?string $plain, mixed $ranges, ?Patient $patient, ?Carbon $on = null): ?string
    {
        $on ??= now();
        $born = $patient?->date_of_birth;
        foreach (self::clean($ranges) as $row) {
            if ($row['sex'] !== null && $row['sex'] !== $patient?->gender) {
                continue;
            }
            if ($row['age_from'] !== null || $row['age_to'] !== null) {
                if (! $born) {
                    continue;
                }
                // Completed years, months or days: "0 to 12 years" holds until the thirteenth birthday.
                $age = (int) match ($row['age_unit']) {
                    'days' => $born->diffInDays($on),
                    'months' => $born->diffInMonths($on),
                    default => $born->diffInYears($on),
                };
                if (($row['age_from'] !== null && $age < $row['age_from']) || ($row['age_to'] !== null && $age > $row['age_to'])) {
                    continue;
                }
            }

            return $row['range'];
        }

        return $plain;
    }
}
