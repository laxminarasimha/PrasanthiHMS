<?php

namespace App\Support;

use App\Models\Medicine;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchasePayment;
use App\Models\StockBatch;
use App\Models\Stockist;
use App\Models\StockReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The pharmacy manager's morning figures: what to order and how much, what is about to expire,
 * which supplier bills fall due, and which orders and invoices do not match. The rules are kept
 * simple enough to explain to the pharmacist, and are spelt out on the screen.
 */
class PharmacyDesk
{
    /** Stock that expires within this many days is not counted as cover: it may not sell in time. */
    public const PROTECT_DAYS = 30;

    /** Sales of this many days decide how fast a medicine moves. */
    public const SALES_DAYS = 30;

    /** Days of sales kept as a cushion on top of the supplier's delivery time. */
    public const SAFETY_DAYS = 3;

    /** Days of sales an order should cover once it arrives. */
    public const COVER_DAYS = 15;

    public const DEFAULT_LEAD_DAYS = 2;

    public const EXPIRY_WATCH_DAYS = 90;

    public const SLOW_DAYS = 90;

    /**
     * Every medicine that needs ordering, most pressing first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function reorder(): Collection
    {
        $today = today();
        $protect = $today->copy()->addDays(self::PROTECT_DAYS)->toDateString();

        $stock = StockBatch::where('units_in_stock', '>', 0)->whereDate('expiry', '>=', $today)
            ->selectRaw('medicine_id, sum(case when expiry > ? then units_in_stock else 0 end) as usable, sum(case when expiry <= ? then units_in_stock else 0 end) as near', [$protect, $protect])
            ->groupBy('medicine_id')->get()->keyBy('medicine_id');

        $sold = DB::table('pharmacy_sale_items as i')->join('pharmacy_sales as s', 's.id', '=', 'i.pharmacy_sale_id')
            ->whereNull('s.voided_at')->where('s.sold_at', '>=', $today->copy()->subDays(self::SALES_DAYS))
            ->selectRaw('i.medicine_id, sum(i.quantity - i.returned_quantity) as units')->groupBy('i.medicine_id')->pluck('units', 'medicine_id');

        $onOrder = DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->whereIn('o.status', ['draft', 'open'])
            ->selectRaw('i.medicine_id, sum(i.packs * i.units_per_pack) as units')->groupBy('i.medicine_id')->pluck('units', 'medicine_id');

        // Whom each medicine was last bought from, and at what price, for medicines with no supplier chosen.
        $last = DB::table('stock_batches as b')->join('purchase_invoices as p', 'p.id', '=', 'b.purchase_invoice_id')
            ->orderByDesc('b.id')->get(['b.medicine_id', 'p.stockist_id', 'b.pack_price', 'b.units_per_pack'])->unique('medicine_id')->keyBy('medicine_id');
        $stockists = Stockist::get(['id', 'name', 'lead_days'])->keyBy('id');
        $stocked = self::everStocked();

        return Medicine::where('is_active', true)->get(['id', 'name', 'units_per_pack', 'reorder_level', 'desired_stock', 'preferred_stockist_id'])
            ->map(function (Medicine $m) use ($stock, $sold, $onOrder, $last, $stockists, $stocked) {
                $usable = (int) ($stock[$m->id]->usable ?? 0);
                $near = (int) ($stock[$m->id]->near ?? 0);
                $ordered = (int) ($onOrder[$m->id] ?? 0);
                $sold30 = max(0, (int) ($sold[$m->id] ?? 0));
                $daily = $sold30 / self::SALES_DAYS;
                // A medicine that has never been on the shelf is not "out of stock": it is simply not kept yet.
                if (! isset($stocked[$m->id]) && $sold30 === 0) {
                    return null;
                }
                $stockist = $stockists[$m->preferred_stockist_id ?? ($last[$m->id]->stockist_id ?? 0)] ?? null;
                $lead = $stockist ? (int) $stockist->lead_days : self::DEFAULT_LEAD_DAYS;

                // The shelf should never go below "minimum"; an order fills it up to "desired".
                $minimum = $m->reorder_level > 0 ? (int) $m->reorder_level : (int) ceil($daily * ($lead + self::SAFETY_DAYS));
                $desired = $m->desired_stock > 0 ? (int) $m->desired_stock : ($daily > 0 ? $minimum + (int) ceil($daily * self::COVER_DAYS) : $minimum * 2);
                $cover = $daily > 0 ? $usable / $daily : null;

                $class = match (true) {
                    $usable === 0 && ($daily > 0 || $m->reorder_level > 0) => 'out',
                    $cover !== null && $cover < $lead => 'urgent',
                    $minimum > 0 && $usable + $ordered <= $minimum => 'low',
                    default => null,
                };
                if ($class === null) {
                    return null;
                }

                $units = max(0, $desired - $usable - $ordered);
                $perPack = max(1, (int) $m->units_per_pack);
                $packs = (int) ceil($units / $perPack);
                $price = isset($last[$m->id]) ? (float) $last[$m->id]->pack_price : null;

                return [
                    'medicine_id' => $m->id, 'name' => $m->name, 'units_per_pack' => $perPack, 'class' => $class,
                    'usable' => $usable, 'near_expiry' => $near, 'on_order' => $ordered,
                    'sold_30' => $sold30, 'days_cover' => $cover === null ? null : (int) floor($cover),
                    'minimum' => $minimum, 'desired' => $desired, 'lead_days' => $lead,
                    'suggest_packs' => $packs,
                    'stockist_id' => $stockist?->id, 'stockist' => $stockist?->name,
                    'last_pack_price' => $price, 'est_cost' => $price !== null ? round($price * $packs, 2) : null,
                ];
            })->filter()
            ->sortBy([fn ($a, $b) => array_search($a['class'], ['out', 'urgent', 'low']) <=> array_search($b['class'], ['out', 'urgent', 'low']), ['name', 'asc']])
            ->values();
    }

    /** Medicines that have had stock at some time, keyed by medicine. */
    private static function everStocked(): Collection
    {
        return StockBatch::distinct()->pluck('medicine_id')->flip();
    }

    /** Medicines in use that have never had any stock entered: the sign that opening stock is still to be done. */
    public static function neverStocked(): int
    {
        return Medicine::where('is_active', true)->whereNotIn('id', StockBatch::distinct()->select('medicine_id'))->count();
    }

    /**
     * Batches that expire soon or have already expired with stock still on the shelf.
     *
     * @return array{expiring: Collection, expired: Collection}
     */
    public static function expiry(): array
    {
        $today = today();
        $sold = DB::table('pharmacy_sale_items as i')->join('pharmacy_sales as s', 's.id', '=', 'i.pharmacy_sale_id')
            ->whereNull('s.voided_at')->where('s.sold_at', '>=', $today->copy()->subDays(self::SALES_DAYS))
            ->selectRaw('i.medicine_id, sum(i.quantity - i.returned_quantity) as units')->groupBy('i.medicine_id')->pluck('units', 'medicine_id');

        $rows = StockBatch::where('units_in_stock', '>', 0)->whereDate('expiry', '<=', $today->copy()->addDays(self::EXPIRY_WATCH_DAYS))
            ->with(['medicine:id,name', 'invoice:id,stockist_id,invoice_no', 'invoice.stockist:id,name'])->orderBy('expiry')->limit(400)->get()
            ->map(function (StockBatch $b) use ($today, $sold) {
                $expiry = Carbon::parse($b->expiry);
                $days = (int) $today->diffInDays($expiry, false);
                $daily = max(0, (int) ($sold[$b->medicine_id] ?? 0)) / self::SALES_DAYS;

                return [
                    'batch_id' => $b->id, 'medicine' => $b->medicine?->name, 'batch_no' => $b->batch_no, 'expiry' => $expiry->toDateString(),
                    'days_left' => $days, 'units' => (int) $b->units_in_stock,
                    'value' => round($b->effectiveUnitCost() * $b->units_in_stock, 2),
                    // At the present rate of sale, how many will still be on the shelf on the day it expires.
                    'likely_left' => $days > 0 ? max(0, (int) $b->units_in_stock - (int) floor($daily * $days)) : (int) $b->units_in_stock,
                    'stockist' => $b->invoice?->stockist?->name,
                ];
            });

        return ['expiring' => $rows->where('days_left', '>=', 0)->values(), 'expired' => $rows->where('days_left', '<', 0)->values()];
    }

    /**
     * Stock that has sat for three months without a single sale.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function slow(): Collection
    {
        $since = today()->subDays(self::SLOW_DAYS);
        $moving = DB::table('pharmacy_sale_items as i')->join('pharmacy_sales as s', 's.id', '=', 'i.pharmacy_sale_id')
            ->whereNull('s.voided_at')->where('s.sold_at', '>=', $since)->distinct()->pluck('i.medicine_id');

        return StockBatch::where('units_in_stock', '>', 0)->whereDate('expiry', '>=', today())->where('created_at', '<', $since)
            ->whereNotIn('medicine_id', $moving)->with('medicine:id,name')->get()
            ->groupBy('medicine_id')->map(fn (Collection $batches) => [
                'medicine_id' => $batches->first()->medicine_id, 'medicine' => $batches->first()->medicine?->name,
                'units' => (int) $batches->sum('units_in_stock'),
                'value' => round($batches->sum(fn (StockBatch $b) => $b->effectiveUnitCost() * $b->units_in_stock), 2),
                'next_expiry' => Carbon::parse($batches->min('expiry'))->toDateString(),
            ])->sortByDesc('value')->values()->take(200);
    }

    /**
     * What is owed to suppliers: each unpaid invoice with its due date, and each supplier's total by age.
     *
     * @return array{invoices: Collection, suppliers: Collection, totals: array<string, float>}
     */
    public static function payables(): array
    {
        $today = today();
        $buckets = ['not_due' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'over_90' => 0.0];

        $invoices = PurchaseInvoice::whereColumn('paid', '<', 'total')->with('stockist:id,name,credit_days')->orderBy('invoice_date')->get()
            ->map(function (PurchaseInvoice $p) use ($today) {
                $due = self::dueDate($p);
                $late = (int) $due->diffInDays($today, false);

                return [
                    'id' => $p->id, 'invoice_no' => $p->invoice_no, 'invoice_date' => $p->invoice_date->toDateString(),
                    'stockist_id' => $p->stockist_id, 'stockist' => $p->stockist?->name,
                    'total' => $p->total, 'paid' => $p->paid, 'owed' => round($p->total - $p->paid, 2),
                    'due_date' => $due->toDateString(), 'days_overdue' => $late,
                    'bucket' => $late <= 0 ? 'not_due' : ($late <= 30 ? 'd1_30' : ($late <= 60 ? 'd31_60' : ($late <= 90 ? 'd61_90' : 'over_90'))),
                ];
            });

        // Stock sent back is a credit with the supplier as a whole, not against one invoice.
        $returned = StockReturn::selectRaw('stockist_id, sum(total) as returned')->groupBy('stockist_id')->pluck('returned', 'stockist_id');
        $billed = PurchaseInvoice::selectRaw('stockist_id, sum(total) as billed, sum(paid) as paid')->groupBy('stockist_id')->get()->keyBy('stockist_id');

        $suppliers = $invoices->groupBy('stockist_id')->map(function (Collection $rows, $stockistId) use ($buckets, $returned, $billed) {
            $age = $buckets;
            foreach ($rows as $row) {
                $age[$row['bucket']] = round($age[$row['bucket']] + $row['owed'], 2);
            }
            $owed = round($rows->sum('owed'), 2);
            $credit = round((float) ($returned[$stockistId] ?? 0), 2);

            return ['stockist_id' => (int) $stockistId, 'stockist' => $rows->first()['stockist'], 'invoices' => $rows->count(), 'owed' => $owed,
                'returns_credit' => $credit, 'net' => round((float) $billed[$stockistId]->billed - (float) $billed[$stockistId]->paid - $credit, 2)] + $age;
        })->sortByDesc('owed')->values();

        $totals = collect(array_keys($buckets))->mapWithKeys(fn ($b) => [$b => round($suppliers->sum($b), 2)])->all()
            + ['owed' => round($invoices->sum('owed'), 2), 'returns_credit' => round($suppliers->sum('returns_credit'), 2)];

        return ['invoices' => $invoices->values(), 'suppliers' => $suppliers, 'totals' => $totals];
    }

    /** The day an invoice should be paid by: the date written on it, or its date plus the supplier's credit period. */
    public static function dueDate(PurchaseInvoice $invoice): Carbon
    {
        return $invoice->due_date ? Carbon::parse($invoice->due_date) : $invoice->invoice_date->copy()->addDays((int) ($invoice->stockist?->credit_days ?? 0));
    }

    /**
     * Orders that should have arrived and have not, and recent invoices that came with no order.
     *
     * @return array{orders: Collection, invoices: Collection}
     */
    public static function unmatched(): array
    {
        $today = today();
        $orders = PurchaseOrder::whereIn('status', ['draft', 'open'])->with('stockist:id,name,lead_days')->withCount('items')->orderBy('order_date')->get()
            ->map(function (PurchaseOrder $o) use ($today) {
                $expected = $o->expected_on ? Carbon::parse($o->expected_on) : $o->order_date->copy()->addDays((int) ($o->stockist?->lead_days ?? self::DEFAULT_LEAD_DAYS));

                return [
                    'id' => $o->id, 'order_no' => $o->order_no, 'stockist' => $o->stockist?->name, 'status' => $o->status,
                    'order_date' => $o->order_date->toDateString(), 'expected_on' => $expected->toDateString(),
                    'days_late' => $o->status === 'open' ? (int) $expected->diffInDays($today, false) : 0, 'items' => $o->items_count,
                ];
            });

        $invoices = PurchaseInvoice::whereNull('purchase_order_id')->where('invoice_date', '>=', $today->copy()->subDays(14))
            ->with('stockist:id,name')->latest('invoice_date')->get()
            ->map(fn (PurchaseInvoice $p) => ['id' => $p->id, 'invoice_no' => $p->invoice_no, 'invoice_date' => $p->invoice_date->toDateString(), 'stockist' => $p->stockist?->name, 'total' => $p->total]);

        return [
            'orders' => $orders->filter(fn ($o) => $o['status'] === 'draft' || $o['days_late'] > 0)->values(),
            'invoices' => $invoices->values(),
        ];
    }

    /**
     * A supplier's account between two dates: what they billed, what was paid and sent back, and the balance after each line.
     */
    public static function statement(Stockist $stockist, Carbon $from, Carbon $to): array
    {
        $invoices = PurchaseInvoice::where('stockist_id', $stockist->id)->with('payments')->get();
        $entries = collect();
        foreach ($invoices as $invoice) {
            $entries->push(['date' => $invoice->invoice_date->toDateString(), 'kind' => 'invoice', 'text' => "Invoice {$invoice->invoice_no}", 'billed' => $invoice->total, 'paid' => 0.0, 'order' => 1]);
            foreach ($invoice->payments as $payment) {
                $entries->push([
                    'date' => $payment->paid_on->toDateString(), 'kind' => 'payment', 'order' => 2, 'billed' => 0.0, 'paid' => $payment->amount,
                    'text' => "Paid against {$invoice->invoice_no} by ".str_replace('_', ' ', $payment->mode).($payment->reference ? " ({$payment->reference})" : ''),
                ]);
            }
            // Money marked as paid before each payment was written down on its own.
            $unlisted = round($invoice->paid - $invoice->payments->sum('amount'), 2);
            if ($unlisted > 0) {
                $entries->push(['date' => $invoice->invoice_date->toDateString(), 'kind' => 'payment', 'text' => "Paid against {$invoice->invoice_no} (date not recorded)", 'billed' => 0.0, 'paid' => $unlisted, 'order' => 2]);
            }
        }
        foreach (StockReturn::where('stockist_id', $stockist->id)->get() as $return) {
            $entries->push(['date' => $return->returned_on->toDateString(), 'kind' => 'return', 'text' => "Stock returned, credit note {$return->return_no}", 'billed' => 0.0, 'paid' => $return->total, 'order' => 3]);
        }

        $entries = $entries->sortBy([['date', 'asc'], ['order', 'asc']])->values();
        $before = $entries->filter(fn ($e) => $e['date'] < $from->toDateString());
        $balance = round($before->sum('billed') - $before->sum('paid'), 2);
        $opening = $balance;

        $lines = $entries->filter(fn ($e) => $e['date'] >= $from->toDateString() && $e['date'] <= $to->toDateString())->map(function ($e) use (&$balance) {
            $balance = round($balance + $e['billed'] - $e['paid'], 2);

            return ['date' => $e['date'], 'kind' => $e['kind'], 'text' => $e['text'], 'billed' => $e['billed'], 'paid' => $e['paid'], 'balance' => $balance];
        })->values();

        return [
            'stockist' => $stockist->only(['id', 'name', 'phone', 'gstin', 'address', 'credit_days']),
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'opening' => $opening, 'lines' => $lines,
            'billed' => round($lines->sum('billed'), 2), 'paid' => round($lines->sum('paid'), 2), 'closing' => $balance,
        ];
    }

    /** Supplier payments made on a day, for the manager's and the cashier's eyes. */
    public static function paidOn(Carbon $day): float
    {
        return round((float) PurchasePayment::whereDate('paid_on', $day->toDateString())->sum('amount'), 2);
    }
}
