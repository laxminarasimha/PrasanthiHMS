<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\Payment;
use App\Models\PharmacyChange;
use App\Models\PharmacyPayment;
use App\Models\PharmacySale;
use App\Models\PurchaseInvoice;
use App\Models\StockBatch;
use App\Models\Stockist;
use App\Models\StockMovement;
use App\Support\OrderMatch;
use App\Support\ShelfPlace;
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
     * Corrects how a payment or refund on a bill was taken, when it was entered under the wrong mode:
     * cash put down as UPI, and the like. The amount and the day stay as they are; the change is kept in the
     * Bill modified report with who made it and why.
     */
    public function updatePayment(Request $request, PharmacySale $sale, PharmacyPayment $payment): JsonResponse
    {
        $this->authorizePharmacy($request);
        abort_unless($payment->pharmacy_sale_id === $sale->id, 404);

        $data = $request->validate([
            'mode' => ['required', Rule::in(Payment::MODES)],
            'reference' => ['nullable', 'string', 'max:255'],
            'reason' => self::REASON,
        ], ['reason.required' => 'Say why the payment mode is being changed.']);

        if ($sale->voided_at) {
            throw ValidationException::withMessages(['mode' => 'This bill has been deleted.']);
        }
        if (! in_array($payment->mode, Payment::MODES, true)) {
            throw ValidationException::withMessages(['mode' => 'This entry is not money taken at the counter, so its mode cannot be changed.']);
        }
        $reference = $data['reference'] ?? null;
        if ($payment->mode === $data['mode'] && ($payment->reference ?? null) === $reference) {
            throw ValidationException::withMessages(['mode' => 'That is already how this payment is recorded.']);
        }

        DB::transaction(function () use ($sale, $payment, $data, $reference, $request) {
            $what = ($payment->type === 'refund' ? 'Refund' : 'Payment').' of ₹'.rtrim(rtrim(number_format($payment->amount, 2, '.', ''), '0'), '.').' on '.$payment->created_at->format('j M Y');
            $changes = PharmacyChange::differences(['mode' => 'mode', 'reference' => 'reference'], ['mode' => $payment->mode, 'reference' => $payment->reference], ['mode' => $data['mode'], 'reference' => $reference], "{$what} ");

            $payment->update(['mode' => $data['mode'], 'reference' => $reference]);
            // The bill itself carries the mode of the first money taken on it, which is what its print shows.
            $first = $sale->payments()->where('type', 'payment')->first();
            if ($first && $sale->payment_mode !== $first->mode) {
                $sale->update(['payment_mode' => $first->mode, 'reference' => $first->reference]);
            }

            $this->log('sale', $sale->id, $sale->bill_no, $changes, $data['reason'], $request);
        });

        return response()->json($sale->fresh()->load(['items', 'patient:id,uhid,title,name', 'seller:id,name', 'payments.receiver:id,name', 'returns']));
    }

    /**
     * Corrects a saved bill: customer details, the bill discount, and the quantity or discount of each line
     * (quantity 0 takes the line off). More medicines can be added to it, when the customer comes back for
     * something else, and the money for them taken in the same step. Stock follows the quantities, and money
     * paid beyond the new total is refunded.
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
            // Adding medicines explains itself; any other change to a saved bill needs a reason.
            'reason' => ['nullable', 'required_without:add', 'string', 'min:3', 'max:255'],
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'add' => ['sometimes', 'array', 'min:1', 'max:100'],
            'add.*.stock_batch_id' => ['required', 'integer', 'distinct', 'exists:stock_batches,id'],
            'add.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'add.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'collect' => ['nullable', 'numeric', 'min:0'],
            'collect_mode' => ['nullable', 'required_with:collect', Rule::in(Payment::MODES)],
            'collect_reference' => ['nullable', 'string', 'max:255'],
        ], [
            'reason.required_without' => 'Say why this bill is being changed.',
            'add.*.stock_batch_id.distinct' => 'The same batch is being added twice; combine the quantities.',
        ]);
        $reason = $data['reason'] ?? 'Medicines added to the bill';

        if ($sale->voided_at) {
            throw ValidationException::withMessages(['reason' => 'This bill has been deleted.']);
        }
        if ($sale->returned > 0 || $sale->items()->where('returned_quantity', '>', 0)->exists()) {
            throw ValidationException::withMessages(['reason' => 'Medicines were returned on this bill, so it can no longer be edited. Use a return for further changes.']);
        }
        if ($sale->ipd_transfer > 0) {
            throw ValidationException::withMessages(['reason' => 'Part of this bill is on an IPD bill. Remove it from the IPD bill first.']);
        }

        DB::transaction(function () use ($sale, $data, $reason, $request) {
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
                    $batch->move(-$extra, 'sale_edit', $sale->bill_no); // a negative number puts units back
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

            // Adding medicines explains itself. Changing what was already on the bill does not, even when something is added too.
            $corrected = (bool) $changes || round((float) ($data['discount'] ?? $sale->discount), 2) !== round((float) $sale->discount, 2);
            if ($corrected && blank($data['reason'] ?? null)) {
                throw ValidationException::withMessages(['reason' => 'Say why this bill is being changed.']);
            }

            // Medicines the customer came back for. A batch already on the bill grows; anything else becomes a new line at today's price.
            $adding = StockBatch::with('medicine:id,name')->whereIn('id', array_column($data['add'] ?? [], 'stock_batch_id'))->lockForUpdate()->get()->keyBy('id');
            foreach ($data['add'] ?? [] as $index => $entry) {
                $batch = $adding[$entry['stock_batch_id']];
                $name = $batch->medicine->name;
                $quantity = (int) $entry['quantity'];

                if ($batch->expiry->endOfDay()->isPast()) {
                    throw ValidationException::withMessages(["add.{$index}.stock_batch_id" => "{$name} batch {$batch->batch_no} has expired."]);
                }
                if ($quantity > $batch->units_in_stock) {
                    throw ValidationException::withMessages(["add.{$index}.quantity" => "Only {$batch->units_in_stock} of {$name} (batch {$batch->batch_no}) are in stock."]);
                }

                if ($line = $items->first(fn ($i) => $i->stock_batch_id === $batch->id)) {
                    $was = $line->quantity;
                    $line->update(['quantity' => $was + $quantity, 'amount' => round(($was + $quantity) * $line->unit_price * (1 - $line->discount_percent / 100), 2)]);
                    $changes[] = "{$name} quantity: {$was} → {$line->quantity}";
                } else {
                    $discount = (float) ($entry['discount_percent'] ?? 0);
                    $line = $sale->items()->create([
                        'medicine_id' => $batch->medicine_id, 'stock_batch_id' => $batch->id, 'medicine_name' => $name,
                        'batch_no' => $batch->batch_no, 'expiry' => $batch->expiry->toDateString(), 'quantity' => $quantity,
                        'unit_price' => $batch->unit_mrp, 'unit_cost' => $batch->effectiveUnitCost(), 'discount_percent' => $discount, 'gst_percent' => $batch->gst_percent,
                        'amount' => round($quantity * $batch->unit_mrp * (1 - $discount / 100), 2),
                    ]);
                    $items->put($line->id, $line);
                    $changes[] = "{$name} (batch {$batch->batch_no}): added, {$quantity} for ₹".(float) $line->amount;
                }
                $batch->move(-$quantity, 'sale_edit', $sale->bill_no);
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

            // Money taken now for what was added.
            $collect = round((float) ($data['collect'] ?? 0), 2);
            if ($collect > 0) {
                $due = round($total - $sale->paid, 2);
                if ($collect > $due) {
                    throw ValidationException::withMessages(['collect' => $due > 0 ? "Only ₹{$due} is due on this bill after the change." : 'Nothing is due on this bill after the change.']);
                }
                $sale->payments()->create(['type' => 'payment', 'amount' => $collect, 'mode' => $data['collect_mode'], 'reference' => $data['collect_reference'] ?? null, 'received_by' => $request->user()->id]);
                $sale->recalculatePaid();
                $changes[] = "Collected ₹{$collect} ({$data['collect_mode']})";
            }

            $this->log('sale', $sale->id, $sale->bill_no, $changes, $reason, $request);
        });

        return response()->json($sale->fresh()->load(['items', 'patient:id,uhid,title,name', 'seller:id,name', 'payments.receiver:id,name', 'returns']));
    }

    /**
     * Corrects a purchase invoice: its number, date, stockist, and each line's batch, expiry, packs and prices.
     * Changing the packs changes the stock by the same number of units. A medicine that was left out can be
     * added, and one entered by mistake taken off, as long as none of it has gone out yet.
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
            'items.*.box_no' => ['nullable', 'string', 'max:30'],
            'items.*.hsn_code' => ['nullable', 'string', 'max:20'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_on_free' => ['nullable', 'boolean'],
            // Lines entered by mistake, by their id on this invoice.
            'remove' => ['sometimes', 'array', 'max:200'],
            'remove.*' => ['integer', 'distinct'],
            // Medicines that were on the stockist's bill but were left out when it was typed in.
            'add' => ['sometimes', 'array', 'max:200'],
            'add.*.medicine_id' => ['required', 'exists:medicines,id'],
            'add.*.batch_no' => ['required', 'string', 'max:50'],
            'add.*.expiry' => ['required', 'date_format:Y-m'],
            'add.*.units_per_pack' => ['required', 'integer', 'min:1', 'max:10000'],
            'add.*.packs' => ['required', 'integer', 'min:1', 'max:100000'],
            'add.*.free_packs' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'add.*.pack_price' => ['required', 'numeric', 'min:0'],
            'add.*.pack_mrp' => ['required', 'numeric', 'gt:0'],
            'add.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'add.*.gst_percent' => ['required', 'numeric', 'min:0', 'max:40'],
            'add.*.rack_no' => ['nullable', 'string', 'max:30'],
            'add.*.box_no' => ['nullable', 'string', 'max:30'],
            'add.*.hsn_code' => ['nullable', 'string', 'max:20'],
            'add.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'add.*.tax_on_free' => ['nullable', 'boolean'],
            'other_charges' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'cash_discount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'round_off' => ['nullable', 'numeric', 'between:-10,10'],
        ], [
            'stockist_id.required' => 'Choose the stockist the invoice is from.',
            'invoice_no.unique' => 'This invoice number is already entered for this stockist.',
            'reason.required' => 'Say why this invoice is being changed.',
        ]);

        DB::transaction(function () use ($purchase, $data, $request) {
            $batches = $purchase->batches()->with('medicine:id,name')->lockForUpdate()->get()->keyBy('id');
            $purchase->load('stockist:id,name');
            $changes = PharmacyChange::differences(
                ['stockist' => 'Stockist', 'invoice_no' => 'Invoice no.', 'invoice_date' => 'Invoice date', 'remarks' => 'Remarks'],
                ['stockist' => $purchase->stockist->name, 'invoice_no' => $purchase->invoice_no, 'invoice_date' => $purchase->invoice_date->toDateString(), 'remarks' => $purchase->remarks],
                ['stockist' => Stockist::find($data['stockist_id'])->name, 'invoice_no' => $data['invoice_no'], 'invoice_date' => Carbon::parse($data['invoice_date'])->toDateString(), 'remarks' => $data['remarks'] ?? null],
            );

            $removing = array_map('intval', $data['remove'] ?? []);
            foreach ($data['items'] ?? [] as $index => $entry) {
                if (in_array((int) $entry['id'], $removing, true)) {
                    continue;
                }
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
                    'rack_no' => $entry['rack_no'] ?? null, 'box_no' => $entry['box_no'] ?? null, 'hsn_code' => $entry['hsn_code'] ?? null,
                    'discount_amount' => (float) ($entry['discount_amount'] ?? 0), 'tax_on_free' => (bool) ($entry['tax_on_free'] ?? false),
                ];
                if ($new['discount_amount'] > $new['packs'] * $new['pack_price'] * (1 - $new['discount_percent'] / 100)) {
                    throw ValidationException::withMessages(["items.{$index}.discount_amount" => "The discount on {$batch->medicine->name} is more than the line is worth."]);
                }
                array_push($changes, ...PharmacyChange::differences(
                    ['batch_no' => 'batch', 'expiry' => 'expiry', 'packs' => 'packs', 'free_packs' => 'free packs', 'pack_price' => 'pack price ₹', 'pack_mrp' => 'pack MRP ₹', 'discount_percent' => 'discount %', 'discount_amount' => 'discount ₹', 'gst_percent' => 'GST %', 'tax_on_free' => 'GST on free packs', 'hsn_code' => 'HSN', 'rack_no' => 'rack', 'box_no' => 'box'],
                    ['expiry' => $batch->expiry->toDateString()] + $batch->only(array_keys($new)),
                    $new,
                    $batch->medicine->name.' ',
                ));
                $change = $stock - $batch->units_in_stock;
                $batch->update($new + ['unit_mrp' => round($new['pack_mrp'] / $batch->units_per_pack, 2), 'units_received' => $units, 'units_in_stock' => $stock]);
                if ($change !== 0) {
                    StockMovement::record($batch, $change, 'purchase_edit', $data['invoice_no']);
                }
            }

            // A line entered by mistake comes off the invoice and out of stock. The batch itself stays, empty and no
            // longer tied to the invoice, so the stock record still shows that it came in and went out again.
            foreach ($removing as $index => $id) {
                $batch = $batches->get($id);
                if (! $batch) {
                    throw ValidationException::withMessages(["remove.{$index}" => 'That line is not on this invoice.']);
                }
                if ((int) $batch->units_in_stock !== (int) $batch->units_received) {
                    throw ValidationException::withMessages(["remove.{$index}" => "{$batch->medicine->name} batch {$batch->batch_no} cannot be taken off: {$batch->units_in_stock} of the {$batch->units_received} units that came in are left, so some have already been sold, returned or corrected. Lower its quantity instead, or make a stock return."]);
                }
                $units = (int) $batch->units_in_stock;
                $batch->move(-$units, 'purchase_edit', $data['invoice_no'], 'Line taken off the invoice');
                $batch->update(['purchase_invoice_id' => null, 'units_received' => 0]);
                $changes[] = "{$batch->medicine->name} batch {$batch->batch_no}: taken off the invoice ({$batch->packs} packs".($batch->free_packs ? " + {$batch->free_packs} free" : '').", {$units} units out of stock)";
            }

            foreach ($data['add'] ?? [] as $index => $item) {
                $medicine = Medicine::find($item['medicine_id']);
                $expiry = Carbon::createFromFormat('Y-m-d', $item['expiry'].'-01')->endOfMonth();
                if ($expiry->isPast()) {
                    throw ValidationException::withMessages(["add.{$index}.expiry" => "{$medicine->name}: the batch being added has already expired."]);
                }
                $free = (int) ($item['free_packs'] ?? 0);
                $discount = (float) ($item['discount_percent'] ?? 0);
                $units = ($item['packs'] + $free) * $item['units_per_pack'];
                if ((float) ($item['discount_amount'] ?? 0) > $item['packs'] * $item['pack_price'] * (1 - $discount / 100)) {
                    throw ValidationException::withMessages(["add.{$index}.discount_amount" => "The discount on {$medicine->name} is more than the line is worth."]);
                }
                $received = $purchase->batches()->create([
                    'medicine_id' => $medicine->id, 'batch_no' => $item['batch_no'], 'expiry' => $expiry->toDateString(),
                    'units_per_pack' => $item['units_per_pack'], 'packs' => $item['packs'], 'free_packs' => $free,
                    'pack_price' => $item['pack_price'], 'pack_mrp' => $item['pack_mrp'], 'unit_mrp' => round($item['pack_mrp'] / $item['units_per_pack'], 2),
                    'discount_percent' => $discount, 'gst_percent' => $item['gst_percent'], 'units_received' => $units, 'units_in_stock' => $units,
                    'rack_no' => $item['rack_no'] ?? null, 'box_no' => $item['box_no'] ?? null, 'hsn_code' => $item['hsn_code'] ?? null,
                    'discount_amount' => (float) ($item['discount_amount'] ?? 0), 'tax_on_free' => (bool) ($item['tax_on_free'] ?? false),
                ]);
                StockMovement::record($received, $units, 'purchase_edit', $data['invoice_no'], 'Medicine added to the invoice');
                $changes[] = "{$medicine->name} batch {$item['batch_no']}: added to the invoice ({$item['packs']} packs".($free ? " + {$free} free" : '').", {$units} units into stock)";
            }
            if (! $purchase->batches()->exists()) {
                throw ValidationException::withMessages(['remove' => 'An invoice must keep at least one medicine.']);
            }

            $extras = ['other_charges' => (float) ($data['other_charges'] ?? $purchase->other_charges), 'cash_discount' => (float) ($data['cash_discount'] ?? $purchase->cash_discount), 'round_off' => (float) ($data['round_off'] ?? $purchase->round_off)];
            array_push($changes, ...PharmacyChange::differences(['other_charges' => 'Other charges ₹', 'cash_discount' => 'Cash discount ₹', 'round_off' => 'Round off ₹'], $purchase->only(array_keys($extras)), $extras));
            $purchase->fill($extras);
            ['subtotal' => $subtotal, 'tax' => $tax, 'total' => $total] = $purchase->figures();
            if ($total < 0) {
                throw ValidationException::withMessages(['cash_discount' => 'The cash discount is more than the invoice is worth.']);
            }
            if ($purchase->paid > $total) {
                throw ValidationException::withMessages(['reason' => "₹{$purchase->paid} has already been paid on this invoice, which is more than the new total of ₹{$total}."]);
            }
            array_push($changes, ...PharmacyChange::differences(['total' => 'Invoice total ₹'], ['total' => $purchase->total], ['total' => $total]));
            if (! $changes) {
                throw ValidationException::withMessages(['reason' => 'Nothing was changed.']);
            }

            $purchase->update([
                'stockist_id' => $data['stockist_id'], 'invoice_no' => $data['invoice_no'], 'invoice_date' => $data['invoice_date'], 'remarks' => $data['remarks'] ?? null,
                'subtotal' => round($subtotal, 2), 'tax' => round($tax, 2), 'total' => $total] + $extras + [
                ]);
            $this->log('invoice', $purchase->id, $purchase->invoice_no, $changes, $data['reason'], $request);
            // A medicine added late may be the one the order was still waiting for.
            OrderMatch::settle($purchase->order);
        });

        return response()->json($purchase->fresh()->present());
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
            'box_no' => ['sometimes', 'nullable', 'string', 'max:30'],
            'reason' => self::REASON,
        ], ['reason.required' => 'Say why the batch details are being changed.']);

        $new = [
            'batch_no' => $data['batch_no'],
            'expiry' => Carbon::createFromFormat('Y-m-d', $data['expiry'].'-01')->endOfMonth()->toDateString(),
            'pack_mrp' => (float) $data['pack_mrp'], 'rack_no' => ShelfPlace::tidy($data['rack_no'] ?? null),
        ];
        // The box is only touched when the form sent one, so an older screen cannot wipe it.
        if (array_key_exists('box_no', $data)) {
            $new['box_no'] = $new['rack_no'] === null ? null : ShelfPlace::tidy($data['box_no']);
        }
        $batch->load('medicine:id,name');
        $changes = PharmacyChange::differences(
            ['batch_no' => 'Batch', 'expiry' => 'Expiry', 'pack_mrp' => 'Pack MRP ₹', 'rack_no' => 'Rack', 'box_no' => 'Box'],
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
        abort_unless($request->user()->mayInPharmacy($request), 403, 'You are not allowed to do this in the pharmacy.');
    }
}
