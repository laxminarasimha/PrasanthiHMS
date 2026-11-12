<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\PharmacyChange;
use App\Models\PharmacySale;
use App\Models\PharmacySaleItem;
use App\Models\PharmacySaleReturn;
use App\Models\PurchaseInvoice;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pharmacy reports. Every report answers with the same shape, so one screen can show any of them:
 * a title, column headings (with which ones are money or numbers), rows, and a totals row.
 */
class PharmacyReportController extends Controller
{
    public const REPORTS = [
        'billing' => 'Billing report',
        'monthly-billing' => 'Monthly billing report',
        'item-wise' => 'Sale item wise report',
        'sales-tax' => 'Sales tax report',
        'purchase-tax' => 'Purchase tax report',
        'returns' => 'Medicine returns report',
        'product-margin' => 'Product margin report',
        'date-margin' => 'Date wise margin report',
        'stock-adjustments' => 'Stock adjustment report',
        'deleted-bills' => 'Deleted bills report',
        'bills-modified' => 'Bill modified report',
        'invoices-modified' => 'Invoice modified report',
        'stock-updated' => 'Stock data update report',
        'medicines-updated' => 'Medicine data updated report',
        'schedule-drugs' => 'Schedule drug report',
        'moving' => 'Moving and non-moving medicines',
    ];

    public function show(Request $request, string $type): JsonResponse
    {
        abort_unless($request->user()->role->canRunPharmacy(), 403, 'You are not allowed to view pharmacy reports.');
        abort_unless(isset(self::REPORTS[$type]), 404, 'There is no such report.');

        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($range['from'] ?? today())->startOfDay();
        $to = Carbon::parse($range['to'] ?? $from)->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose a range of one year or less.');

        [$columns, $rows] = $this->{str_replace('-', '', ucwords($type, '-'))}($from, $to);
        $rows = collect($rows)->values();

        // Totals for every money or number column, except rates and percentages.
        $totals = [];
        foreach ($columns as $column) {
            if (in_array($column['type'] ?? 'text', ['money', 'number'], true) && ($column['total'] ?? true)) {
                $totals[$column['key']] = round((float) $rows->sum($column['key']), 2);
            }
        }

        return response()->json([
            'type' => $type, 'title' => self::REPORTS[$type], 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'reports' => self::REPORTS,
        ]);
    }

    private static function col(string $key, string $label, string $type = 'text', bool $total = true): array
    {
        return compact('key', 'label', 'type', 'total');
    }

    /** Sale lines in the period from bills that were not deleted. */
    private function lines(Carbon $from, Carbon $to)
    {
        return PharmacySaleItem::query()->whereHas('sale', fn ($q) => $q->whereBetween('sold_at', [$from, $to])->whereNull('voided_at'));
    }

    private function Billing(Carbon $from, Carbon $to): array
    {
        $rows = PharmacySale::whereBetween('sold_at', [$from, $to])->whereNull('voided_at')->orderBy('sold_at')->get()
            ->map(fn (PharmacySale $s) => [
                'bill_no' => $s->bill_no, 'date' => $s->sold_at->format('Y-m-d H:i'), 'customer' => $s->customer_name, 'doctor' => $s->referred_doctor,
                'amount' => $s->subtotal, 'discount' => $s->discount, 'total' => $s->total, 'returned' => $s->returned, 'paid' => $s->paid, 'due' => $s->due,
            ]);

        return [[
            self::col('bill_no', 'Bill'), self::col('date', 'Date'), self::col('customer', 'Customer'), self::col('doctor', 'Doctor'),
            self::col('amount', 'Amount', 'money'), self::col('discount', 'Discount', 'money'), self::col('total', 'Bill total', 'money'),
            self::col('returned', 'Returned', 'money'), self::col('paid', 'Paid', 'money'), self::col('due', 'Due', 'money'),
        ], $rows];
    }

    private function MonthlyBilling(Carbon $from, Carbon $to): array
    {
        $rows = PharmacySale::whereBetween('sold_at', [$from, $to])->whereNull('voided_at')->get()
            ->groupBy(fn (PharmacySale $s) => $s->sold_at->format('Y-m'))
            ->map(fn (Collection $sales, string $month) => [
                'month' => Carbon::createFromFormat('Y-m-d', $month.'-01')->format('M Y'), 'bills' => $sales->count(),
                'total' => round($sales->sum('total'), 2), 'returned' => round($sales->sum('returned'), 2),
                'paid' => round($sales->sum('paid'), 2), 'due' => round($sales->sum('due'), 2),
            ])->sortKeys();

        return [[
            self::col('month', 'Month'), self::col('bills', 'Bills', 'number'), self::col('total', 'Billed', 'money'),
            self::col('returned', 'Returned', 'money'), self::col('paid', 'Collected', 'money'), self::col('due', 'Due', 'money'),
        ], $rows];
    }

    private function ItemWise(Carbon $from, Carbon $to): array
    {
        $rows = $this->lines($from, $to)
            ->selectRaw('medicine_name, sum(quantity) as sold, sum(returned_quantity) as returned, sum(amount) as amount')
            ->groupBy('medicine_name')->orderByDesc('amount')->get()
            ->map(fn ($r) => ['medicine' => $r->medicine_name, 'sold' => (int) $r->sold, 'returned' => (int) $r->returned, 'net' => (int) $r->sold - (int) $r->returned, 'amount' => round((float) $r->amount, 2)]);

        return [[
            self::col('medicine', 'Medicine'), self::col('sold', 'Units sold', 'number'), self::col('returned', 'Units returned', 'number'),
            self::col('net', 'Net units', 'number'), self::col('amount', 'Sale value', 'money'),
        ], $rows];
    }

    /** GST collected on sales, by rate. Prices include GST, so the tax is the part contained in the amount. */
    private function SalesTax(Carbon $from, Carbon $to): array
    {
        $rows = $this->lines($from, $to)->get(['gst_percent', 'amount', 'quantity', 'returned_quantity'])
            ->groupBy(fn ($line) => (string) $line->gst_percent)
            ->map(function (Collection $lines, string $rate) {
                $gross = $lines->sum(fn ($l) => $l->quantity > 0 ? $l->amount * ($l->quantity - $l->returned_quantity) / $l->quantity : 0);
                $taxable = $gross / (1 + (float) $rate / 100);

                return [
                    'rate' => (float) $rate, 'taxable' => round($taxable, 2), 'cgst' => round(($gross - $taxable) / 2, 2),
                    'sgst' => round(($gross - $taxable) / 2, 2), 'tax' => round($gross - $taxable, 2), 'gross' => round($gross, 2),
                ];
            })->sortBy('rate');

        return [[
            self::col('rate', 'GST %', 'number', false), self::col('taxable', 'Taxable value', 'money'), self::col('cgst', 'CGST', 'money'),
            self::col('sgst', 'SGST', 'money'), self::col('tax', 'Total GST', 'money'), self::col('gross', 'Sale value', 'money'),
        ], $rows];
    }

    private function PurchaseTax(Carbon $from, Carbon $to): array
    {
        $rows = PurchaseInvoice::whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])->with('stockist:id,name,gstin')->orderBy('invoice_date')->get()
            ->map(fn (PurchaseInvoice $i) => [
                'date' => $i->invoice_date->format('Y-m-d'), 'invoice' => $i->invoice_no, 'stockist' => $i->stockist->name, 'gstin' => $i->stockist->gstin,
                'taxable' => $i->subtotal, 'tax' => $i->tax, 'total' => $i->total, 'paid' => $i->paid, 'balance' => round($i->total - $i->paid, 2),
            ]);

        return [[
            self::col('date', 'Date'), self::col('invoice', 'Invoice'), self::col('stockist', 'Stockist'), self::col('gstin', 'GSTIN'),
            self::col('taxable', 'Taxable value', 'money'), self::col('tax', 'GST', 'money'), self::col('total', 'Invoice total', 'money'),
            self::col('paid', 'Paid', 'money'), self::col('balance', 'Balance', 'money'),
        ], $rows];
    }

    private function Returns(Carbon $from, Carbon $to): array
    {
        $rows = PharmacySaleReturn::whereBetween('created_at', [$from, $to])->with('sale:id,bill_no,customer_name')->orderBy('created_at')->get()
            ->flatMap(fn (PharmacySaleReturn $r) => collect($r->lines)->map(fn (array $line) => [
                'date' => $r->created_at->format('Y-m-d H:i'), 'bill_no' => $r->sale->bill_no, 'customer' => $r->sale->customer_name,
                'medicine' => $line['medicine_name'], 'batch' => $line['batch_no'], 'quantity' => $line['quantity'], 'amount' => $line['amount'],
            ]));

        return [[
            self::col('date', 'Returned on'), self::col('bill_no', 'Bill'), self::col('customer', 'Customer'), self::col('medicine', 'Medicine'),
            self::col('batch', 'Batch'), self::col('quantity', 'Units', 'number'), self::col('amount', 'Value', 'money'),
        ], $rows];
    }

    /** Sale value against what the same units cost, per medicine. Sale value has the GST taken out so the two compare fairly. */
    private function ProductMargin(Carbon $from, Carbon $to): array
    {
        $rows = $this->lines($from, $to)->get()->groupBy('medicine_name')->map(fn (Collection $lines, string $name) => self::margin($lines) + ['medicine' => $name])
            ->sortByDesc('margin')->map(fn ($r) => ['medicine' => $r['medicine']] + $r);

        return [[
            self::col('medicine', 'Medicine'), self::col('units', 'Net units', 'number'), self::col('sale', 'Sale value (ex GST)', 'money'),
            self::col('cost', 'Cost', 'money'), self::col('margin', 'Margin', 'money'), self::col('margin_percent', 'Margin %', 'number', false),
        ], $rows];
    }

    private function DateMargin(Carbon $from, Carbon $to): array
    {
        $rows = $this->lines($from, $to)->with('sale:id,sold_at')->get()->groupBy(fn ($l) => $l->sale->sold_at->format('Y-m-d'))
            ->map(fn (Collection $lines, string $day) => ['date' => $day] + self::margin($lines))->sortKeys();

        return [[
            self::col('date', 'Date'), self::col('units', 'Net units', 'number'), self::col('sale', 'Sale value (ex GST)', 'money'),
            self::col('cost', 'Cost', 'money'), self::col('margin', 'Margin', 'money'), self::col('margin_percent', 'Margin %', 'number', false),
        ], $rows];
    }

    /**
     * @return array{units: int, sale: float, cost: float, margin: float, margin_percent: float}
     */
    private static function margin(Collection $lines): array
    {
        $units = $sale = $cost = 0;
        foreach ($lines as $line) {
            $kept = $line->quantity - $line->returned_quantity;
            $units += $kept;
            $sale += $line->quantity > 0 ? ($line->amount * $kept / $line->quantity) / (1 + $line->gst_percent / 100) : 0;
            $cost += $kept * $line->unit_cost;
        }

        return ['units' => $units, 'sale' => round($sale, 2), 'cost' => round($cost, 2), 'margin' => round($sale - $cost, 2), 'margin_percent' => $sale > 0 ? round(($sale - $cost) / $sale * 100, 1) : 0.0];
    }

    private function StockAdjustments(Carbon $from, Carbon $to): array
    {
        $rows = StockAdjustment::whereBetween('created_at', [$from, $to])->with(['medicine:id,name', 'user:id,name'])->latest()->get()
            ->map(fn (StockAdjustment $a) => [
                'date' => $a->created_at->format('Y-m-d H:i'), 'medicine' => $a->medicine->name, 'person' => $a->user?->name,
                'before' => $a->before, 'after' => $a->after, 'difference' => $a->after - $a->before, 'reason' => $a->reason,
            ]);

        return [[
            self::col('date', 'Time'), self::col('medicine', 'Medicine'), self::col('person', 'Changed by'), self::col('before', 'Before', 'number', false),
            self::col('after', 'After', 'number', false), self::col('difference', 'Difference', 'number'), self::col('reason', 'Reason'),
        ], $rows];
    }

    private function DeletedBills(Carbon $from, Carbon $to): array
    {
        $rows = PharmacySale::whereBetween('voided_at', [$from, $to])->orderBy('voided_at')->get()
            ->map(fn (PharmacySale $s) => [
                'bill_no' => $s->bill_no, 'billed_on' => $s->sold_at->format('Y-m-d H:i'), 'deleted_on' => $s->voided_at->format('Y-m-d H:i'),
                'customer' => $s->customer_name, 'total' => $s->total, 'reason' => $s->void_reason,
            ]);

        return [[
            self::col('bill_no', 'Bill'), self::col('billed_on', 'Billed on'), self::col('deleted_on', 'Deleted on'),
            self::col('customer', 'Customer'), self::col('total', 'Bill total', 'money'), self::col('reason', 'Reason'),
        ], $rows];
    }

    private function BillsModified(Carbon $from, Carbon $to): array
    {
        return $this->changes('sale', 'Bill', $from, $to);
    }

    private function InvoicesModified(Carbon $from, Carbon $to): array
    {
        return $this->changes('invoice', 'Invoice', $from, $to);
    }

    private function StockUpdated(Carbon $from, Carbon $to): array
    {
        return $this->changes('stock', 'Batch', $from, $to);
    }

    private function MedicinesUpdated(Carbon $from, Carbon $to): array
    {
        return $this->changes('medicine', 'Medicine', $from, $to);
    }

    /** Edits of one kind from the change log: when, to what, by whom, what changed and why. */
    private function changes(string $kind, string $heading, Carbon $from, Carbon $to): array
    {
        $rows = PharmacyChange::where('kind', $kind)->whereBetween('created_at', [$from, $to])->with('user:id,name')->latest()->latest('id')->get()
            ->map(fn (PharmacyChange $c) => [
                'date' => $c->created_at->format('Y-m-d H:i'), 'subject' => $c->label, 'person' => $c->user?->name,
                'changes' => str_replace("\n", '; ', $c->summary), 'reason' => $c->reason,
            ]);

        return [[
            self::col('date', 'Time'), self::col('subject', $heading), self::col('person', 'Changed by'), self::col('changes', 'What changed'), self::col('reason', 'Reason'),
        ], $rows];
    }

    /** The register of scheduled drugs sold: who bought what, on whose prescription. */
    private function ScheduleDrugs(Carbon $from, Carbon $to): array
    {
        $scheduled = Medicine::where('is_scheduled', true)->pluck('id');
        $rows = $this->lines($from, $to)->whereIn('medicine_id', $scheduled)->with('sale:id,bill_no,sold_at,customer_name,customer_phone,referred_doctor')->get()
            ->sortBy(fn ($l) => $l->sale->sold_at)
            ->map(fn ($l) => [
                'date' => $l->sale->sold_at->format('Y-m-d H:i'), 'bill_no' => $l->sale->bill_no, 'customer' => $l->sale->customer_name, 'phone' => $l->sale->customer_phone,
                'doctor' => $l->sale->referred_doctor, 'medicine' => $l->medicine_name, 'batch' => $l->batch_no, 'quantity' => $l->quantity - $l->returned_quantity,
            ]);

        return [[
            self::col('date', 'Date'), self::col('bill_no', 'Bill'), self::col('customer', 'Customer'), self::col('phone', 'Phone'),
            self::col('doctor', 'Doctor'), self::col('medicine', 'Medicine'), self::col('batch', 'Batch'), self::col('quantity', 'Units', 'number'),
        ], $rows];
    }

    /** Every stocked medicine with how much of it sold in the period; nothing sold means it is not moving. */
    private function Moving(Carbon $from, Carbon $to): array
    {
        $sold = $this->lines($from, $to)->selectRaw('medicine_id, sum(quantity - returned_quantity) as units')->groupBy('medicine_id')->pluck('units', 'medicine_id');
        $stock = StockBatch::where('units_in_stock', '>', 0)->selectRaw('medicine_id, sum(units_in_stock) as units, sum(units_in_stock * unit_mrp) as value')->groupBy('medicine_id')->get()->keyBy('medicine_id');

        $rows = Medicine::where('is_active', true)->whereIn('id', $stock->keys()->merge($sold->keys())->unique())->orderBy('name')->get(['id', 'name'])
            ->map(fn (Medicine $m) => [
                'medicine' => $m->name, 'sold' => (int) ($sold[$m->id] ?? 0), 'in_stock' => (int) ($stock[$m->id]->units ?? 0),
                'stock_value' => round((float) ($stock[$m->id]->value ?? 0), 2), 'status' => ($sold[$m->id] ?? 0) > 0 ? 'Moving' : 'Not moving',
            ])->sortByDesc('sold');

        return [[
            self::col('medicine', 'Medicine'), self::col('sold', 'Units sold', 'number'), self::col('in_stock', 'Units in stock', 'number'),
            self::col('stock_value', 'Stock value at MRP', 'money'), self::col('status', 'Status'),
        ], $rows];
    }
}
