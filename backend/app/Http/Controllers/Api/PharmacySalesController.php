<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            'latest_sales' => (clone $sales)->latest('sold_at')->latest('id')->limit(10)->get(['id', 'bill_no', 'customer_name', 'sold_at', 'total', 'returned', 'paid', 'ipd_transfer', 'voided_at']),
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

    /** Takes money against a bill that was left part-paid or unpaid. */
    public function collect(Request $request, PharmacySale $sale): JsonResponse
    {
        $this->authorizePharmacy($request);
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
                StockBatch::whereKey($item->stock_batch_id)->increment('units_in_stock', $entry['quantity']);
            }

            // A bill-level discount was given on the whole bill, so the same share comes off what is handed back.
            $share = $sale->subtotal > 0 ? ($sale->subtotal - $sale->discount) / $sale->subtotal : 1;
            $value = round($value * $share, 2);

            $sale->returned = round($sale->returned + $value, 2);
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
                    StockBatch::whereKey($item->stock_batch_id)->increment('units_in_stock', $back);
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
