<?php

namespace App\Support;

use App\Models\Medicine;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\StockBatch;
use Illuminate\Support\Collection;

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
     * @return array{lines: list<array<string, mixed>>, extras: list<array<string, mixed>>, invoices: list<array<string, mixed>>, complete: bool}
     */
    public static function forOrder(PurchaseOrder $order): array
    {
        $order->loadMissing('items');
        $invoices = PurchaseInvoice::where('purchase_order_id', $order->id)->orderBy('invoice_date')->get(['id', 'invoice_no', 'invoice_date', 'total']);
        $received = StockBatch::whereIn('purchase_invoice_id', $invoices->pluck('id'))->with('medicine:id,name')->get()->groupBy('medicine_id');

        $lines = $order->items->map(function ($item) use ($received) {
            $got = (int) ($received[$item->medicine_id] ?? collect())->sum('packs');
            $free = (int) ($received[$item->medicine_id] ?? collect())->sum('free_packs');

            return [
                'medicine_id' => $item->medicine_id, 'medicine_name' => $item->medicine_name, 'units_per_pack' => $item->units_per_pack,
                'ordered' => (int) $item->packs, 'received' => $got, 'free' => $free,
                'state' => $got === 0 ? 'none' : ($got < $item->packs ? 'short' : ($got > $item->packs ? 'over' : 'full')),
            ];
        })->values();

        $ordered = $order->items->pluck('medicine_id')->flip();
        $extras = $received->reject(fn ($batches, $medicineId) => $ordered->has($medicineId))->map(fn (Collection $batches) => [
            'medicine_name' => $batches->first()->medicine?->name, 'received' => (int) $batches->sum('packs'), 'free' => (int) $batches->sum('free_packs'),
        ])->values();

        return [
            'lines' => $lines->all(), 'extras' => $extras->all(),
            'invoices' => $invoices->map(fn (PurchaseInvoice $p) => ['id' => $p->id, 'invoice_no' => $p->invoice_no, 'invoice_date' => $p->invoice_date->toDateString(), 'total' => $p->total])->all(),
            'complete' => $lines->isNotEmpty() && $lines->every(fn ($l) => $l['received'] >= $l['ordered']),
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
                } elseif ($line['state'] === 'short') {
                    $notes[] = "Ordered {$line['ordered']} packs; {$line['received']} received so far.";
                } elseif ($line['state'] === 'over') {
                    $notes[] = "Ordered {$line['ordered']} packs; {$line['received']} received.";
                }
            }
            if ($notes) {
                $flags[$batch->id] = $notes;
            }
        }

        return $flags;
    }
}
