<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Manufacturer;
use App\Models\Medicine;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Everything about what is on the pharmacy's shelves and how it gets there or leaves, other than sales. */
class PharmacyStockController extends Controller
{
    // ---- Manufacturers ----

    public function manufacturers(): JsonResponse
    {
        return response()->json(Manufacturer::orderBy('name')->get());
    }

    public function storeManufacturer(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'unique:manufacturers,name']], ['name.unique' => 'This manufacturer is already in the list.']);

        return response()->json(Manufacturer::create($data), 201);
    }

    /** Renaming a manufacturer also renames it on every medicine that carries the old name. */
    public function updateManufacturer(Request $request, Manufacturer $manufacturer): JsonResponse
    {
        $this->authorizePharmacy($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('manufacturers', 'name')->ignore($manufacturer)]]);

        DB::transaction(function () use ($manufacturer, $data) {
            Medicine::where('manufacturer', $manufacturer->name)->update(['manufacturer' => $data['name']]);
            $manufacturer->update($data);
        });

        return response()->json($manufacturer);
    }

    // ---- Opening stock ----

    /** Puts stock on the shelf that did not come through a purchase invoice, e.g. what was there on day one. */
    public function openingStock(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.medicine_id' => ['required', 'exists:medicines,id'],
            'items.*.batch_no' => ['required', 'string', 'max:50'],
            'items.*.expiry' => ['required', 'date_format:Y-m'],
            'items.*.units_per_pack' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.mrp' => ['required', 'numeric', 'gt:0'],
            'items.*.cost_price' => ['required', 'numeric', 'min:0'],
            'items.*.gst_percent' => ['required', 'numeric', 'min:0', 'max:40'],
        ], ['items.required' => 'Add at least one medicine.']);

        $batches = DB::transaction(fn () => collect($data['items'])->map(function (array $item, int $index) {
            $expiry = Carbon::createFromFormat('Y-m-d', $item['expiry'].'-01')->endOfMonth();
            if ($expiry->isPast()) {
                throw ValidationException::withMessages(["items.{$index}.expiry" => 'Line '.($index + 1).' has already expired.']);
            }

            // Quantity is in single units; MRP and cost are per pack, as printed on the strip and the bill.
            return StockBatch::create([
                'medicine_id' => $item['medicine_id'], 'batch_no' => $item['batch_no'], 'expiry' => $expiry->toDateString(),
                'units_per_pack' => $item['units_per_pack'], 'packs' => (int) ceil($item['quantity'] / $item['units_per_pack']),
                'pack_price' => $item['cost_price'], 'pack_mrp' => $item['mrp'], 'unit_mrp' => round($item['mrp'] / $item['units_per_pack'], 2),
                'gst_percent' => $item['gst_percent'], 'units_received' => $item['quantity'], 'units_in_stock' => $item['quantity'],
            ]);
        }));

        return response()->json(['added' => $batches->count()], 201);
    }

    // ---- Inventory by medicine ----

    /** Units left per medicine, with tabs for what is expiring, running low or gone. */
    public function inventory(Request $request): JsonResponse
    {
        $soon = today()->addDays(90);

        $medicines = Medicine::where('is_active', true)
            ->withSum(['batches as units_in_stock' => fn ($q) => $q->whereDate('expiry', '>=', today())], 'units_in_stock')
            ->withSum(['batches as expiring_units' => fn ($q) => $q->where('units_in_stock', '>', 0)->whereDate('expiry', '<=', $soon)], 'units_in_stock')
            ->withMin(['batches as next_expiry' => fn ($q) => $q->where('units_in_stock', '>', 0)], 'expiry')
            ->orderBy('name')->get(['id', 'name', 'type', 'manufacturer', 'reorder_level'])
            ->each(function (Medicine $m) {
                $m->units_in_stock = (int) $m->units_in_stock;
                $m->expiring_units = (int) $m->expiring_units;
            });

        return response()->json([
            'all' => $medicines,
            'counts' => [
                'expiring' => $medicines->where('expiring_units', '>', 0)->count(),
                'low' => $medicines->filter(fn ($m) => $m->units_in_stock > 0 && $m->units_in_stock <= $m->reorder_level)->count(),
                'zero' => $medicines->where('units_in_stock', 0)->count(),
            ],
        ]);
    }

    /** Corrects the count of a batch after a physical check, and records who changed it and why. */
    public function adjust(Request $request, StockBatch $batch): JsonResponse
    {
        $this->authorizePharmacy($request);

        $data = $request->validate([
            'units_in_stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], ['reason.required' => 'Say why the count is being changed.']);

        DB::transaction(function () use ($batch, $data, $request) {
            $locked = StockBatch::whereKey($batch->id)->lockForUpdate()->first();
            if ($locked->units_in_stock === $data['units_in_stock']) {
                throw ValidationException::withMessages(['units_in_stock' => 'That is already the count for this batch.']);
            }

            StockAdjustment::create([
                'stock_batch_id' => $locked->id, 'medicine_id' => $locked->medicine_id, 'before' => $locked->units_in_stock,
                'after' => $data['units_in_stock'], 'reason' => $data['reason'], 'created_by' => $request->user()->id,
            ]);
            $locked->update(['units_in_stock' => $data['units_in_stock']]);
        });

        return response()->json($batch->fresh()->load(['medicine:id,name,reorder_level', 'invoice:id,invoice_no']));
    }

    // ---- Stockist payments and returns ----

    public function payInvoice(Request $request, PurchaseInvoice $purchase): JsonResponse
    {
        $this->authorizePharmacy($request);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0']]);

        $balance = round($purchase->total - $purchase->paid, 2);
        if ($data['amount'] > $balance) {
            throw ValidationException::withMessages(['amount' => "Only ₹{$balance} is owed on this invoice."]);
        }

        $purchase->increment('paid', $data['amount']);

        return response()->json($purchase->fresh()->load(['stockist', 'batches.medicine:id,name']));
    }

    public function stockReturns(): JsonResponse
    {
        return response()->json(StockReturn::with(['stockist:id,name', 'items'])->latest('returned_on')->latest('id')->limit(200)->get());
    }

    /** Sends stock back to a stockist (expired, damaged or excess): the units leave their batches. */
    public function storeStockReturn(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);

        $data = $request->validate([
            'stockist_id' => ['required', 'exists:stockists,id'],
            'returned_on' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.stock_batch_id' => ['required', 'integer', 'distinct', 'exists:stock_batches,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], ['items.required' => 'Choose at least one batch to return.']);

        $return = DB::transaction(function () use ($data, $request) {
            $batches = StockBatch::with('medicine:id,name')->whereIn('id', array_column($data['items'], 'stock_batch_id'))->lockForUpdate()->get()->keyBy('id');
            $return = StockReturn::create([
                'stockist_id' => $data['stockist_id'], 'returned_on' => $data['returned_on'], 'remarks' => $data['remarks'] ?? null, 'created_by' => $request->user()->id,
            ]);

            $total = 0;
            foreach ($data['items'] as $index => $entry) {
                $batch = $batches[$entry['stock_batch_id']];
                if ($entry['quantity'] > $batch->units_in_stock) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => "Only {$batch->units_in_stock} of {$batch->medicine->name} (batch {$batch->batch_no}) are in stock."]);
                }

                // The stockist credits what was paid, including the GST on it.
                $cost = round($batch->unitCost() * (1 + $batch->gst_percent / 100), 2);
                $amount = round($cost * $entry['quantity'], 2);
                $total += $amount;
                $return->items()->create([
                    'stock_batch_id' => $batch->id, 'medicine_id' => $batch->medicine_id, 'medicine_name' => $batch->medicine->name,
                    'batch_no' => $batch->batch_no, 'quantity' => $entry['quantity'], 'unit_cost' => $cost, 'amount' => $amount,
                ]);
                $batch->decrement('units_in_stock', $entry['quantity']);
            }

            $return->update(['total' => round($total, 2)]);

            return $return;
        });

        return response()->json($return->load(['stockist:id,name', 'items']), 201);
    }

    // ---- Purchase orders ----

    public function purchaseOrders(): JsonResponse
    {
        return response()->json(PurchaseOrder::with('stockist:id,name')->withCount('items')->latest('order_date')->latest('id')->limit(200)->get());
    }

    public function showPurchaseOrder(PurchaseOrder $order): JsonResponse
    {
        return response()->json($order->load(['stockist', 'items']));
    }

    public function storePurchaseOrder(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);

        $data = $request->validate([
            'stockist_id' => ['required', 'exists:stockists,id'],
            'order_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.medicine_id' => ['required', 'integer', 'distinct', 'exists:medicines,id'],
            'items.*.units_per_pack' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.packs' => ['required', 'integer', 'min:1', 'max:100000'],
        ], ['items.required' => 'Add at least one medicine to the order.']);

        $order = DB::transaction(function () use ($data, $request) {
            $order = PurchaseOrder::create([
                'stockist_id' => $data['stockist_id'], 'order_date' => $data['order_date'], 'remarks' => $data['remarks'] ?? null, 'created_by' => $request->user()->id,
            ]);
            $names = Medicine::whereIn('id', array_column($data['items'], 'medicine_id'))->pluck('name', 'id');
            foreach ($data['items'] as $item) {
                $order->items()->create($item + ['medicine_name' => $names[$item['medicine_id']]]);
            }

            return $order;
        });

        return response()->json($order->fresh()->load(['stockist', 'items']), 201);
    }

    public function updatePurchaseOrder(Request $request, PurchaseOrder $order): JsonResponse
    {
        $this->authorizePharmacy($request);
        $order->update($request->validate(['status' => ['required', Rule::in(['open', 'received', 'cancelled'])]]));

        return response()->json($order->load(['stockist', 'items']));
    }

    private function authorizePharmacy(Request $request): void
    {
        abort_unless($request->user()->allows('canRunPharmacy'), 403, 'You are not allowed to do this in the pharmacy.');
    }
}
