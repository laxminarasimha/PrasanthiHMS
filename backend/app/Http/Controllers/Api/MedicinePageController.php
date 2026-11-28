<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\PharmacySaleItem;
use App\Models\RepProduct;
use App\Models\StockBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One medicine on one page: what it contains, where it is kept, what is on the shelf batch by batch, whom it is
 * bought from and at what rate, how it is selling, the pharmacy's other brands with the same contents, and what the
 * companies' representatives offer for it. Only what is recorded is shown; nothing about its contents is guessed.
 */
class MedicinePageController extends Controller
{
    public function show(Request $request, Medicine $medicine): JsonResponse
    {
        abort_unless($request->user()->mayInPharmacy($request) || $request->user()->allows('canAuditPharmacy'), 403, 'You are not allowed to open the pharmacy’s medicines.');
        $medicine->load(['ingredients:id,name']);

        $batches = StockBatch::where('medicine_id', $medicine->id)->with('invoice:id,invoice_no,invoice_date,stockist_id', 'invoice.stockist:id,name')->latest('id')->limit(200)->get();
        $onShelf = $batches->where('units_in_stock', '>', 0)->sortBy(fn (StockBatch $b) => $b->expiry?->toDateString() ?? '9999');
        $bought = $batches->filter(fn (StockBatch $b) => $b->invoice)->take(12);
        $suppliers = $batches->filter(fn (StockBatch $b) => $b->invoice?->stockist)->groupBy(fn (StockBatch $b) => $b->invoice->stockist_id)
            ->map(fn ($rows) => ['id' => (int) $rows->first()->invoice->stockist_id, 'name' => $rows->first()->invoice->stockist->name, 'times' => $rows->unique('purchase_invoice_id')->count(),
                'last_on' => $rows->first()->invoice->invoice_date?->toDateString(), 'last_rate' => (float) $rows->first()->pack_price])
            ->sortByDesc('times')->values();

        $sold = PharmacySaleItem::where('medicine_id', $medicine->id)->join('pharmacy_sales as s', 's.id', '=', 'pharmacy_sale_items.pharmacy_sale_id')->whereNull('s.voided_at');
        $since = now()->subDays(30);
        $recent = (clone $sold)->orderByDesc('s.sold_at')->orderByDesc('pharmacy_sale_items.id')->limit(15)
            ->get(['s.id as sale_id', 's.bill_no', 's.sold_at', 's.customer_name', 's.patient_id', 'pharmacy_sale_items.quantity', 'pharmacy_sale_items.returned_quantity', 'pharmacy_sale_items.amount']);
        $month = (clone $sold)->where('s.sold_at', '>=', $since)->selectRaw('count(distinct s.id) as bills, coalesce(sum(pharmacy_sale_items.quantity - pharmacy_sale_items.returned_quantity), 0) as units, coalesce(sum(pharmacy_sale_items.amount), 0) as amount')->first();

        // Brands with exactly the same contents, as written down for each; a medicine with nothing written has none.
        $same = filled($medicine->composition) ? mb_strtolower(trim($medicine->composition)) : null;
        $alternatives = $same === null ? collect() : Medicine::where('is_active', true)->where('id', '!=', $medicine->id)->whereRaw('lower(trim(composition)) = ?', [$same])
            ->withSum('batches as on_shelf', 'units_in_stock')->orderBy('name')->limit(20)->get(['id', 'name', 'manufacturer'])
            ->map(fn (Medicine $m) => ['id' => $m->id, 'name' => $m->name, 'manufacturer' => $m->manufacturer, 'on_shelf' => (int) $m->on_shelf]);
        $offers = RepProduct::where(fn ($q) => $q->where('medicine_id', $medicine->id)->when($same, fn ($w) => $w->orWhereRaw('lower(trim(composition)) = ?', [$same])))
            ->with(['company:id,name', 'company.reps' => fn ($q) => $q->where('is_active', true)->select(['id', 'rep_company_id', 'name', 'mobile'])])
            ->orderBy('name')->limit(30)->get()
            ->map(fn (RepProduct $p) => ['id' => $p->id, 'name' => $p->name, 'company' => $p->company?->name, 'pack' => $p->pack, 'mrp' => $p->mrp, 'rate' => $p->rate, 'scheme' => $p->scheme,
                'ours' => $p->medicine_id === $medicine->id, 'reps' => $p->company?->reps->map(fn ($r) => ['name' => $r->name, 'mobile' => $r->mobile])->values() ?? []]);

        return response()->json([
            'medicine' => $medicine->only(['id', 'name', 'composition', 'type', 'manufacturer', 'category', 'is_scheduled', 'hsn_code', 'gst_percent', 'units_per_pack', 'reorder_level', 'desired_stock', 'is_active', 'rack_no', 'box_no'])
                + ['drugs' => $medicine->ingredients->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'strength' => $d->pivot->strength])->values()],
            'on_shelf' => (int) $onShelf->sum('units_in_stock'),
            'batches' => $onShelf->map(fn (StockBatch $b) => [
                'id' => $b->id, 'batch_no' => $b->batch_no, 'expiry' => $b->expiry?->toDateString(), 'units' => (int) $b->units_in_stock, 'rack_no' => $b->rack_no, 'box_no' => $b->box_no,
                'unit_mrp' => (float) $b->unit_mrp, 'invoice_id' => $b->purchase_invoice_id, 'invoice_no' => $b->invoice?->invoice_no,
            ])->values(),
            'purchases' => $bought->map(fn (StockBatch $b) => [
                'invoice_id' => $b->purchase_invoice_id, 'invoice_no' => $b->invoice->invoice_no, 'on' => $b->invoice->invoice_date?->toDateString(),
                'stockist_id' => $b->invoice->stockist_id, 'stockist' => $b->invoice->stockist?->name, 'batch_no' => $b->batch_no,
                'packs' => (float) $b->packs, 'free_packs' => (float) $b->free_packs, 'pack_price' => (float) $b->pack_price, 'pack_mrp' => (float) $b->pack_mrp,
            ])->values(),
            'suppliers' => $suppliers,
            'sales' => [
                'month' => ['bills' => (int) ($month->bills ?? 0), 'units' => (int) ($month->units ?? 0), 'amount' => round((float) ($month->amount ?? 0), 2)],
                'recent' => $recent->map(fn ($r) => ['sale_id' => (int) $r->sale_id, 'bill_no' => $r->bill_no, 'on' => substr((string) $r->sold_at, 0, 16), 'customer' => $r->customer_name,
                    'patient_id' => $r->patient_id, 'units' => (int) $r->quantity - (int) $r->returned_quantity, 'amount' => (float) $r->amount])->values(),
            ],
            'alternatives' => $alternatives->values(),
            'offers' => $offers->values(),
        ]);
    }
}
