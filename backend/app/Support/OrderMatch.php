<?php

namespace App\Support;

use App\Models\Medicine;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\StockBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sets what was ordered beside what the supplier sent and billed: quantities short or over,
 * medicines that were never ordered, and rates, MRPs or GST that differ from last time.
 */
class OrderMatch
{
    /** A rate has to move by more than this share before it is pointed out. */
    private const RATE_CHANGE = 0.05;

    /**
     * An order line by line: packs ordered, packs received and billed, free packs, and what came without being ordered.
     *
     * A line is matched in units, as the stockist may send another pack size: 4 packs of 25 answer an order of 10
     * packs of 10. What is still to come is told in the order's own packs, rounded up. The packs received are the
     * paid packs as billed, whatever their size.
     *
     * @return array{lines: list<array<string, mixed>>, extras: list<array<string, mixed>>, invoices: list<array<string, mixed>>, complete: bool}
     */
    public static function forOrder(PurchaseOrder $order): array
    {
        $order->loadMissing('items');
        $invoices = PurchaseInvoice::where('purchase_order_id', $order->id)->orderBy('invoice_date')->get(['id', 'invoice_no', 'invoice_date', 'total']);
        $received = StockBatch::whereIn('purchase_invoice_id', $invoices->pluck('id'))->with('medicine:id,name')->get()->groupBy('medicine_id');

        $lines = $order->items->map(function ($item) use ($received) {
            $batches = $received[$item->medicine_id] ?? collect();
            $got = (int) $batches->sum('packs');
            $free = (int) $batches->sum('free_packs');
            // Free packs come on top of an order, so only the paid packs count towards what was ordered.
            $gotUnits = (int) $batches->sum(fn (StockBatch $b) => (int) $b->packs * (int) $b->units_per_pack);
            $perPack = max(1, (int) $item->units_per_pack);
            $orderedUnits = (int) $item->packs * $perPack;

            return [
                'medicine_id' => $item->medicine_id, 'medicine_name' => $item->medicine_name, 'units_per_pack' => $item->units_per_pack,
                'ordered' => (int) $item->packs, 'received' => $got, 'free' => $free,
                'ordered_units' => $orderedUnits, 'received_units' => $gotUnits,
                'to_come' => (int) ceil(max(0, $orderedUnits - $gotUnits) / $perPack),
                'state' => $gotUnits === 0 ? 'none' : ($gotUnits < $orderedUnits ? 'short' : ($gotUnits > $orderedUnits ? 'over' : 'full')),
            ];
        })->values();

        $ordered = $order->items->pluck('medicine_id')->flip();
        $extras = $received->reject(fn ($batches, $medicineId) => $ordered->has($medicineId))->map(fn (Collection $batches) => [
            'medicine_name' => $batches->first()->medicine?->name, 'received' => (int) $batches->sum('packs'), 'free' => (int) $batches->sum('free_packs'),
        ])->values();

        return [
            'lines' => $lines->all(), 'extras' => $extras->all(),
            'invoices' => $invoices->map(fn (PurchaseInvoice $p) => ['id' => $p->id, 'invoice_no' => $p->invoice_no, 'invoice_date' => $p->invoice_date->toDateString(), 'total' => $p->total])->all(),
            'complete' => $lines->isNotEmpty() && $lines->every(fn ($l) => $l['received_units'] >= $l['ordered_units']),
        ];
    }

    /** Marks an open order received once every line on it has come in full. */
    public static function settle(?PurchaseOrder $order): void
    {
        if ($order && $order->status === 'open' && self::forOrder($order)['complete']) {
            $order->update(['status' => 'received']);
        }
    }

    /**
     * What is still to come on an open order, one line for each medicine, ready to fill a new invoice from the
     * stockist: the order's pack size, the packs not received yet (matched in units, see forOrder), and the
     * medicine's GST, HSN code and place.
     * Each line also says what the medicine was last bought at in the same pack size, from this stockist if it
     * ever was, else from anyone: a hint to show beside the boxes, never a figure to save. Nothing is written.
     *
     * @return list<array<string, mixed>>
     */
    public static function stillToCome(PurchaseOrder $order): array
    {
        if ($order->status !== 'open') {
            return [];
        }
        $lines = collect(self::forOrder($order)['lines'])->where('to_come', '>', 0)->values();
        if ($lines->isEmpty()) {
            return [];
        }
        $ids = $lines->pluck('medicine_id')->all();
        $medicines = Medicine::whereIn('id', $ids)->get(['id', 'name', 'gst_percent', 'hsn_code', 'rack_no', 'box_no', 'is_active'])->keyBy('id');
        // Every purchase of these medicines on an invoice, newest first, so the first one that fits is the last time.
        $bought = DB::table('stock_batches as b')->join('purchase_invoices as i', 'i.id', '=', 'b.purchase_invoice_id')
            ->whereIn('b.medicine_id', $ids)->orderByDesc('i.invoice_date')->orderByDesc('b.id')
            ->get(['b.medicine_id', 'b.units_per_pack', 'b.pack_price', 'b.pack_mrp', 'i.stockist_id', 'i.invoice_date'])->groupBy('medicine_id');

        return $lines->map(function (array $line) use ($medicines, $bought, $order) {
            $medicine = $medicines[$line['medicine_id']] ?? null;
            // A rate for another pack size says nothing about this one.
            $same = ($bought[$line['medicine_id']] ?? collect())->filter(fn ($b) => (int) $b->units_per_pack === (int) $line['units_per_pack']);
            $fromThem = $same->first(fn ($b) => (int) $b->stockist_id === (int) $order->stockist_id);
            $last = $fromThem ?? $same->first();

            return [
                'medicine_id' => $line['medicine_id'], 'medicine_name' => $medicine?->name ?? $line['medicine_name'],
                'units_per_pack' => (int) $line['units_per_pack'], 'packs' => $line['to_come'], 'ordered' => $line['ordered'], 'received' => $line['received'],
                // The packs are rounded up when another pack size came before; this is exactly what is left.
                'units_to_come' => max(0, $line['ordered_units'] - $line['received_units']),
                'gst_percent' => $medicine?->gst_percent, 'hsn_code' => $medicine?->hsn_code, 'rack_no' => $medicine?->rack_no, 'box_no' => $medicine?->box_no,
                'is_active' => (bool) $medicine?->is_active,
                'last_rate' => $last ? (float) $last->pack_price : null, 'last_mrp' => $last ? (float) $last->pack_mrp : null,
                'last_on' => $last ? substr((string) $last->invoice_date, 0, 10) : null, 'last_from_this_stockist' => $fromThem !== null,
            ];
        })->all();
    }

    /**
     * What to look at on each line of an invoice, keyed by batch.
     *
     * @return array<int, list<string>>
     */
    public static function flags(PurchaseInvoice $invoice): array
    {
        $invoice->loadMissing(['batches', 'order.items']);
        $rupees = fn (float $amount) => '₹'.number_format($amount, 2);
        $medicines = Medicine::whereIn('id', $invoice->batches->pluck('medicine_id'))->get(['id', 'gst_percent'])->keyBy('id');
        // The last time each medicine was bought before this invoice.
        $earlier = StockBatch::whereIn('medicine_id', $invoice->batches->pluck('medicine_id'))->whereNotNull('purchase_invoice_id')
            ->where('purchase_invoice_id', '!=', $invoice->id)->where('id', '<', $invoice->batches->min('id') ?? 0)
            ->orderByDesc('id')->get(['id', 'medicine_id', 'pack_price', 'pack_mrp', 'units_per_pack'])->unique('medicine_id')->keyBy('medicine_id');
        $match = $invoice->order ? collect(self::forOrder($invoice->order)['lines'])->keyBy('medicine_id') : null;

        $flags = [];
        foreach ($invoice->batches as $batch) {
            $notes = [];
            $before = $earlier[$batch->medicine_id] ?? null;
            if ($before && (int) $before->units_per_pack === (int) $batch->units_per_pack) {
                if ($before->pack_price > 0 && abs($batch->pack_price - $before->pack_price) / $before->pack_price > self::RATE_CHANGE) {
                    $change = round(($batch->pack_price - $before->pack_price) / $before->pack_price * 100);
                    $notes[] = 'Rate '.$rupees((float) $batch->pack_price).' a pack; last time '.$rupees((float) $before->pack_price).' ('.($change > 0 ? '+' : '').$change.'%).';
                }
                if ($batch->pack_mrp < $before->pack_mrp) {
                    $notes[] = 'MRP '.$rupees((float) $batch->pack_mrp).'; last time '.$rupees((float) $before->pack_mrp).'.';
                }
            }
            $listed = $medicines[$batch->medicine_id]->gst_percent ?? null;
            if ($listed !== null && (float) $listed !== (float) $batch->gst_percent) {
                $notes[] = 'GST '.(float) $batch->gst_percent.'%; the medicine list says '.(float) $listed.'%.';
            }
            if ($match) {
                $line = $match[$batch->medicine_id] ?? null;
                if (! $line) {
                    $notes[] = "Not on order {$invoice->order->order_no}.";
                } else {
                    $perPack = (int) $line['units_per_pack'];
                    if ((int) $batch->units_per_pack !== $perPack) {
                        $notes[] = "Came in packs of {$batch->units_per_pack}; the order is for packs of {$perPack}.";
                    }
                    // Told in packs while every pack that came is of the order's size, else in units.
                    $said = $line['received_units'] === $line['received'] * $perPack
                        ? "Ordered {$line['ordered']} packs; {$line['received']} received"
                        : "Ordered {$line['ordered_units']} units; {$line['received_units']} received";
                    if ($line['state'] === 'short') {
                        $notes[] = "{$said} so far.";
                    } elseif ($line['state'] === 'over') {
                        $notes[] = "{$said}.";
                    }
                }
            }
            if ($notes) {
                $flags[$batch->id] = $notes;
            }
        }

        return $flags;
    }
}
