<?php

namespace App\Support;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\DischargeSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Patients a doctor asked to come back who have not been seen since. "Seen" is any visit they arrived for,
 * with any doctor, or an admission, after the visit that gave the advice. One line per patient: the latest
 * advice in the period. The front desk rings them from this list.
 *
 * The advice is the "next visit" date of an OPD visit, or the follow-up date on a discharge summary. A patient who
 * is still in a bed, or who died, is never listed from a discharge summary.
 */
class FollowUps
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function notReturned(Carbon $from, Carbon $to): Collection
    {
        // Someone advised for today or later is not late yet.
        $to = $to->copy()->min(today()->subDay()->endOfDay());
        if ($to->lt($from)) {
            return collect();
        }

        // The latest advice wins, whether it was given at a visit or on going home.
        return self::afterVisits($from, $to)->concat(self::afterDischarge($from, $to))
            ->sortByDesc('follow_up_on')->unique(fn (array $row) => $row['patient']->id)->values();
    }

    /**
     * Follow-up dates written on discharge summaries, for one day: who is due to come back that day.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function dischargedDueOn(string $day): Collection
    {
        return self::discharged($day, $day)->map(fn (Admission $a) => [
            'id' => $a->summary->id, 'source' => 'discharge',
            'patient_id' => $a->patient_id, 'doctor_id' => $a->doctor_id,
            'diagnosis' => $a->summary->diagnosis, 'follow_up_on' => $a->summary->follow_up_on->format('Y-m-d'),
            'completed_at' => $a->discharged_at->format('Y-m-d H:i'),
            'patient' => $a->patient, 'doctor' => $a->doctor,
        ])->values();
    }

    /**
     * Stays that ended with the patient going home, whose summary gives a follow-up date in these days.
     *
     * @return Collection<int, Admission>
     */
    private static function discharged(string $from, string $to): Collection
    {
        return Admission::whereNotIn('status', ['admitted', 'died'])->whereNotNull('discharged_at')
            ->whereHas('summary', fn ($q) => $q->whereDate('follow_up_on', '>=', $from)->whereDate('follow_up_on', '<=', $to))
            // Nobody who died on a later stay is rung to come back.
            ->whereNotIn('patient_id', Admission::where('status', 'died')->select('patient_id'))
            ->with(['summary', 'patient:id,uhid,title,name,gender,date_of_birth,mobile', 'doctor:id,name'])
            ->latest('discharged_at')->get()->unique('patient_id')->filter(fn (Admission $a) => $a->patient && $a->summary?->follow_up_on)->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function afterDischarge(Carbon $from, Carbon $to): Collection
    {
        $stays = self::discharged($from->toDateString(), $to->toDateString());
        if ($stays->isEmpty()) {
            return collect();
        }
        $ids = $stays->pluck('patient_id');
        $since = $stays->min('discharged_at');
        $visits = Appointment::whereIn('patient_id', $ids)->where('status', '!=', 'cancelled')->where('scheduled_at', '>', $since->format('Y-m-d H:i'))
            ->orderBy('scheduled_at')->get(['id', 'patient_id', 'scheduled_at', 'status', 'arrived_at'])->groupBy('patient_id');
        $later = Admission::whereIn('patient_id', $ids)->where('admitted_at', '>', $since->format('Y-m-d H:i'))->get(['id', 'patient_id', 'admitted_at'])->groupBy('patient_id');

        return $stays->reject(function (Admission $stay) use ($visits, $later) {
            $after = fn ($when) => $when && Carbon::parse($when)->gt($stay->discharged_at);
            $came = ($visits[$stay->patient_id] ?? collect())->contains(fn (Appointment $a) => ($a->arrived_at || $a->status !== 'booked') && $after($a->getRawOriginal('scheduled_at')));

            return $came || ($later[$stay->patient_id] ?? collect())->contains(fn (Admission $a) => $a->id !== $stay->id && $after($a->getRawOriginal('admitted_at')));
        })->map(function (Admission $stay) use ($visits) {
            $next = ($visits[$stay->patient_id] ?? collect())->first(fn (Appointment $a) => $a->status === 'booked' && Carbon::parse($a->getRawOriginal('scheduled_at'))->gte(today()));

            return [
                'id' => $stay->summary->id, 'source' => 'discharge',
                'patient' => $stay->patient, 'doctor' => $stay->doctor,
                'follow_up_on' => $stay->summary->follow_up_on->format('Y-m-d'),
                'days_late' => (int) $stay->summary->follow_up_on->diffInDays(today()),
                'seen_on' => $stay->discharged_at->format('Y-m-d'),
                'diagnosis' => str($stay->summary->diagnosis)->before("\n")->limit(60)->value() ?: null,
                'booked_for' => $next ? Carbon::parse($next->getRawOriginal('scheduled_at'))->format('Y-m-d') : null,
            ];
        })->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function afterVisits(Carbon $from, Carbon $to): Collection
    {
        $advised = Consultation::whereNotNull('completed_at')->whereDate('follow_up_on', '>=', $from)->whereDate('follow_up_on', '<=', $to)
            ->with(['patient:id,uhid,title,name,gender,date_of_birth,mobile', 'doctor:id,name'])
            ->orderByDesc('follow_up_on')->orderByDesc('id')
            ->get(['id', 'appointment_id', 'patient_id', 'doctor_id', 'diagnosis', 'follow_up_on', 'completed_at'])
            ->unique('patient_id')->filter(fn (Consultation $c) => $c->patient)->values();
        if ($advised->isEmpty()) {
            return collect();
        }

        $ids = $advised->pluck('patient_id');
        $since = $advised->min('completed_at');
        $visits = Appointment::whereIn('patient_id', $ids)->where('status', '!=', 'cancelled')->where('scheduled_at', '>', $since->format('Y-m-d H:i'))
            ->orderBy('scheduled_at')->get(['id', 'patient_id', 'scheduled_at', 'status', 'arrived_at'])->groupBy('patient_id');
        $admissions = Admission::whereIn('patient_id', $ids)->where('admitted_at', '>', $since->format('Y-m-d H:i'))->get(['patient_id', 'admitted_at'])->groupBy('patient_id');

        return $advised->reject(function (Consultation $c) use ($visits, $admissions) {
            $after = fn ($when) => $when && Carbon::parse($when)->gt($c->completed_at);
            $came = ($visits[$c->patient_id] ?? collect())->contains(fn (Appointment $a) => $a->id !== $c->appointment_id && ($a->arrived_at || $a->status !== 'booked') && $after($a->getRawOriginal('scheduled_at')));

            return $came || ($admissions[$c->patient_id] ?? collect())->contains(fn (Admission $a) => $after($a->getRawOriginal('admitted_at')));
        })->map(function (Consultation $c) use ($visits) {
            $next = ($visits[$c->patient_id] ?? collect())->first(fn (Appointment $a) => $a->status === 'booked' && Carbon::parse($a->getRawOriginal('scheduled_at'))->gte(today()));

            return [
                'id' => $c->id, 'source' => 'visit',
                'patient' => $c->patient, 'doctor' => $c->doctor,
                'follow_up_on' => $c->follow_up_on->format('Y-m-d'),
                'days_late' => (int) $c->follow_up_on->diffInDays(today()),
                'seen_on' => $c->completed_at->format('Y-m-d'),
                'diagnosis' => str($c->diagnosis)->before("\n")->limit(60)->value() ?: null,
                'booked_for' => $next ? Carbon::parse($next->getRawOriginal('scheduled_at'))->format('Y-m-d') : null,
            ];
        })->values();
    }
}
