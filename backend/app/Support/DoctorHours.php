<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * When a doctor sees patients: their own hours for each weekday if they have set any, otherwise the
 * hospital's OPD hours; and how long one of their slots is. The patient app and the front desk both
 * ask here, so they cannot disagree.
 */
class DoctorHours
{
    /** Minutes in one of this doctor's slots: their own if set, otherwise the hospital's. */
    public static function step(?User $doctor): int
    {
        return max(5, (int) ($doctor?->slot_minutes ?: Setting::group('calendar', Setting::CALENDAR_DEFAULTS)['slot_minutes']));
    }

    /**
     * The sessions the doctor keeps on that day, earliest first.
     *
     * @return list<array{from: string, to: string}>
     */
    public static function ranges(User $doctor, Carbon $day): array
    {
        $own = $doctor->availability;
        if (is_array($own) && $own !== []) {
            return array_values($own[strtolower($day->format('D'))] ?? []);
        }
        $settings = Setting::group('calendar', Setting::CALENDAR_DEFAULTS);

        return [['from' => $settings['morning_start'], 'to' => $settings['morning_end']], ['from' => $settings['evening_start'], 'to' => $settings['evening_end']]];
    }

    /** Whether that time of day falls inside one of the doctor's sessions. */
    public static function covers(User $doctor, Carbon $at): bool
    {
        $time = $at->format('H:i');

        return collect(self::ranges($doctor, $at))->contains(fn (array $r) => $r['from'] <= $time && $time < $r['to']);
    }

    /** "Monday 10:30 AM – 2:00 PM and 6:00 PM – 9:00 PM", or that the doctor is not in that day. */
    public static function describe(User $doctor, Carbon $day): string
    {
        $ranges = self::ranges($doctor, $day);
        if (! $ranges) {
            return "{$doctor->name} does not see patients on {$day->format('l')}s.";
        }
        $hours = collect($ranges)->map(fn (array $r) => Carbon::parse($r['from'])->format('g:i A').' – '.Carbon::parse($r['to'])->format('g:i A'))->join(' and ');

        return "{$doctor->name} sees patients on {$day->format('l')}s from {$hours}.";
    }
}
