<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\Medicine;
use App\Models\Payment;
use App\Models\PharmacySale;
use App\Models\PurchaseInvoice;
use App\Models\StockBatch;
use App\Models\Stockist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PharmacyController extends Controller
{
    // ---- Medicine list ----

    public function medicines(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));

        return response()->json(
            Medicine::query()
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('manufacturer', 'like', "%{$term}%")))
                ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
                ->withSum(['batches as units_in_stock' => fn ($q) => $q->whereDate('expiry', '>=', today())], 'units_in_stock')
                ->orderBy('name')
                ->limit(500)
                ->get()
                ->each(fn (Medicine $m) => $m->units_in_stock = (int) $m->units_in_stock)
        );
    }

    public function storeMedicine(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);

        $data = $this->medicineData($request);
        $this->rememberManufacturer($data['manufacturer'] ?? null);

        return response()->json(Medicine::create($data), 201);
    }

    public function updateMedicine(Request $request, Medicine $medicine): JsonResponse
    {
        $this->authorizePharmacy($request);
        $data = $this->medicineData($request, $medicine);
        $this->rememberManufacturer($data['manufacturer'] ?? null);

        // Keep a note of what was changed, for the "medicine data updated" report.
        $labels = ['name' => 'Name', 'type' => 'Type', 'manufacturer' => 'Manufacturer', 'category' => 'Category', 'is_scheduled' => 'Scheduled drug', 'hsn_code' => 'HSN code', 'gst_percent' => 'GST %', 'units_per_pack' => 'Units per pack', 'reorder_level' => 'Reorder level', 'is_active' => 'In use'];
        $before = $medicine->only(array_keys($labels));
        $label = $medicine->name;
        $medicine->update($data);
        $changes = \App\Models\PharmacyChange::differences($labels, $before, $medicine->only(array_keys($labels)));
        if ($changes) {
            \App\Models\PharmacyChange::create(['kind' => 'medicine', 'subject_id' => $medicine->id, 'label' => $label, 'summary' => implode("\n", $changes), 'created_by' => $request->user()->id]);
        }

        return response()->json($medicine);
    }

    /**
     * @return array<string, mixed>
     */
    private function medicineData(Request $request, ?Medicine $medicine = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('medicines')->ignore($medicine)],
            'type' => ['nullable', 'string', 'max:30'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'is_scheduled' => ['sometimes', 'boolean'],
            'hsn_code' => ['nullable', 'string', 'max:20'],
            'gst_percent' => ['required', 'numeric', 'min:0', 'max:40'],
            'units_per_pack' => ['required', 'integer', 'min:1', 'max:10000'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['name.unique' => 'A medicine with this name is already in the list.']) + ['reorder_level' => 0];
    }

    private function rememberManufacturer(?string $name): void
    {
        if (filled($name)) {
            \App\Models\Manufacturer::firstOrCreate(['name' => trim($name)]);
        }
    }

    // ---- Stockists (suppliers) ----

    public function stockists(): JsonResponse
    {
        return response()->json(Stockist::orderBy('name')->get());
    }

    public function storeStockist(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);

        return response()->json(Stockist::create($this->stockistData($request)), 201);
    }

    public function updateStockist(Request $request, Stockist $stockist): JsonResponse
    {
        $this->authorizePharmacy($request);
        $stockist->update($this->stockistData($request));

        return response()->json($stockist);
    }

    /**
     * @return array<string, mixed>
     */
    private function stockistData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
    }

    // ---- Purchase invoices (stock coming in) ----

    public function purchases(): JsonResponse
    {
        return response()->json(
            PurchaseInvoice::with('stockist:id,name')->withCount('batches')->latest('invoice_date')->latest('id')->limit(200)->get()
        );
    }

    public function showPurchase(PurchaseInvoice $purchase): JsonResponse
    {
        return response()->json($purchase->load(['stockist', 'batches.medicine:id,name']));
    }

    /** Records a supplier invoice and puts every line into stock as its own batch. */
    public function storePurchase(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);

        $data = $request->validate([
            'stockist_id' => ['required', 'exists:stockists,id'],
            'invoice_no' => ['required', 'string', 'max:50', Rule::unique('purchase_invoices')->where('stockist_id', $request->input('stockist_id'))],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.medicine_id' => ['required', 'exists:medicines,id'],
            'items.*.batch_no' => ['required', 'string', 'max:50'],
            'items.*.expiry' => ['required', 'date_format:Y-m'],
            'items.*.units_per_pack' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.packs' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.free_packs' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'items.*.pack_price' => ['required', 'numeric', 'min:0'],
            'items.*.pack_mrp' => ['required', 'numeric', 'gt:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.gst_percent' => ['required', 'numeric', 'min:0', 'max:40'],
            'items.*.rack_no' => ['nullable', 'string', 'max:30'],
        ], [
            'invoice_no.unique' => 'This invoice number is already entered for this stockist.',
            'items.required' => 'Add at least one medicine to the invoice.',
        ]);

        foreach ($data['items'] as $index => $item) {
            if (Carbon::createFromFormat('Y-m-d', $item['expiry'].'-01')->endOfMonth()->isPast()) {
                throw ValidationException::withMessages(["items.{$index}.expiry" => 'Line '.($index + 1).' has already expired.']);
            }
        }

        $invoice = DB::transaction(function () use ($data, $request) {
            $invoice = PurchaseInvoice::create([
                'branch_id' => $request->user()->branch_id,
                'stockist_id' => $data['stockist_id'],
                'invoice_no' => $data['invoice_no'],
                'invoice_date' => $data['invoice_date'],
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $subtotal = $tax = 0;
            foreach ($data['items'] as $item) {
                $free = (int) ($item['free_packs'] ?? 0);
                $discount = (float) ($item['discount_percent'] ?? 0);
                $units = ($item['packs'] + $free) * $item['units_per_pack'];
                // The stockist charges for the paid packs only, less any trade discount, plus GST.
                $line = round($item['packs'] * $item['pack_price'] * (1 - $discount / 100), 2);
                $subtotal += $line;
                $tax += round($line * $item['gst_percent'] / 100, 2);

                $invoice->batches()->create([
                    'medicine_id' => $item['medicine_id'],
                    'batch_no' => $item['batch_no'],
                    'expiry' => Carbon::createFromFormat('Y-m-d', $item['expiry'].'-01')->endOfMonth()->toDateString(),
                    'units_per_pack' => $item['units_per_pack'],
                    'packs' => $item['packs'],
                    'free_packs' => $free,
                    'pack_price' => $item['pack_price'],
                    'pack_mrp' => $item['pack_mrp'],
                    'unit_mrp' => round($item['pack_mrp'] / $item['units_per_pack'], 2),
                    'discount_percent' => $discount,
                    'gst_percent' => $item['gst_percent'],
                    'units_received' => $units,
                    'units_in_stock' => $units,
                    'rack_no' => $item['rack_no'] ?? null,
                ]);
            }

            $invoice->update(['subtotal' => round($subtotal, 2), 'tax' => round($tax, 2), 'total' => round($subtotal + $tax, 2)]);

            return $invoice;
        });

        return response()->json($invoice->load(['stockist', 'batches.medicine:id,name']), 201);
    }

    // ---- Stock ----

    /** Every batch on the shelf, soonest expiry first. Empty batches are hidden unless asked for. */
    public function stock(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));

        return response()->json(
            StockBatch::query()
                ->with(['medicine:id,name,reorder_level', 'invoice:id,invoice_no'])
                ->when(! $request->boolean('show_empty'), fn ($q) => $q->where('units_in_stock', '>', 0))
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('batch_no', 'like', "{$term}%")
                    ->orWhereHas('medicine', fn ($m) => $m->where('name', 'like', "%{$term}%"))))
                ->orderBy('expiry')->orderBy('id')
                ->limit(1000)
                ->get()
        );
    }

    /** Medicines matching the typed text that have sellable stock, with their batches. */
    public function sellSearch(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        return response()->json(
            Medicine::where('is_active', true)
                ->where('name', 'like', "%{$term}%")
                ->whereHas('sellableBatches')
                ->with(['sellableBatches' => fn ($q) => $q->select('id', 'medicine_id', 'batch_no', 'expiry', 'unit_mrp', 'gst_percent', 'units_in_stock', 'rack_no')])
                ->orderBy('name')
                ->limit(12)
                ->get(['id', 'name', 'type'])
        );
    }

    /**
     * A patient's recent prescriptions, each medicine paired with the pharmacy item of the
     * same name and its sellable batches, so the bill can be started from the prescription.
     */
    public function prescriptions(Request $request): JsonResponse
    {
        $data = $request->validate(['patient_id' => ['required', 'exists:patients,id']]);

        $consultations = Consultation::where('patient_id', $data['patient_id'])
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subDays(60))
            ->whereHas('medicines')
            ->with(['medicines', 'doctor:id,name'])
            ->latest('completed_at')->limit(3)->get();

        $names = $consultations->flatMap->medicines->pluck('medicine')->map(fn ($name) => mb_strtolower(trim($name)))->unique();
        $stocked = Medicine::where('is_active', true)
            ->whereIn(DB::raw('LOWER(name)'), $names)
            ->with(['sellableBatches' => fn ($q) => $q->select('id', 'medicine_id', 'batch_no', 'expiry', 'unit_mrp', 'gst_percent', 'units_in_stock', 'rack_no')])
            ->get(['id', 'name', 'type'])
            ->keyBy(fn (Medicine $m) => mb_strtolower($m->name));

        return response()->json($consultations->map(fn (Consultation $c) => [
            'id' => $c->id,
            'completed_at' => $c->completed_at->format('Y-m-d H:i'),
            'doctor' => $c->doctor?->name,
            'medicines' => $c->medicines->map(fn ($item) => [
                'medicine' => $item->medicine,
                'directions' => collect([$item->dose, $item->frequency, $item->timing, $item->duration])->filter()->implode(' · '),
                'match' => $stocked->get(mb_strtolower(trim($item->medicine))),
            ]),
        ]));
    }

    // ---- Sales ----

    public function sales(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $term = trim((string) ($filters['q'] ?? ''));
        $from = \Illuminate\Support\Carbon::parse($filters['from'] ?? $filters['date'] ?? today())->startOfDay();
        $to = \Illuminate\Support\Carbon::parse($filters['to'] ?? $filters['from'] ?? $filters['date'] ?? today())->endOfDay();

        return response()->json(
            PharmacySale::query()
                ->when($term === '', fn ($q) => $q->whereBetween('sold_at', [$from, $to]))
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('bill_no', 'like', "{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%")
                    ->orWhere('customer_phone', 'like', "{$term}%")))
                ->withCount('items')
                ->latest('sold_at')->latest('id')
                ->limit(300)
                ->get()
        );
    }

    public function showSale(PharmacySale $sale): JsonResponse
    {
        return response()->json($sale->load(['items', 'patient:id,uhid,name', 'seller:id,name', 'payments.receiver:id,name', 'returns']));
    }

    /** Sells from specific batches, reducing their stock, and records the bill. */
    public function storeSale(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);

        $data = $request->validate([
            'patient_id' => ['nullable', 'exists:patients,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'referred_doctor' => ['nullable', 'string', 'max:255'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'paid' => ['required', 'numeric', 'min:0'],
            'payment_mode' => ['required', Rule::in(Payment::MODES)],
            'reference' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.stock_batch_id' => ['required', 'integer', 'distinct', 'exists:stock_batches,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'items.required' => 'Add at least one medicine to the bill.',
            'items.*.stock_batch_id.distinct' => 'The same batch is on the bill twice; combine the quantities.',
        ]);

        $sale = DB::transaction(function () use ($data, $request) {
            // Lock the batches so two counters cannot sell the same last strip.
            $batches = StockBatch::with('medicine:id,name')
                ->whereIn('id', array_column($data['items'], 'stock_batch_id'))
                ->lockForUpdate()->get()->keyBy('id');

            $lines = [];
            $subtotal = $tax = 0;
            foreach ($data['items'] as $index => $item) {
                $batch = $batches[$item['stock_batch_id']];
                $name = $batch->medicine->name;

                if ($batch->expiry->endOfDay()->isPast()) {
                    throw ValidationException::withMessages(["items.{$index}.stock_batch_id" => "{$name} batch {$batch->batch_no} has expired."]);
                }
                if ($item['quantity'] > $batch->units_in_stock) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => "Only {$batch->units_in_stock} of {$name} (batch {$batch->batch_no}) are in stock."]);
                }

                $discount = (float) ($item['discount_percent'] ?? 0);
                $amount = round($item['quantity'] * $batch->unit_mrp * (1 - $discount / 100), 2);
                $subtotal += $amount;
                // MRP already includes GST, so the tax is the part contained in the amount.
                $tax += $amount - $amount / (1 + $batch->gst_percent / 100);

                $lines[] = [
                    'medicine_id' => $batch->medicine_id, 'stock_batch_id' => $batch->id, 'medicine_name' => $name,
                    'batch_no' => $batch->batch_no, 'expiry' => $batch->expiry->toDateString(), 'quantity' => $item['quantity'],
                    'unit_price' => $batch->unit_mrp, 'unit_cost' => $batch->unitCost(), 'discount_percent' => $discount, 'gst_percent' => $batch->gst_percent, 'amount' => $amount,
                ];
                $batch->decrement('units_in_stock', $item['quantity']);
            }

            $discount = round((float) ($data['discount'] ?? 0), 2);
            if ($discount > $subtotal) {
                throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the bill amount.']);
            }

            $net = $subtotal - $discount;
            $total = round($net); // pharmacy bills are rounded to the nearest rupee
            if ($data['paid'] > $total) {
                throw ValidationException::withMessages(['paid' => "The bill is only ₹{$total}."]);
            }

            $sale = PharmacySale::create([
                'branch_id' => $request->user()->branch_id,
                'patient_id' => $data['patient_id'] ?? null,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'referred_doctor' => $data['referred_doctor'] ?? null,
                'sold_at' => now(),
                'subtotal' => round($subtotal, 2),
                'discount' => $discount,
                'tax' => round($subtotal > 0 ? $tax * ($net / $subtotal) : 0, 2),
                'round_off' => round($total - $net, 2),
                'total' => $total,
                'paid' => $data['paid'],
                'payment_mode' => $data['payment_mode'],
                'reference' => $data['reference'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $sale->items()->createMany($lines);
            if ($data['paid'] > 0) {
                $sale->payments()->create([
                    'type' => 'payment', 'amount' => $data['paid'], 'mode' => $data['payment_mode'],
                    'reference' => $data['reference'] ?? null, 'received_by' => $request->user()->id,
                ]);
            }

            return $sale;
        });

        return response()->json($sale->fresh()->load(['items', 'patient:id,uhid,name', 'seller:id,name']), 201);
    }

    private function authorizePharmacy(Request $request): void
    {
        abort_unless($request->user()->role->canRunPharmacy(), 403, 'You are not allowed to do this in the pharmacy.');
    }
}
