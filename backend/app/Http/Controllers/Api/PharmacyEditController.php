<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PharmacyChange;
use App\Models\PharmacySale;
use App\Models\PurchaseInvoice;
use App\Models\StockBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Corrections to pharmacy records after they were saved. Every correction needs a reason and is
 * written to the change log, which the "modified" reports read.
 */
class PharmacyEditController extends Controller
{
    private const REASON = ['required', 'string', 'min:3', 'max:255'];

    /**
     * Corrects a saved bill: customer details, the bill discount, and the quantity or discount of each line
     * (quantity 0 takes the line off). Stock follows the quantities, and money paid beyond the new total is refunded.
     */
    public function updateSale(Request $request, PharmacySale $sale): JsonResponse
    {
        $this->authorizePharmacy($request);

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'referred_doctor' => ['nullable', 'string', 'max:255'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'refund_mode' => ['nullable', Rule::in(Payment::MODES)],
            'reason' => self::REASON,
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], ['reason.required' => 'Say why this bill is being changed.']);

        if ($sale->voided_at) {
            throw ValidationException::withMessages(['reason' => 'This bill has been deleted.']);
        }
        if ($sale->returned > 0 || $sale->items()->where('returned_quantity', '>', 0)->exists()) {
            throw ValidationException::withMessages(['reason' => 'Medicines were returned on this bill, so it can no longer be edited. Use a return for further changes.']);
        }
        if ($sale->ipd_transfer > 0) {
            throw ValidationException::withMessages(['reason' => 'Part of this bill is on an IPD bill. Remove it from the IPD bill first.']);
        }

        DB::transaction(function () use ($sale, $data, $request) {
            $items = $sale->items()->lockForUpdate()->get()->keyBy('id');
            $batches = StockBatch::whereIn('id', $items->pluck('stock_batch_id'))->lockForUpdate()->get()->keyBy('id');
            $changes = PharmacyChange::differences(
                ['customer_name' => 'Customer', 'customer_phone' => 'Phone', 'referred_doctor' => 'Doctor'],
                $sale->only(['customer_name', 'customer_phone', 'referred_doctor']),
                $data,
            );

            foreach ($data['items'] ?? [] as $index => $entry) {
                $item = $items->get($entry['id']);
                if (! $item) {
                    throw ValidationException::withMessages(["items.{$index}.id" => 'That medicine is not on this bill.']);
                }
                $quantity = (int) $entry['quantity'];
                $discount = (float) ($entry['discount_percent'] ?? $item->discount_percent);
                $extra = $quantity - $item->quantity;
                $batch = $batches->get($item->stock_batch_id);

                if ($extra > 0) {
                    if (! $batch || $batch->expiry->endOfDay()->isPast()) {
                        throw ValidationException::withMessages(["items.{$index}.quantity" => "{$item->medicine_name} batch {$item->batch_no} has expired; more of it cannot be sold."]);
                    }
                    if ($extra > $batch->units_in_stock) {
                        throw ValidationException::withMessages(["items.{$index}.quantity" => "Only {$batch->units_in_stock} more of {$item->medicine_name} (batch {$item->batch_no}) are in stock."]);
                    }
                }
                if ($extra !== 0 && $batch) {
                    $batch->decrement('units_in_stock', $extra); // a negative number puts units back
                }

                if ($quantity === 0) {
                    $changes[] = "{$item->medicine_name}: removed ({$item->quantity} back into stock)";
                    $item->delete();
                    $items->forget($item->id);

                    continue;
                }

                array_push($changes, ...PharmacyChange::differences(
                    ['quantity' => 'quantity', 'discount_percent' => 'discount %'],
                    ['quantity' => $item->quantity, 'discount_percent' => $item->discount_percent],
                    ['quantity' => $quantity, 'discount_percent' => $discount],
                    $item->medicine_name.' ',
                ));
                $item->update(['quantity' => $quantity, 'discount_percent' => $discount, 'amount' => round($quantity * $item->unit_price * (1 - $discount / 100), 2)]);
            }

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'A bill needs at least one medicine. Delete the bill instead.']);
            }

            $subtotal = round($items->sum('amount'), 2);
            // MRP already includes GST, so the tax is the part contained in each amount.
            $tax = $items->sum(fn ($i) => $i->amount - $i->amount / (1 + $i->gst_percent / 100));
            $discount = round((float) ($data['discount'] ?? $sale->discount), 2);
            if ($discount > $subtotal) {
                throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the bill amount.']);
            }
            $net = $subtotal - $discount;
            $total = round($net);

            array_push($changes, ...PharmacyChange::differences(
                ['discount' => 'Bill discount ₹', 'total' => 'Bill total ₹'],
                ['discount' => $sale->discount, 'total' => $sale->total],
                ['discount' => $discount, 'total' => (float) $total],
            ));
            if (! $changes) {
                throw ValidationException::withMessages(['reason' => 'Nothing was changed.']);
            }

            $sale->update([
                'customer_name' => $data['customer_name'], 'customer_phone' => $data['customer_phone'] ?? null, 'referred_doctor' => $data['referred_doctor'] ?? null,
                'subtotal' => $subtotal, 'discount' => $discount, 'tax' => round($subtotal > 0 ? $tax * ($net / $subtotal) : 0, 2),
                'round_off' => round($total - $net, 2), 'total' => $total,
            ]);

            $refund = round($sale->paid - $total, 2);
            if ($refund > 0) {
                $mode = $data['refund_mode'] ?? $sale->payment_mode;
                $sale->payments()->create(['type' => 'refund', 'amount' => $refund, 'mode' => $mode, 'reference' => 'Bill edited', 'received_by' => $request->user()->id]);
                $sale->recalculatePaid();
                $changes[] = "Refunded ₹{$refund} ({$mode})";
            }

            $this->log('sale', $sale->id, $sale->bill_no, $changes, $data['reason'], $request);
        });

        return response()->json($sale->fresh()->load(['items', 'patient:id,uhid,name', 'seller:id,name', 'payments.receiver:id,name', 'returns']));
    }

    /**
     * Corrects a purchase invoice: its number, date, stockist, and each line's batch, expiry, packs and prices.
     * Changing the packs changes the stock by the same number of units.
     */
    public function updatePurchase(Request $request, PurchaseInvoice $purchase): JsonResponse
    {
        $this->authorizePharmacy($request);

        $data = $request->validate([
            'stockist_id' => ['required', 'exists:stockists,id'],
            'invoice_no' => ['required', 'string', 'max:50', Rule::unique('purchase_invoices')->where('stockist_id', $request->input('stockist_id'))->ignore($purchase)],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'reason' => self::REASON,
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.batch_no' => ['required', 'string', 'max:50'],
            'items.*.expiry' => ['required', 'date_format:Y-m'],
            'items.*.packs' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.free_packs' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'items.*.pack_price' => ['required', 'numeric', 'min:0'],
            'items.*.pack_mrp' => ['required', 'numeric', 'gt:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.gst_percent' => ['required', 'numeric', 'min:0', 'max:40'],
            'items.*.rack_no' => ['nullable', 'string', 'max:30'],
        ], [
            'invoice_no.unique' => 'This invoice number is already entered for this stockist.',
            'reason.required' => 'Say why this invoice is being changed.',
        ]);

        DB::transaction(function () use ($purchase, $data, $request) {
            $batches = $purchase->batches()->with('medicine:id,name')->lockForUpdate()->get()->keyBy('id');
            $purchase->load('stockist:id,name');
            $changes = PharmacyChange::differences(
                ['stockist' => 'Stockist', 'invoice_no' => 'Invoice no.', 'invoice_date' => 'Invoice date', 'remarks' => 'Remarks'],
                ['stockist' => $purchase->stockist->name, 'invoice_no' => $purchase->invoice_no, 'invoice_date' => $purchase->invoice_date->toDateString(), 'remarks' => $purchase->remarks],
                ['stockist' => \App\Models\Stockist::find($data['stockist_id'])->name, 'invoice_no' => $data['invoice_no'], 'invoice_date' => Carbon::parse($data['invoice_date'])->toDateString(), 'remarks' => $data['remarks'] ?? null],
            );

            foreach ($data['items'] ?? [] as $index => $entry) {
                $batch = $batches->get($entry['id']);
                if (! $batch) {
                    throw ValidationException::withMessages(["items.{$index}.id" => 'That line is not on this invoice.']);
                }
                $free = (int) ($entry['free_packs'] ?? 0);
                $units = ($entry['packs'] + $free) * $batch->units_per_pack;
                $stock = $batch->units_in_stock + $units - $batch->units_received;
                if ($stock < 0) {
                    $gone = $batch->units_received - $batch->units_in_stock;
                    throw ValidationException::withMessages(["items.{$index}.packs" => "{$gone} units of {$batch->medicine->name} from this batch have already gone out, so it cannot be fewer than that."]);
                }

                $new = [
                    'batch_no' => $entry['batch_no'],
                    'expiry' => Carbon::createFromFormat('Y-m-d', $entry['expiry'].'-01')->endOfMonth()->toDateString(),
                    'packs' => (int) $entry['packs'], 'free_packs' => $free,
                    'pack_price' => (float) $entry['pack_price'], 'pack_mrp' => (float) $entry['pack_mrp'],
                    'discount_percent' => (float) ($entry['discount_percent'] ?? 0), 'gst_percent' => (float) $entry['gst_percent'],
                    'rack_no' => $entry['rack_no'] ?? null,
                ];
                array_push($changes, ...PharmacyChange::differences(
                    ['batch_no' => 'batch', 'expiry' => 'expiry', 'packs' => 'packs', 'free_packs' => 'free packs', 'pack_price' => 'pack price ₹', 'pack_mrp' => 'pack MRP ₹', 'discount_percent' => 'discount %', 'gst_percent' => 'GST %', 'rack_no' => 'rack'],
                    ['expiry' => $batch->expiry->toDateString()] + $batch->only(array_keys($new)),
                    $new,
                    $batch->medicine->name.' ',
                ));
                $batch->update($new + ['unit_mrp' => round($new['pack_mrp'] / $batch->units_per_pack, 2), 'units_received' => $units, 'units_in_stock' => $stock]);
            }

            $subtotal = $tax = 0;
            foreach ($batches as $batch) {
                // The stockist charges for the paid packs only, less any trade discount, plus GST.
                $line = round($batch->packs * $batch->pack_price * (1 - $batch->discount_percent / 100), 2);
                $subtotal += $line;
                $tax += round($line * $batch->gst_percent / 100, 2);
            }
            $total = round($subtotal + $tax, 2);
            if ($purchase->paid > $total) {
                throw ValidationException::withMessages(['reason' => "₹{$purchase->paid} has already been paid on this invoice, which is more than the new total of ₹{$total}."]);
            }
            array_push($changes, ...PharmacyChange::differences(['total' => 'Invoice total ₹'], ['total' => $purchase->total], ['total' => $total]));
            if (! $changes) {
                throw ValidationException::withMessages(['reason' => 'Nothing was changed.']);
            }

            $purchase->update([
                'stockist_id' => $data['stockist_id'], 'invoice_no' => $data['invoice_no'], 'invoice_date' => $data['invoice_date'], 'remarks' => $data['remarks'] ?? null,
                'subtotal' => round($subtotal, 2), 'tax' => round($tax, 2), 'total' => $total,
            ]);
            $this->log('invoice', $purchase->id, $purchase->invoice_no, $changes, $data['reason'], $request);
        });

        return response()->json($purchase->fresh()->load(['stockist', 'batches.medicine:id,name']));
    }

    /** Corrects what is written on a batch: its number, expiry, MRP and rack. The count is changed with a stock adjustment instead. */
    public function updateBatch(Request $request, StockBatch $batch): JsonResponse
    {
        $this->authorizePharmacy($request);

        $data = $request->validate([
            'batch_no' => ['required', 'string', 'max:50'],
            'expiry' => ['required', 'date_format:Y-m'],
            'pack_mrp' => ['required', 'numeric', 'gt:0'],
            'rack_no' => ['nullable', 'string', 'max:30'],
            'reason' => self::REASON,
        ], ['reason.required' => 'Say why the batch details are being changed.']);

        $new = [
            'batch_no' => $data['batch_no'],
            'expiry' => Carbon::createFromFormat('Y-m-d', $data['expiry'].'-01')->endOfMonth()->toDateString(),
            'pack_mrp' => (float) $data['pack_mrp'], 'rack_no' => $data['rack_no'] ?? null,
        ];
        $batch->load('medicine:id,name');
        $changes = PharmacyChange::differences(
            ['batch_no' => 'Batch', 'expiry' => 'Expiry', 'pack_mrp' => 'Pack MRP ₹', 'rack_no' => 'Rack'],
            ['expiry' => $batch->expiry->toDateString()] + $batch->only(array_keys($new)),
            $new,
        );
        if (! $changes) {
            throw ValidationException::withMessages(['reason' => 'Nothing was changed.']);
        }

        $label = "{$batch->medicine->name} · batch {$batch->batch_no}";
        $batch->update($new + ['unit_mrp' => round($new['pack_mrp'] / max(1, $batch->units_per_pack), 2)]);
        $this->log('stock', $batch->id, $label, $changes, $data['reason'], $request);

        return response()->json($batch->fresh()->load(['medicine:id,name,reorder_level', 'invoice:id,invoice_no']));
    }

    /**
     * @param  array<int, string>  $changes
     */
    private function log(string $kind, int $id, string $label, array $changes, ?string $reason, Request $request): void
    {
        PharmacyChange::create(['kind' => $kind, 'subject_id' => $id, 'label' => $label, 'summary' => implode("\n", $changes), 'reason' => $reason, 'created_by' => $request->user()->id]);
    }

    private function authorizePharmacy(Request $request): void
    {
        abort_unless($request->user()->allows('canRunPharmacy'), 403, 'You are not allowed to do this in the pharmacy.');
    }
}
