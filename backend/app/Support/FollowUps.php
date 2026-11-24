<?php

namespace App\Support;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Consultation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Patients a doctor asked to come back who have not been seen since. "Seen" is any visit they arrived for,
 * with any doctor, or an admission, after the visit that gave the advice. One line per patient: the latest
 * advice in the period. The front desk rings them from this list.
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
                'id' => $c->id,
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
