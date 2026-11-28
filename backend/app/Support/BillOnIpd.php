<?php

namespace App\Support;

use App\Models\Admission;
use App\Models\AdmissionCharge;
use App\Models\Bill;
use App\Models\LabOrder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Front desk and lab bills that are settled through an inpatient's bill instead of at the counter. The bill stays one
 * line of money on the IPD bill; on the bill itself the amount shows as received "through IPD". The one place that
 * writes this, whether the IPD desk moves a bill by hand or a lab order is made from the case.
 */
class BillOnIpd
{
    /** Puts what is still owed on the bill onto the IPD bill. `$on` is the day of the IPD line; left out, the bill's own day. */
    public static function put(Admission $admission, Bill $bill, int $userId, ?string $on = null): ?AdmissionCharge
    {
        $due = round((float) $bill->due, 2);
        if ($due <= 0) {
            return null;
        }
        $order = $bill->labOrder()->first(['id', 'bill_id', 'order_no']);
        $charge = $admission->charges()->create([
            'description' => $order ? "Lab order {$order->order_no} (bill {$bill->bill_no})" : "Front desk bill {$bill->bill_no}",
            'category' => $order ? 'Lab' : 'OPD',
            'charged_on' => $on ?? $bill->created_at?->toDateString() ?? today()->toDateString(),
            'quantity' => 1,
            'unit_price' => $due,
            'amount' => $due,
            'created_by' => $userId,
            'source_type' => 'bill',
            'source_id' => $bill->id,
        ]);
        $bill->payments()->create(['type' => 'payment', 'amount' => $due, 'mode' => 'ipd', 'reference' => "IPD charge {$charge->id}", 'received_by' => $userId]);
        $bill->recalculate();
        $admission->recalculate();

        return $charge;
    }

    /** How much of this bill is being collected through an IPD bill. */
    public static function held(Bill $bill): float
    {
        $by = fn (string $type) => (float) $bill->payments()->where('mode', 'ipd')->where('type', $type)->sum('amount');

        return round($by('payment') - $by('refund'), 2);
    }

    /**
     * A bill that is on an IPD bill cannot be changed on its own: the IPD line would no longer say what the bill does.
     * It is taken off the IPD bill first, changed, and put back.
     */
    public static function refuseChange(Bill $bill, string $field, string $what): void
    {
        if (self::held($bill) > 0) {
            throw ValidationException::withMessages([$field => "This bill is on an IPD bill, so {$what}. Or remove it from the IPD bill first, change it here, and add it back."]);
        }
    }

    /**
     * The tests behind each lab line of an IPD bill, keyed by the charge's id, so the IPD bill says what was done.
     *
     * @param  Collection<int, AdmissionCharge>  $charges
     * @return Collection<int, list<string>>
     */
    public static function tests(Collection $charges): Collection
    {
        $lines = $charges->where('source_type', 'bill');
        if ($lines->isEmpty()) {
            return collect();
        }
        $orders = LabOrder::whereIn('bill_id', $lines->pluck('source_id'))->with('items:id,lab_order_id,test_name,repeat_no')->get()->keyBy('bill_id');

        return $lines->filter(fn (AdmissionCharge $c) => $orders->has($c->source_id))
            ->mapWithKeys(fn (AdmissionCharge $c) => [$c->id => $orders[$c->source_id]->items->map(fn ($i) => $i->test_name.($i->repeat_no ? " ({$i->repeat_no})" : ''))->values()->all()]);
    }
}
