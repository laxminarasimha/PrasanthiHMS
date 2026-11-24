<?php

namespace App\Support;

use App\Models\Admission;
use App\Models\ConsultantPayment;
use App\Models\ConsultantVisit;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What a consultant has earned from the hospital, and how.
 *
 * Two ways, as the hospital pays them. For a patient who is their own case (booked with them, or admitted under
 * them) they get a percentage of what is collected, set for each doctor. For a visit to another doctor's inpatient
 * they get a fixed fee: one amount for the first visit to that patient, a smaller one for each later day.
 */
final class ConsultantFees
{
    /** What the hospital pays for a consulting visit until it sets its own figures. */
    public const RATES = ['first_visit' => 1000.0, 'later_visit' => 500.0];

    /** @return array{first_visit: float, later_visit: float} */
    public static function rates(): array
    {
        $saved = Setting::find('consultants')?->value ?? [];

        return ['first_visit' => (float) ($saved['first_visit'] ?? self::RATES['first_visit']), 'later_visit' => (float) ($saved['later_visit'] ?? self::RATES['later_visit'])];
    }

    /**
     * Writes down that a consultant saw an inpatient on a day. One entry for a day, however many times they looked in.
     * The doctor the patient is admitted under is not a visiting consultant on their own case.
     */
    public static function visit(Admission $admission, User $doctor, string $date, ?string $note, User $by): ConsultantVisit
    {
        if ((int) $admission->doctor_id === (int) $doctor->id) {
            throw ValidationException::withMessages(['doctor_id' => "{$doctor->name} is the doctor this patient is admitted under. Their share comes from the bill, not from a visit fee."]);
        }
        if ($date < $admission->admitted_at->format('Y-m-d') || ($admission->discharged_at && $date > $admission->discharged_at->format('Y-m-d'))) {
            throw ValidationException::withMessages(['visited_on' => 'Choose a day on which this patient was admitted here.']);
        }
        if (ConsultantVisit::standing()->where(['admission_id' => $admission->id, 'doctor_id' => $doctor->id])->whereDate('visited_on', $date)->exists()) {
            throw ValidationException::withMessages(['visited_on' => "{$doctor->name}'s visit on this day is already written down. It is one fee for a day."]);
        }
        $visit = ConsultantVisit::create([
            'branch_id' => $admission->branch_id, 'admission_id' => $admission->id, 'patient_id' => $admission->patient_id, 'doctor_id' => $doctor->id,
            'visited_on' => $date, 'kind' => 'later', 'fee' => 0, 'note' => $note, 'created_by' => $by->id,
        ]);
        self::settle($admission->id, $doctor->id);

        return $visit->fresh();
    }

    /**
     * Sets which of a consultant's visits to one patient is the first, and each visit's fee. The earliest day is the
     * first visit; every other day is a later one. A visit already at the right kind keeps the fee it was given, so a
     * change of rates does not rewrite what was earned before it.
     */
    public static function settle(int $admissionId, int $doctorId): void
    {
        $rates = self::rates();
        $visits = ConsultantVisit::standing()->where(['admission_id' => $admissionId, 'doctor_id' => $doctorId])->orderBy('visited_on')->orderBy('id')->get();
        foreach ($visits as $i => $visit) {
            $kind = $i === 0 ? 'first' : 'later';
            if ($visit->kind !== $kind || (float) $visit->fee === 0.0) {
                $visit->update(['kind' => $kind, 'fee' => $rates[$kind === 'first' ? 'first_visit' : 'later_visit']]);
            }
        }
    }

    /**
     * A month for every doctor with anything in it: their share of what was collected on their own cases, their
     * visit fees, what was paid to them for the month, and what is still due.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function month(string $month): Collection
    {
        $from = Carbon::parse($month.'-01')->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $collected = DoctorMoney::between($from, $to)->where('doctor_id', '>', 0)->keyBy('doctor_id');
        $visits = ConsultantVisit::standing()->whereBetween('visited_on', [$from->toDateString(), $to->toDateString()])->get()->groupBy('doctor_id');
        $paid = ConsultantPayment::standing()->where('for_month', $month)->get()->groupBy('doctor_id');
        $doctors = User::query()->where(fn ($q) => $q->where('role', 'doctor')->where('is_active', true)->orWhereIn('id', $collected->keys()->merge($visits->keys())->merge($paid->keys())->unique()))->orderBy('name')->get();

        return $doctors->map(function (User $doctor) use ($collected, $visits, $paid) {
            $row = $collected->get($doctor->id);
            $shares = [];
            foreach (DoctorMoney::COUNTERS as $key => $label) {
                $percent = $doctor->{"share_{$key}"};
                $amount = (float) ($row["{$key}_collected"] ?? 0);
                $shares[] = ['counter' => $label, 'collected' => round($amount, 2), 'percent' => $percent === null ? null : (float) $percent, 'share' => round($amount * (float) $percent / 100, 2)];
            }
            $mine = $visits->get($doctor->id) ?? collect();
            $share = round(array_sum(array_column($shares, 'share')), 2);
            $fees = round((float) $mine->sum('fee'), 2);
            $given = round((float) ($paid->get($doctor->id) ?? collect())->sum('amount'), 2);

            return [
                'doctor_id' => $doctor->id, 'doctor' => $doctor->name, 'department' => $doctor->department, 'shares' => $shares, 'share' => $share,
                'visits' => $mine->count(), 'first_visits' => $mine->where('kind', 'first')->count(), 'visit_fees' => $fees,
                'earned' => round($share + $fees, 2), 'paid' => $given, 'due' => round($share + $fees - $given, 2),
            ];
        })->values();
    }
}
