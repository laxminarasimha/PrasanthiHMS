<?php

namespace App\Support;

use App\Http\Controllers\Api\AdminController;
use App\Models\Appointment;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * The hospital's rule for a follow-up: a patient who comes back to the same doctor within so many days of a
 * consultation is not charged again. The desk only books the appointment; this decides which of the two it is.
 *
 * The days are counted from the consultation, not from the last follow-up, so free visits do not chain on for ever.
 * A consultation is a visit the patient came for that was not itself a follow-up; a new one starts the days again.
 * (Not to be confused with `FollowUps`, the list of patients a doctor asked to come back.)
 */
final class FreeFollowUp
{
    /** Until the hospital sets its own under Setup → Preferences. */
    public const DAYS = 15;

    /** Statuses of a visit the patient did come for. One only booked, or cancelled, earns no follow-up. */
    private const CAME = ['arrived', 'ongoing', 'reviewed'];

    /** How many days after a consultation a visit to the same doctor is free. Zero switches the rule off. */
    public static function days(): int
    {
        return max(0, (int) (Setting::group('billing', AdminController::BILLING_DEFAULTS)['follow_up_days'] ?? self::DAYS));
    }

    /**
     * The consultation that a visit on this day, to this doctor, would be a free follow-up of. Null when there is
     * none within the days: the visit is then a consultation, and is charged.
     */
    public static function of(int $patientId, int $doctorId, Carbon|string $day, ?int $except = null): ?Appointment
    {
        $days = self::days();
        if ($days < 1) {
            return null;
        }
        $day = Carbon::parse($day)->startOfDay();

        return Appointment::query()->where('patient_id', $patientId)->where('doctor_id', $doctorId)
            ->where('is_follow_up', false)->whereIn('status', self::CAME)
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->whereDate('scheduled_at', '<=', $day)->whereDate('scheduled_at', '>=', $day->copy()->subDays($days))
            ->orderByDesc('scheduled_at')->orderByDesc('id')->first();
    }

    /**
     * What the booking form is told for a patient, a doctor and a day.
     *
     * @return array{follow_up: bool, days: int, since: ?string, until: ?string}
     */
    public static function answer(int $patientId, int $doctorId, Carbon|string $day): array
    {
        $first = self::of($patientId, $doctorId, $day);
        $since = $first ? Carbon::parse($first->getRawOriginal('scheduled_at'))->startOfDay() : null;

        return ['follow_up' => $first !== null, 'days' => self::days(), 'since' => $since?->toDateString(), 'until' => $since?->copy()->addDays(self::days())->toDateString()];
    }
}
