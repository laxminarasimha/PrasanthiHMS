<?php

namespace App\Support;

use App\Models\Admission;
use App\Models\AdmissionOrder;
use App\Models\PharmacySale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Medicines a doctor has ordered for patients in the ward, as the pharmacy needs to see them. Nothing ties a
 * pharmacy bill to one order, so an order counts as "waiting" until a pharmacy bill is made for that patient after
 * it was written: the same rule the counter uses for a prescription.
 */
class WardMedicines
{
    /**
     * The running medicine orders of the patients admitted now that nothing has been sent for yet, by admission.
     *
     * @return Collection<int, Collection<int, AdmissionOrder>>
     */
    public static function waiting(): Collection
    {
        $orders = AdmissionOrder::where('kind', 'medicine')->whereNull('stopped_at')
            ->whereIn('admission_id', Admission::where('status', 'admitted')->select('id'))
            ->orderBy('id')->get();
        if ($orders->isEmpty()) {
            return collect();
        }
        $patients = Admission::whereIn('id', $orders->pluck('admission_id')->unique())->pluck('patient_id', 'id');
        $last = self::lastBills($patients->values()->all());

        return $orders->filter(fn (AdmissionOrder $o) => self::isNew($o, $last[$patients[$o->admission_id]] ?? null))->groupBy('admission_id');
    }

    /** Whether nothing has been billed at the pharmacy for the patient since this order was written. */
    public static function isNew(AdmissionOrder $order, ?Carbon $lastBill): bool
    {
        return $lastBill === null || $order->created_at === null || $lastBill->lt($order->created_at);
    }

    /**
     * When the pharmacy last made a bill for each of these patients.
     *
     * @param  list<int>  $patientIds
     * @return array<int, Carbon>
     */
    public static function lastBills(array $patientIds): array
    {
        return PharmacySale::whereIn('patient_id', $patientIds)->whereNull('voided_at')->orderBy('sold_at')->get(['patient_id', 'sold_at'])
            ->mapWithKeys(fn (PharmacySale $sale) => [$sale->patient_id => Carbon::parse($sale->getRawOriginal('sold_at'))])->all();
    }
}
