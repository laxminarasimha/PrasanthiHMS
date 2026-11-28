<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\PharmacySale;
use Illuminate\Support\Collection;

/**
 * Whether a doctor's prescription has had its medicines from the pharmacy, decided in one place for the Prescriptions
 * page, the counter's prescription cards and the To do list.
 *
 * A bill made at the counter from the prescription's card remembers it: that prescription is "filled". Older bills,
 * and medicines typed at the counter without the card, remember nothing, so for those any pharmacy bill for the
 * patient made after the visit was finished still counts, as it always has: "billed since". A deleted bill counts for
 * neither.
 */
class PrescriptionFill
{
    /**
     * The bill behind each finished visit: the first made from it, else the first for the patient since, else none.
     *
     * @param  Collection<int, Consultation>  $visits
     * @return array<int, array{id: int, bill_no: string, filled: bool}> by visit (consultation) id; a visit with no bill is left out
     */
    public static function bills(Collection $visits): array
    {
        $visits = $visits->filter(fn (Consultation $c) => $c->completed_at !== null);
        if ($visits->isEmpty()) {
            return [];
        }
        $sales = PharmacySale::whereNull('voided_at')
            ->where(fn ($q) => $q->whereIn('consultation_id', $visits->pluck('id'))
                ->orWhere(fn ($since) => $since->whereIn('patient_id', $visits->pluck('patient_id')->unique())->where('sold_at', '>=', $visits->min('completed_at'))))
            ->orderBy('sold_at')->orderBy('id')
            ->get(['id', 'patient_id', 'consultation_id', 'bill_no', 'sold_at']);

        $bills = [];
        foreach ($visits as $visit) {
            $made = $sales->first(fn (PharmacySale $s) => (int) $s->consultation_id === (int) $visit->id);
            $since = $made ? null : $sales->first(fn (PharmacySale $s) => (int) $s->patient_id === (int) $visit->patient_id && $s->sold_at->gte($visit->completed_at));
            if ($made || $since) {
                $bills[$visit->id] = ['id' => ($made ?? $since)->id, 'bill_no' => ($made ?? $since)->bill_no, 'filled' => $made !== null];
            }
        }

        return $bills;
    }
}
