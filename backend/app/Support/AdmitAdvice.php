<?php

namespace App\Support;

use App\Models\Admission;
use App\Models\Consultation;
use Illuminate\Support\Collection;

/**
 * Admission advised by a doctor on the visit pad, for IPD to act on. The one place that decides which advice is
 * still waiting, so the IPD page, the To do screen and the admit form all agree.
 */
class AdmitAdvice
{
    /** Advice older than this is no longer chased. */
    public const DAYS = 14;

    /**
     * Advice still waiting: given in the last two weeks, not marked as "not coming", and the patient has not been
     * admitted since (and is not in a bed now). One line per patient, the latest advice.
     *
     * @return Collection<int, Consultation>
     */
    public static function waiting(?int $doctorId = null, ?int $patientId = null): Collection
    {
        $advice = Consultation::whereNotNull('admit_advised_at')->whereNull('admit_closed_at')
            ->where('admit_advised_at', '>=', today()->subDays(self::DAYS))
            ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
            ->when($patientId, fn ($q) => $q->where('patient_id', $patientId))
            ->with(['patient:id,uhid,title,name,gender,date_of_birth,mobile', 'doctor:id,name'])->latest('admit_advised_at')->get()
            ->unique('patient_id')->values();
        if ($advice->isEmpty()) {
            return $advice;
        }

        $stays = Admission::whereIn('patient_id', $advice->pluck('patient_id'))
            ->where(fn ($q) => $q->where('status', 'admitted')->orWhere('admitted_at', '>=', $advice->min('admit_advised_at')->copy()->startOfDay()))
            ->get(['id', 'patient_id', 'status', 'admitted_at', 'consultation_id']);

        // The admission time is typed by staff, so anything from the day of the advice onwards counts as "admitted since".
        return $advice->reject(fn (Consultation $c) => $stays->contains(fn (Admission $a) => $a->patient_id === $c->patient_id
            && ($a->status === 'admitted' || $a->consultation_id === $c->id || $a->admitted_at->gte($c->admit_advised_at->copy()->startOfDay()))))->values();
    }

    /** The visit a new admission of this patient came from, when a doctor advised it. */
    public static function for(int $patientId): ?Consultation
    {
        return self::waiting(null, $patientId)->first();
    }
}
