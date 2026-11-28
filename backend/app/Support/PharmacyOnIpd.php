<?php

namespace App\Support;

use App\Models\Admission;
use App\Models\AdmissionCharge;
use App\Models\PharmacySale;
use App\Models\PharmacySaleItem;
use Illuminate\Support\Collection;

/**
 * Pharmacy bills that are settled through an inpatient's bill rather than at the counter. The pharmacy bill stays
 * one line of money on the IPD bill, so returns and corrections keep working as before; the medicines on it are
 * listed under that line, so the IPD bill still says what was given.
 */
class PharmacyOnIpd
{
    /**
     * Puts what is still owed on a pharmacy bill onto the IPD bill, as the counter saves it.
     * The same thing the IPD desk does by hand with "Add to IPD bill".
     */
    public static function put(Admission $admission, PharmacySale $sale, int $userId): ?AdmissionCharge
    {
        $due = round((float) $sale->due, 2);
        if ($due <= 0) {
            return null;
        }
        $charge = $admission->charges()->create([
            'description' => "Pharmacy bill {$sale->bill_no}",
            'category' => 'Pharmacy',
            // The day the medicines were given: today, unless the bill was entered later for an earlier day.
            'charged_on' => $sale->sold_at?->toDateString() ?? today(),
            'quantity' => 1,
            'unit_price' => $due,
            'amount' => $due,
            'created_by' => $userId,
            'source_type' => 'pharmacy',
            'source_id' => $sale->id,
        ]);
        PharmacySale::whereKey($sale->id)->increment('ipd_transfer', $due);
        $admission->recalculate();

        return $charge;
    }

    /**
     * The medicines behind each pharmacy line of an IPD bill, keyed by the charge's id.
     *
     * @param  Collection<int, AdmissionCharge>  $charges
     * @return Collection<int, list<array{name: string, batch: ?string, quantity: int, returned: int, rate: float, amount: float}>>
     */
    public static function medicines(Collection $charges): Collection
    {
        $lines = $charges->where('source_type', 'pharmacy');
        if ($lines->isEmpty()) {
            return collect();
        }
        $items = PharmacySaleItem::whereIn('pharmacy_sale_id', $lines->pluck('source_id'))->orderBy('id')->get()->groupBy('pharmacy_sale_id');

        return $lines->mapWithKeys(fn (AdmissionCharge $charge) => [$charge->id => $items->get($charge->source_id, collect())->map(fn (PharmacySaleItem $item) => [
            'name' => $item->medicine_name, 'batch' => $item->batch_no, 'quantity' => (int) $item->quantity, 'returned' => (int) $item->returned_quantity,
            'rate' => (float) $item->unit_price, 'amount' => (float) $item->amount,
        ])->values()->all()]);
    }
}
