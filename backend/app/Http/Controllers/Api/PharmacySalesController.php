<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\AdmissionCharge;
use App\Models\Consultation;
use App\Models\Manufacturer;
use App\Models\Medicine;
use App\Models\Payment;
use App\Models\PharmacyPayment;
use App\Models\PharmacySale;
use App\Models\PharmacySaleItem;
use App\Models\PharmacySaleReturn;
use App\Models\StockBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** What happens to a pharmacy bill after it is made, plus the dashboard and the prescription queue. */
class PharmacySalesController extends Controller
{
    private const SALE_WITH = ['items', 'patient:id,uhid,title,name', 'seller:id,name', 'payments.receiver:id,name', 'returns'];

    public function dashboard(Request $request): JsonResponse
    {
        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($range['from'] ?? today())->startOfDay();
        $to = Carbon::parse($range['to'] ?? $from)->endOfDay();

        $sales = PharmacySale::whereBetween('sold_at', [$from, $to])->whereNull('voided_at');
        $money = PharmacyPayment::whereBetween('created_at', [$from, $to])
            ->selectRaw("mode, sum(case when type = 'refund' then -amount else amount end) as amount")
            ->groupBy('mode')->pluck('amount', 'mode')->map(fn ($amount) => (float) $amount);

        $sellable = StockBatch::where('units_in_stock', '>', 0);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'sales' => (clone $sales)->count(),
            'billed' => round((float) (clone $sales)->sum('total') - (float) PharmacySaleReturn::whereBetween('created_at', [$from, $to])->sum('amount'), 2),
            'collected' => round($money->sum(), 2),
            'collected_cash' => round($money->get('cash', 0), 2),
            'collected_card' => round($money->get('card', 0), 2),
            'collected_other' => round($money->except(['cash', 'card'])->sum(), 2),
            'statistics' => [
                'medicines' => Medicine::where('is_active', true)->count(),
                'manufacturers' => Manufacturer::count(),
                'inventory_cost' => round((float) (clone $sellable)->selectRaw('sum(units_in_stock * pack_price * (1 - discount_percent / 100) / units_per_pack) as value')->value('value'), 2),
                'inventory_mrp' => round((float) (clone $sellable)->selectRaw('sum(units_in_stock * unit_mrp) as value')->value('value'), 2),
                'units_in_stock' => (int) (clone $sellable)->sum('units_in_stock'),
                'out_of_stock' => Medicine::where('is_active', true)->whereDoesntHave('batches', fn ($q) => $q->where('units_in_stock', '>', 0))->count(),
            ],
            'latest_sales' => (clone $sales)->latest('sold_at')->latest('id')->limit(10)->get(['id', 'bill_no', 'patient_id', 'customer_name', 'sold_at', 'total', 'returned', 'paid', 'ipd_transfer', 'voided_at']),
            'fast_moving' => PharmacySaleItem::query()
                ->whereHas('sale', fn ($q) => $q->whereBetween('sold_at', [$from, $to])->whereNull('voided_at'))
                ->selectRaw('medicine_name, sum(quantity - returned_quantity) as sold')
                ->groupBy('medicine_name')->orderByDesc('sold')->limit(10)->get(),
        ]);
    }

    /** The day's prescriptions, each marked with whether a pharmacy bill has been made for the patient since. */
    public function orders(Request $request): JsonResponse
    {
        $day = Carbon::parse($request->validate(['date' => ['nullable', 'date']])['date'] ?? today());

        $consultations = Consultation::whereDate('completed_at', $day)
            ->whereHas('medicines')
            ->with(['patient:id,uhid,title,name,gender,date_of_birth,mobile', 'doctor:id,name', 'medicines:id,consultation_id,medicine,dose,frequency,duration'])
            ->latest('completed_at')->get();

        $billed = PharmacySale::whereIn('patient_id', $consultations->pluck('patient_id'))
            ->whereDate('sold_at', '>=', $day)->whereNull('voided_at')
            ->get(['id', 'patient_id', 'bill_no', 'sold_at'])->groupBy('patient_id');

        return response()->json($consultations->map(fn (Consultation $c) => [
            'id' => $c->id,
            'completed_at' => $c->completed_at->format('Y-m-d H:i'),
            'patient' => $c->patient,
            'doctor' => $c->doctor?->name,
            'medicines' => $c->medicines,
            'bill' => $billed->get($c->patient_id)?->first(fn ($sale) => $sale->sold_at >= $c->completed_at)?->only(['id', 'bill_no']),
        ]));
    }

    /**
     * All the pharmacy bills of one customer together, found from any one of them: the bills of that day, or for
     * someone admitted, every bill of the stay. With each bill come its medicines, and the medicines of all the
     * bills are also added up into one list, so the counter and the ward see what was given without opening each bill.
     */
    public function customerBills(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);
        $data = $request->validate(['sale_id' => ['required', 'exists:pharmacy_sales,id'], 'scope' => ['nullable', Rule::in(['day', 'stay'])]]);
        $anchor = PharmacySale::findOrFail($data['sale_id']);
        $day = $anchor->sold_at->copy();

        // Whose bills: a registered patient's, or a walk-in's with the same name and phone. A walk-in who left no phone is one bill.
        $theirs = fn ($q) => $anchor->patient_id
            ? $q->where('patient_id', $anchor->patient_id)
            : ($anchor->customer_phone
                ? $q->whereNull('patient_id')->where('customer_name', $anchor->customer_name)->where('customer_phone', $anchor->customer_phone)
                : $q->whereKey($anchor->id));

        // The stay this bill fell in, if the patient was admitted then.
        $admission = $anchor->patient_id
            ? Admission::where('patient_id', $anchor->patient_id)->where('admitted_at', '<=', $day)
                ->where(fn ($q) => $q->whereNull('discharged_at')->orWhere('discharged_at', '>=', $day))->latest('admitted_at')->first()
            : null;
        $stay = ($data['scope'] ?? 'day') === 'stay' && $admission;
        [$from, $to] = $stay ? [$admission->admitted_at, $admission->discharged_at ?? now()] : [$day->copy()->startOfDay(), $day->copy()->endOfDay()];

        $sales = PharmacySale::query()->where($theirs)->whereNull('voided_at')->whereBetween('sold_at', [$from, $to])
            ->with(['items', 'payments', 'seller:id,name'])->oldest('sold_at')->oldest('id')->get();

        $medicines = $sales->flatMap->items->groupBy('medicine_name')->map(function ($lines, $name) {
            $given = (int) $lines->sum('quantity');
            $back = (int) $lines->sum('returned_quantity');

            return ['name' => $name, 'quantity' => $given - $back, 'returned' => $back,
                'amount' => round((float) $lines->sum(fn (PharmacySaleItem $i) => $i->quantity > 0 ? (float) $i->amount * ($i->quantity - $i->returned_quantity) / $i->quantity : 0), 2)];
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json([
            'scope' => $stay ? 'stay' : 'day',
            'date' => $day->toDateString(),
            'customer' => ['name' => $anchor->customer_name, 'phone' => $anchor->customer_phone, 'patient' => $anchor->patient()->first(['id', 'uhid', 'title', 'name'])],
            'admission' => $admission ? ['id' => $admission->id, 'ipd_no' => $admission->ipd_no, 'status' => $admission->status,
                'admitted_at' => $admission->admitted_at->format('Y-m-d H:i'), 'discharged_at' => $admission->discharged_at?->format('Y-m-d H:i')] : null,
            'sales' => $sales->map(fn (PharmacySale $s) => [
                'id' => $s->id, 'bill_no' => $s->bill_no, 'sold_at' => $s->sold_at->format('Y-m-d H:i'), 'seller' => $s->seller?->name,
                'total' => $s->total, 'returned' => $s->returned, 'paid' => $s->paid, 'ipd_transfer' => $s->ipd_transfer, 'due' => $s->due,
                'modes' => $s->payments->where('type', 'payment')->pluck('mode')->unique()->values()->all(),
                'items' => $s->items->map(fn (PharmacySaleItem $i) => ['id' => $i->id, 'name' => $i->medicine_name, 'batch_no' => $i->batch_no,
                    'quantity' => (int) $i->quantity, 'returned' => (int) $i->returned_quantity, 'unit_price' => (float) $i->unit_price, 'amount' => (float) $i->amount])->all(),
            ])->all(),
            'medicines' => $medicines,
            'billed' => round((float) $sales->sum('total') - (float) $sales->sum('returned'), 2), 'paid' => round((float) $sales->sum('paid'), 2),
            'on_ipd' => round((float) $sales->sum('ipd_transfer'), 2), 'due' => round((float) $sales->sum('due'), 2),
        ]);
    }

    /**
     * Takes one payment and puts it against several bills of one customer, in the order given.
     * Each bill gets its own payment line, exactly as if it had been paid on its own.
     */
    public function collectMany(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);
        $data = $request->validate([
            'sale_ids' => ['required', 'array', 'min:1', 'max:30'],
            'sale_ids.*' => ['integer', 'distinct'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(Payment::MODES)],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $sales = PharmacySale::whereIn('id', $data['sale_ids'])->lockForUpdate()->get()->keyBy('id');
        $first = $sales->first();
        $same = $first && $sales->every(fn (PharmacySale $s) => $s->patient_id === $first->patient_id
            && ($s->patient_id || ($s->customer_name === $first->customer_name && $s->customer_phone === $first->customer_phone)));
        if ($sales->count() !== count($data['sale_ids']) || ! $same) {
            throw ValidationException::withMessages(['sale_ids' => 'These bills are not all the same customer’s. Close this and open it again.']);
        }
        $sales->each(fn (PharmacySale $s) => $this->refuseIfVoided($s));
        $owed = round((float) $sales->sum('due'), 2);
        $left = round((float) $data['amount'], 2);
        if ($left > $owed + 0.004) {
            throw ValidationException::withMessages(['amount' => 'Only ₹'.rtrim(rtrim(number_format($owed, 2, '.', ''), '0'), '.').' is due on these bills.']);
        }

        foreach ($data['sale_ids'] as $id) {
            $sale = $sales[$id];
            $part = min($left, round((float) $sale->due, 2));
            if ($part <= 0) {
                continue;
            }
            $sale->payments()->create(['type' => 'payment', 'amount' => $part, 'mode' => $data['mode'], 'reference' => $data['reference'] ?? null, 'received_by' => $request->user()->id]);
            $sale->recalculatePaid();
            $left = round($left - $part, 2);
        }

        return response()->json(['paid' => round((float) $data['amount'], 2)]);
    }

    /** Takes money against a bill that was left part-paid or unpaid. */
    public function collect(Request $request, PharmacySale $sale): JsonResponse
    {
        $this->authorizePharmacy($request);
        $sale = $this->held($sale);
        $this->refuseIfVoided($sale);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(Payment::MODES)],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['amount'] > $sale->due) {
            throw ValidationException::withMessages(['amount' => "Only ₹{$sale->due} is due on this bill."]);
        }

        $sale->payments()->create($data + ['type' => 'payment', 'received_by' => $request->user()->id]);
        $sale->recalculatePaid();

        return response()->json($sale->fresh()->load(self::SALE_WITH), 201);
    }

    /**
     * Takes medicines back from the customer: the units go back into their batches and
     * any money paid beyond what the bill is now worth is refunded.
     */
    public function returnMedicines(Request $request, PharmacySale $sale): JsonResponse
    {
        $this->authorizePharmacy($request);
        $sale = $this->held($sale);
        $this->refuseIfVoided($sale);

        $data = $request->validate([
            'refund_mode' => ['required', Rule::in(Payment::MODES)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], ['items.required' => 'Choose at least one medicine to take back.']);

        DB::transaction(function () use ($data, $sale, $request) {
            $items = $sale->items()->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            $value = 0;

            foreach ($data['items'] as $index => $entry) {
                $item = $items->get($entry['id']);
                if (! $item) {
                    throw ValidationException::withMessages(["items.{$index}.id" => 'That medicine is not on this bill.']);
                }
                $left = $item->quantity - $item->returned_quantity;
                if ($entry['quantity'] > $left) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => "Only {$left} of {$item->medicine_name} can still be returned."]);
                }

                $amount = round($item->amount * $entry['quantity'] / $item->quantity, 2);
                $value += $amount;
                $lines[] = ['item_id' => $item->id, 'medicine_name' => $item->medicine_name, 'batch_no' => $item->batch_no, 'quantity' => $entry['quantity'], 'amount' => $amount];

                $item->increment('returned_quantity', $entry['quantity']);
                StockBatch::find($item->stock_batch_id)?->move($entry['quantity'], 'sale_return', $sale->bill_no);
            }

            // A bill-level discount was given on the whole bill, so the same share comes off what is handed back.
            $share = $sale->subtotal > 0 ? ($sale->subtotal - $sale->discount) / $sale->subtotal : 1;
            $value = round($value * $share, 2);

            $sale->returned = round($sale->returned + $value, 2);
            // What has been settled beyond what the bill is now worth. The part that was put on an IPD bill was never
            // paid at the counter, so it comes off the IPD bill first; only what is left over is handed back as money.
            $over = round($sale->paid + $sale->ipd_transfer - ($sale->total - $sale->returned), 2);
            $offIpd = $over > 0 && $sale->ipd_transfer > 0 ? $this->takeOffIpdBill($sale, min($over, (float) $sale->ipd_transfer), $request->user()->id) : 0.0;
            $sale->ipd_transfer = round($sale->ipd_transfer - $offIpd, 2);
            $worth = max(0, $sale->total - $sale->returned - $sale->ipd_transfer);
            $refund = round(max(0, $sale->paid - $worth), 2);
            $sale->save();

            if ($refund > 0) {
                $sale->payments()->create(['type' => 'refund', 'amount' => $refund, 'mode' => $data['refund_mode'], 'reference' => 'Medicine return', 'received_by' => $request->user()->id]);
                $sale->recalculatePaid();
            }

            $sale->returns()->create(['amount' => $value, 'refunded' => $refund, 'refund_mode' => $refund > 0 ? $data['refund_mode'] : null, 'lines' => $lines, 'created_by' => $request->user()->id]);
        });

        return response()->json($sale->fresh()->load(self::SALE_WITH), 201);
    }

    /** Cancels a bill made by mistake: everything goes back into stock and the money is refunded. The bill is kept, marked as deleted. */
    public function void(Request $request, PharmacySale $sale): JsonResponse
    {
        $this->authorizePharmacy($request);
        abort_unless($request->user()->allows('canDeleteBills'), 403, 'You are not allowed to delete bills.');
        $sale = $this->held($sale);
        $this->refuseIfVoided($sale);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'refund_mode' => ['nullable', Rule::in(Payment::MODES)],
        ], ['reason.required' => 'Say why this bill is being deleted.']);

        if ($sale->ipd_transfer > 0) {
            throw ValidationException::withMessages(['reason' => 'Part of this bill is on an IPD bill. Remove it from the IPD bill first.']);
        }

        DB::transaction(function () use ($sale, $data, $request) {
            foreach ($sale->items()->lockForUpdate()->get() as $item) {
                $back = $item->quantity - $item->returned_quantity;
                if ($back > 0) {
                    StockBatch::find($item->stock_batch_id)?->move($back, 'sale_void', $sale->bill_no, $data['reason']);
                    $item->update(['returned_quantity' => $item->quantity]);
                }
            }

            if ($sale->paid > 0) {
                $sale->payments()->create([
                    'type' => 'refund', 'amount' => $sale->paid, 'mode' => $data['refund_mode'] ?? $sale->payment_mode,
                    'reference' => 'Bill deleted', 'received_by' => $request->user()->id,
                ]);
                $sale->recalculatePaid();
            }

            $sale->update(['voided_at' => now(), 'voided_by' => $request->user()->id, 'void_reason' => $data['reason']]);
        });

        return response()->json($sale->fresh()->load(self::SALE_WITH));
    }

    /**
     * The bill as it stands this instant, held until the request is saved, so two requests arriving together
     * (a double click, or two counters) are taken one after the other and the second sees what the first did.
     */
    private function held(PharmacySale $sale): PharmacySale
    {
        return PharmacySale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Medicines taken back from a bill that was moved onto an inpatient's bill: the IPD bill gets a line
     * taking the amount off again, dated today, so earlier printed bills and closed days stay as they were.
     * Returns how much came off.
     */
    private function takeOffIpdBill(PharmacySale $sale, float $amount, int $userId): float
    {
        $charge = AdmissionCharge::where('source_type', 'pharmacy')->where('source_id', $sale->id)->latest('id')->lockForUpdate()->first();
        $admission = $charge ? Admission::whereKey($charge->admission_id)->lockForUpdate()->first() : null;
        if (! $admission) {
            return 0.0;
        }
        if ($admission->bill_approved_at) {
            throw ValidationException::withMessages(['items' => "This bill is on IPD bill {$admission->ipd_no}, which has been approved. Withdraw the approval there first, then take the medicines back."]);
        }
        if ($admission->total - $amount < $admission->discount) {
            throw ValidationException::withMessages(['items' => "This bill is on IPD bill {$admission->ipd_no}. Reduce the discount on that bill first; it would be more than the remaining charges."]);
        }

        $admission->charges()->create([
            'description' => "Medicines returned (pharmacy bill {$sale->bill_no})",
            'category' => $charge->category,
            'charged_on' => today(),
            'quantity' => 1,
            'unit_price' => -$amount,
            'amount' => -$amount,
            'created_by' => $userId,
            'source_type' => 'pharmacy_return',
            'source_id' => $sale->id,
        ]);
        $admission->recalculate();

        return $amount;
    }

    private function refuseIfVoided(PharmacySale $sale): void
    {
        if ($sale->voided_at) {
            throw ValidationException::withMessages(['sale' => 'This bill has been deleted.']);
        }
    }

    private function authorizePharmacy(Request $request): void
    {
        abort_unless($request->user()->allows('canRunPharmacy'), 403, 'You are not allowed to do this in the pharmacy.');
    }
}
