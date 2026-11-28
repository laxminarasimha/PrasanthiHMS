<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Consultation;
use App\Models\DrugIngredient;
use App\Models\Manufacturer;
use App\Models\Medicine;
use App\Models\Payment;
use App\Models\PharmacyChange;
use App\Models\PharmacySale;
use App\Models\PurchaseInvoice;
use App\Models\StockBatch;
use App\Models\Stockist;
use App\Models\StockMovement;
use App\Models\StockReturn;
use App\Support\Composition;
use App\Support\EarlierDay;
use App\Support\OrderMatch;
use App\Support\PharmacyOnIpd;
use App\Support\ShelfPlace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

        // For choosing a medicine on a purchase invoice, an order or opening stock: every medicine in use, however
        // many there are, with only what those forms fill in from it. The fuller list below stops at 500.
        if ($request->boolean('list')) {
            return response()->json(Medicine::where('is_active', true)->orderBy('name')->get(['id', 'name', 'composition', 'type', 'manufacturer', 'units_per_pack', 'gst_percent', 'hsn_code', 'rack_no', 'box_no']));
        }

        return response()->json(
            Medicine::query()
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('manufacturer', 'like', "%{$term}%")->orWhere('composition', 'like', "%{$term}%")))
                ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
                ->when($request->query('category'), fn ($q, $v) => $q->where('category', $v))
                ->when($request->query('ved'), fn ($q, $v) => $q->where('ved', $v))
                ->when($request->query('sde'), fn ($q, $v) => $q->where('sde', $v))
                ->when($request->query('fsn'), fn ($q, $v) => $q->where('fsn', $v))
                ->withSum(['batches as units_in_stock' => fn ($q) => $q->whereDate('expiry', '>=', today())], 'units_in_stock')
                ->orderBy('name')
                ->limit(500)
                ->get()
                ->each(fn (Medicine $m) => $m->units_in_stock = (int) $m->units_in_stock)
        );
    }

    /**
     * Every medicine in use with what is needed to choose where to order it: what it contains, how
     * much is on the shelf, and each supplier it has been bought from, with the last rate and date.
     * Brands that contain the same thing are options for one another; the screen groups them.
     */
    public function medicineOptions(): JsonResponse
    {
        // Each purchase of each medicine, newest first, so the first one seen per supplier is the latest.
        $bought = DB::table('stock_batches as b')
            ->join('purchase_invoices as i', 'i.id', '=', 'b.purchase_invoice_id')
            ->join('stockists as s', 's.id', '=', 'i.stockist_id')
            ->orderByDesc('i.invoice_date')->orderByDesc('b.id')
            ->get(['b.medicine_id', 'i.id as invoice_id', 'i.stockist_id', 's.name as stockist', 'i.invoice_date', 'b.pack_price', 'b.pack_mrp', 'b.units_per_pack'])
            ->groupBy('medicine_id');
        $stockists = Stockist::pluck('name', 'id');

        $medicines = Medicine::where('is_active', true)
            ->with('ingredients:id,name')
            ->withSum('batches as on_shelf', 'units_in_stock')
            ->orderBy('name')->limit(5000)
            ->get(['id', 'name', 'composition', 'type', 'manufacturer', 'units_per_pack', 'preferred_stockist_id'])
            ->map(function (Medicine $m) use ($bought, $stockists) {
                $suppliers = ($bought[$m->id] ?? collect())->groupBy('stockist_id')->map(function ($rows) {
                    $last = $rows->first();

                    return [
                        'stockist_id' => (int) $last->stockist_id, 'name' => $last->stockist, 'times' => $rows->unique('invoice_id')->count(),
                        'last_on' => substr((string) $last->invoice_date, 0, 10), 'last_rate' => (float) $last->pack_price, 'last_mrp' => (float) $last->pack_mrp, 'units_per_pack' => (int) $last->units_per_pack,
                    ];
                })->sortByDesc(fn ($s) => [$s['times'], $s['last_on']])->values();
                // Where it is usually ordered from: the supplier named on the medicine, else the one used most.
                $usual = $m->preferred_stockist_id ?: ($suppliers->first()['stockist_id'] ?? null);

                return [
                    'id' => $m->id, 'name' => $m->name, 'composition' => $m->composition, 'type' => $m->type, 'manufacturer' => $m->manufacturer,
                    'units_per_pack' => $m->units_per_pack, 'on_shelf' => (int) $m->on_shelf,
                    'drugs' => $m->ingredients->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'strength' => $d->pivot->strength])->values(),
                    'usual_stockist_id' => $usual ? (int) $usual : null, 'usual_stockist' => $usual ? ($stockists[$usual] ?? null) : null,
                    'suppliers' => $suppliers,
                ];
            });

        return response()->json([
            'medicines' => $medicines,
            'compositions' => Medicine::whereNotNull('composition')->where('composition', '!=', '')->distinct()->orderBy('composition')->pluck('composition'),
            'drugs' => DrugIngredient::has('medicines')->orderBy('name')->pluck('name'),
        ]);
    }

    /** Says what a medicine contains, without opening its whole form: used to tag the list quickly. */
    public function setComposition(Request $request, Medicine $medicine): JsonResponse
    {
        $this->authorizePharmacy($request);
        $data = $request->validate(['composition' => ['present', 'nullable', 'string', 'max:255']]);
        $was = $medicine->composition;
        Composition::apply($medicine, $data['composition']);
        if ($was !== $medicine->composition) {
            PharmacyChange::create(['kind' => 'medicine', 'subject_id' => $medicine->id, 'label' => $medicine->name, 'summary' => 'Composition: '.($was ?? '—').' → '.($medicine->composition ?? '—'), 'created_by' => $request->user()->id]);
        }

        return response()->json($medicine->only(['id', 'name', 'composition']));
    }

    /**
     * Fills in compositions from a sheet of medicine names and what each contains. With `commit`
     * left off it only reports what it would do, line by line, so the sheet can be put right first.
     */
    public function importCompositions(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*.medicine' => ['nullable', 'string', 'max:255'],
            'rows.*.composition' => ['nullable', 'string', 'max:255'],
            'commit' => ['sometimes', 'boolean'],
        ], ['rows.required' => 'The sheet has no lines.', 'rows.max' => 'A sheet can have up to 5,000 lines. Split it into smaller sheets.']);

        $key = fn (?string $name) => mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $name)));
        $medicines = Medicine::get(['id', 'name', 'composition'])->keyBy(fn (Medicine $m) => $key($m->name));
        $seen = [];
        $lines = [];
        foreach (array_values($data['rows']) as $index => $row) {
            $name = preg_replace('/\s+/', ' ', trim((string) ($row['medicine'] ?? '')));
            $typed = trim((string) ($row['composition'] ?? ''));
            $medicine = $medicines->get($key($name));
            $line = ['line' => $index + 2, 'medicine' => $medicine?->name ?? $name, 'typed' => $typed, 'composition' => null, 'drugs' => [], 'was' => $medicine?->composition, 'state' => 'change', 'note' => null];
            if ($name === '' && $typed === '') {
                continue;
            } elseif ($name === '') {
                $line = ['state' => 'error', 'note' => 'The medicine name is missing.'] + $line;
            } elseif (! $medicine) {
                $line = ['state' => 'error', 'note' => 'This medicine is not in the medicines list. Check the spelling against the list.'] + $line;
            } elseif (isset($seen[$medicine->id])) {
                $line = ['state' => 'error', 'note' => 'The same medicine is also on line '.$seen[$medicine->id].'.'] + $line;
            } elseif ($typed === '') {
                // An empty box is "not filled in yet", never "wipe what is there".
                $line = ['state' => 'skip', 'note' => 'No composition given: left as it is.'] + $line;
            } else {
                $seen[$medicine->id] = $index + 2;
                $line['composition'] = Composition::standard($typed);
                $line['drugs'] = array_map(fn ($p) => trim($p['name'].' '.($p['strength'] ?? '')), Composition::parts($typed));
                $line['id'] = $medicine->id;
                if ($line['composition'] === null) {
                    $line = ['state' => 'error', 'note' => 'This composition could not be read.'] + $line;
                } elseif ($line['composition'] === $medicine->composition) {
                    $line = ['state' => 'same', 'note' => 'Already set to this.'] + $line;
                }
            }
            $lines[] = $line;
        }

        $count = fn (string $state) => count(array_filter($lines, fn ($l) => $l['state'] === $state));
        $totals = ['lines' => count($lines), 'change' => $count('change'), 'same' => $count('same'), 'skip' => $count('skip'), 'error' => $count('error')];
        $saved = 0;
        if (($data['commit'] ?? false) && $totals['change'] > 0) {
            DB::transaction(function () use ($lines, $request, &$saved) {
                foreach ($lines as $line) {
                    if ($line['state'] !== 'change') {
                        continue;
                    }
                    $medicine = Medicine::find($line['id']);
                    Composition::apply($medicine, $line['typed']);
                    PharmacyChange::create(['kind' => 'medicine', 'subject_id' => $medicine->id, 'label' => $medicine->name, 'summary' => 'Composition: '.($line['was'] ?? '—').' → '.$medicine->composition.' (from a sheet)', 'created_by' => $request->user()->id]);
                    $saved++;
                }
            });
        }

        return response()->json(['lines' => $lines, 'totals' => $totals, 'saved' => $saved]);
    }

    public function storeMedicine(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);

        $data = $this->medicineData($request);
        $this->rememberManufacturer($data['manufacturer'] ?? null);

        $medicine = Medicine::create(Arr::except($data, ['rack_no', 'box_no']));
        Composition::apply($medicine, $data['composition'] ?? null);
        ShelfPlace::set($medicine, $data['rack_no'] ?? null, $data['box_no'] ?? null);

        return response()->json($medicine, 201);
    }

    public function updateMedicine(Request $request, Medicine $medicine): JsonResponse
    {
        $this->authorizePharmacy($request);
        $data = $this->medicineData($request, $medicine);
        $this->rememberManufacturer($data['manufacturer'] ?? null);

        // Keep a note of what was changed, for the "medicine data updated" report.
        $labels = ['name' => 'Name', 'composition' => 'Composition', 'type' => 'Type', 'manufacturer' => 'Manufacturer', 'category' => 'Category', 'sub_category' => 'Sub category', 'ved' => 'VED class', 'sde' => 'SDE class', 'fsn' => 'FSN class', 'is_scheduled' => 'Scheduled drug', 'hsn_code' => 'HSN code', 'gst_percent' => 'GST %', 'units_per_pack' => 'Units per pack', 'reorder_level' => 'Reorder level', 'is_active' => 'In use'];
        $labels += ['rack_no' => 'Rack', 'box_no' => 'Box'];
        $before = $medicine->only(array_keys($labels));
        $label = $medicine->name;
        $medicine->update(Arr::except($data, ['rack_no', 'box_no']));
        // The place is only touched when the form sent one, and the stock on the shelf moves with it.
        if (array_key_exists('rack_no', $data)) {
            ShelfPlace::set($medicine, $data['rack_no'], $data['box_no'] ?? null);
        }
        if (array_key_exists('composition', $data)) {
            Composition::apply($medicine, $data['composition']);
        }
        $changes = PharmacyChange::differences($labels, $before, $medicine->only(array_keys($labels)));
        if ($changes) {
            PharmacyChange::create(['kind' => 'medicine', 'subject_id' => $medicine->id, 'label' => $label, 'summary' => implode("\n", $changes), 'created_by' => $request->user()->id]);
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
            'composition' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:30'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'sub_category' => ['nullable', 'string', 'max:100'],
            'ved' => ['nullable', Rule::in(['V', 'E', 'D'])],
            'sde' => ['nullable', Rule::in(['S', 'D', 'E'])],
            'fsn' => ['nullable', Rule::in(['F', 'S', 'N'])],
            'is_scheduled' => ['sometimes', 'boolean'],
            'hsn_code' => ['nullable', 'string', 'max:20'],
            'gst_percent' => ['required', 'numeric', 'min:0', 'max:40'],
            'units_per_pack' => ['required', 'integer', 'min:1', 'max:10000'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'desired_stock' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'preferred_stockist_id' => ['nullable', 'exists:stockists,id'],
            'is_active' => ['sometimes', 'boolean'],
            'rack_no' => ['sometimes', 'nullable', 'string', 'max:30'],
            'box_no' => ['sometimes', 'nullable', 'string', 'max:30'],
        ], ['name.unique' => 'A medicine with this name is already in the list.']) + ['reorder_level' => 0];
    }

    private function rememberManufacturer(?string $name): void
    {
        if (filled($name)) {
            Manufacturer::firstOrCreate(['name' => trim($name)]);
        }
    }

    // ---- Stockists (suppliers) ----

    public function stockists(): JsonResponse
    {
        // What each supplier has billed, been paid and been sent back, so the amount still owed is plain.
        $billed = PurchaseInvoice::selectRaw('stockist_id, sum(total) as billed, sum(paid) as paid, count(*) as invoices')->groupBy('stockist_id')->get()->keyBy('stockist_id');
        $returned = StockReturn::selectRaw('stockist_id, sum(total) as returned')->groupBy('stockist_id')->pluck('returned', 'stockist_id');

        return response()->json(Stockist::orderBy('name')->get()->map(function (Stockist $s) use ($billed, $returned) {
            $b = round((float) ($billed[$s->id]->billed ?? 0), 2);
            $p = round((float) ($billed[$s->id]->paid ?? 0), 2);
            $r = round((float) ($returned[$s->id] ?? 0), 2);

            return $s->toArray() + ['invoices' => (int) ($billed[$s->id]->invoices ?? 0), 'billed' => $b, 'paid' => $p, 'returned' => $r, 'balance' => round($b - $p - $r, 2)];
        }));
    }

    /** One stockist with every bill from them and everything sent back, newest first: the stockist's own page. */
    public function showStockist(Stockist $stockist): JsonResponse
    {
        $invoices = PurchaseInvoice::where('stockist_id', $stockist->id)->withCount('batches')->latest('invoice_date')->latest('id')
            ->get(['id', 'invoice_no', 'invoice_date', 'due_date', 'total', 'paid', 'remarks']);
        $returns = StockReturn::where('stockist_id', $stockist->id)->latest('returned_on')->latest('id')->get(['id', 'return_no', 'returned_on', 'total', 'remarks']);
        $billed = round((float) $invoices->sum('total'), 2);
        $paid = round((float) $invoices->sum('paid'), 2);
        $returned = round((float) $returns->sum('total'), 2);

        return response()->json([
            'stockist' => $stockist,
            'totals' => ['invoices' => $invoices->count(), 'billed' => $billed, 'paid' => $paid, 'returned' => $returned, 'balance' => round($billed - $paid - $returned, 2)],
            'invoices' => $invoices, 'returns' => $returns,
        ]);
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
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'lead_days' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'credit_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
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
        return response()->json($purchase->present());
    }

    /** Records a supplier invoice and puts every line into stock as its own batch. */
    public function storePurchase(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);

        $data = $request->validate([
            'stockist_id' => ['required', 'exists:stockists,id'],
            'invoice_no' => ['required', 'string', 'max:50', Rule::unique('purchase_invoices')->where('stockist_id', $request->input('stockist_id'))],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            // The order this delivery answers, if there was one: it must be an approved order to the same supplier.
            'purchase_order_id' => ['nullable', Rule::exists('purchase_orders', 'id')->where('stockist_id', $request->input('stockist_id'))->where('status', 'open')],
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
            'items.*.box_no' => ['nullable', 'string', 'max:30'],
            'items.*.hsn_code' => ['nullable', 'string', 'max:20'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_on_free' => ['nullable', 'boolean'],
            'other_charges' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'cash_discount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'round_off' => ['nullable', 'numeric', 'between:-10,10'],
        ], [
            'stockist_id.required' => 'Choose the stockist the invoice is from.',
            'invoice_no.unique' => 'This invoice number is already entered for this stockist.',
            'purchase_order_id.exists' => 'Choose an approved, open order placed with this stockist.',
            'due_date.after_or_equal' => 'The due date cannot be before the invoice date.',
            'items.required' => 'Add at least one medicine to the invoice.',
            'round_off.between' => 'Rounding is a few paise or rupees either way, not more than ₹10.',
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
                'due_date' => $data['due_date'] ?? null, 'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'other_charges' => (float) ($data['other_charges'] ?? 0), 'cash_discount' => (float) ($data['cash_discount'] ?? 0), 'round_off' => (float) ($data['round_off'] ?? 0),
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['items'] as $index => $item) {
                $free = (int) ($item['free_packs'] ?? 0);
                $discount = (float) ($item['discount_percent'] ?? 0);
                $units = ($item['packs'] + $free) * $item['units_per_pack'];
                // The stockist charges for the paid packs only, less any trade discount, plus GST.
                if ((float) ($item['discount_amount'] ?? 0) > $item['packs'] * $item['pack_price'] * (1 - $discount / 100)) {
                    throw ValidationException::withMessages(["items.{$index}.discount_amount" => 'Line '.($index + 1).': the discount is more than the line is worth.']);
                }

                $received = $invoice->batches()->create([
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
                    'box_no' => $item['box_no'] ?? null,
                    'hsn_code' => $item['hsn_code'] ?? null,
                    'discount_amount' => (float) ($item['discount_amount'] ?? 0),
                    'tax_on_free' => (bool) ($item['tax_on_free'] ?? false),
                ]);
                StockMovement::record($received, $units, 'purchase', $invoice->invoice_no);
            }

            $figures = $invoice->figures();
            if ($figures['total'] < 0) {
                throw ValidationException::withMessages(['cash_discount' => 'The cash discount is more than the invoice is worth.']);
            }
            $invoice->update($figures);
            // An order whose every line has now come in full is closed as received; one that is short stays open.
            OrderMatch::settle($invoice->order);

            return $invoice;
        });

        return response()->json($invoice->present(), 201);
    }

    // ---- Stock ----

    /** Every batch on the shelf, soonest expiry first. Empty batches are hidden unless asked for. */
    public function stock(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));

        $batches = StockBatch::query()
            ->with(['medicine:id,name,reorder_level,type,composition,manufacturer', 'invoice:id,invoice_no'])
            ->when(! $request->boolean('show_empty'), fn ($q) => $q->where('units_in_stock', '>', 0))
            // Found by its batch, its own name, or a drug inside it.
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('batch_no', 'like', "{$term}%")
                ->orWhereHas('medicine', fn ($m) => $m->where('name', 'like', "%{$term}%")->orWhere('composition', 'like', "%{$term}%"))))
            ->orderBy('expiry')->orderBy('id')
            ->limit(3000)
            ->get();

        // What the pharmacy can still sell of each medicine across all its batches, whichever of them are listed:
        // the figure the low-stock level is held against.
        $sellable = StockBatch::whereIn('medicine_id', $batches->pluck('medicine_id')->unique())->whereDate('expiry', '>=', today())
            ->selectRaw('medicine_id, sum(units_in_stock) as units')->groupBy('medicine_id')->pluck('units', 'medicine_id');

        return response()->json($batches->each(fn (StockBatch $b) => $b->setAttribute('medicine_units', (int) ($sellable[$b->medicine_id] ?? 0))));
    }

    /**
     * Every medicine in use with where it is kept and how much of it is on the shelf: for arranging the racks,
     * finding what has no place yet, and printing what belongs in each rack.
     */
    public function shelves(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);

        return response()->json(
            Medicine::where('is_active', true)
                ->withSum(['batches as units_in_stock' => fn ($q) => $q->whereDate('expiry', '>=', today())], 'units_in_stock')
                ->orderBy('name')->get(['id', 'name', 'type', 'rack_no', 'box_no'])
                ->map(fn (Medicine $m) => ['id' => $m->id, 'name' => $m->name, 'type' => $m->type, 'rack_no' => $m->rack_no, 'box_no' => $m->box_no, 'units_in_stock' => (int) $m->units_in_stock])
        );
    }

    /** Puts medicines in their racks and boxes, many at a time. The stock of each moves on record with it. */
    public function placeMedicines(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);
        $data = $request->validate([
            'places' => ['required', 'array', 'min:1', 'max:1000'],
            'places.*.medicine_id' => ['required', 'integer', 'distinct', 'exists:medicines,id'],
            'places.*.rack_no' => ['nullable', 'string', 'max:30'],
            'places.*.box_no' => ['nullable', 'string', 'max:30'],
        ]);

        $changed = DB::transaction(function () use ($data, $request) {
            $medicines = Medicine::whereIn('id', array_column($data['places'], 'medicine_id'))->get()->keyBy('id');
            $changed = 0;
            foreach ($data['places'] as $place) {
                $medicine = $medicines[$place['medicine_id']];
                $was = ShelfPlace::label($medicine->rack_no, $medicine->box_no);
                ShelfPlace::set($medicine, $place['rack_no'] ?? null, $place['box_no'] ?? null);
                $now = ShelfPlace::label($medicine->rack_no, $medicine->box_no);
                if ($was !== $now) {
                    $changed++;
                    PharmacyChange::create(['kind' => 'medicine', 'subject_id' => $medicine->id, 'label' => $medicine->name, 'summary' => "Kept at: {$was} → {$now}", 'created_by' => $request->user()->id]);
                }
            }

            return $changed;
        });

        return response()->json(['changed' => $changed]);
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
                ->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('composition', 'like', "%{$term}%"))
                ->whereHas('sellableBatches')
                ->with(['sellableBatches' => fn ($q) => $q->select('id', 'medicine_id', 'batch_no', 'expiry', 'unit_mrp', 'gst_percent', 'units_in_stock', 'rack_no', 'box_no')])
                ->orderBy('name')
                ->limit(12)
                ->get(['id', 'name', 'type', 'composition'])
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
            ->with(['sellableBatches' => fn ($q) => $q->select('id', 'medicine_id', 'batch_no', 'expiry', 'unit_mrp', 'gst_percent', 'units_in_stock', 'rack_no', 'box_no')])
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
        $from = Carbon::parse($filters['from'] ?? $filters['date'] ?? today())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? $filters['from'] ?? $filters['date'] ?? today())->endOfDay();

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
        return response()->json($sale->load(['items', 'patient:id,uhid,title,name', 'seller:id,name', 'payments.receiver:id,name', 'returns']));
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
            // An inpatient's medicines: what is not paid at the counter goes on this IPD case's bill in the same step.
            'admission_id' => ['nullable', 'integer', 'exists:admissions,id'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.stock_batch_id' => ['required', 'integer', 'distinct', 'exists:stock_batches,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // The day the medicines were given, when a bill that was missed is being entered later.
            'sold_on' => EarlierDay::rule(),
        ], [
            'items.required' => 'Add at least one medicine to the bill.',
            'items.*.stock_batch_id.distinct' => 'The same batch is on the bill twice; combine the quantities.',
        ] + EarlierDay::messages('sold_on'));
        $at = EarlierDay::at($data['sold_on'] ?? null);

        $sale = DB::transaction(function () use ($data, $request, $at) {
            // The case is held while this is saved, so it cannot be closed or its bill approved half-way through.
            $admission = isset($data['admission_id']) ? Admission::whereKey($data['admission_id'])->lockForUpdate()->first() : null;
            if ($admission) {
                if ($admission->status !== 'admitted') {
                    throw ValidationException::withMessages(['admission_id' => 'This patient is no longer admitted. Take the money at the counter, or reopen the case.']);
                }
                if ((int) $admission->patient_id !== (int) ($data['patient_id'] ?? 0)) {
                    throw ValidationException::withMessages(['admission_id' => 'That IPD case belongs to another patient.']);
                }
                if ($admission->bill_approved_at) {
                    throw ValidationException::withMessages(['admission_id' => "The bill of {$admission->ipd_no} has been approved, so nothing more can go on it. Withdraw the approval there, or take the money at the counter."]);
                }
                if ($at && $at->toDateString() < $admission->admitted_at->toDateString()) {
                    throw ValidationException::withMessages(['sold_on' => "This patient was admitted on {$admission->admitted_at->format('j M Y')}, so nothing of an earlier day can go on {$admission->ipd_no}."]);
                }
            }

            // Lock the batches so two counters cannot sell the same last strip.
            $batches = StockBatch::with('medicine:id,name')
                ->whereIn('id', array_column($data['items'], 'stock_batch_id'))
                ->lockForUpdate()->get()->keyBy('id');

            $lines = [];
            $subtotal = $tax = 0;
            $moved = [];
            foreach ($data['items'] as $index => $item) {
                $batch = $batches[$item['stock_batch_id']];
                $name = $batch->medicine->name;

                // What matters is whether it was in date on the day it was given.
                if ($batch->expiry->endOfDay()->lt($at ?? now())) {
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
                    'unit_price' => $batch->unit_mrp, 'unit_cost' => $batch->effectiveUnitCost(), 'discount_percent' => $discount, 'gst_percent' => $batch->gst_percent, 'amount' => $amount,
                ];
                $moved[] = $batch->move(-$item['quantity'], 'sale')->id;
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
                'sold_at' => $at ?? now(),
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
            // The ledger lines written as each batch went down now say which bill they were for.
            StockMovement::whereKey($moved)->update(['reference' => $sale->bill_no]);
            if ($data['paid'] > 0) {
                // Money taken with a bill of an earlier day was taken on that day.
                EarlierDay::date($sale->payments()->make([
                    'type' => 'payment', 'amount' => $data['paid'], 'mode' => $data['payment_mode'],
                    'reference' => $data['reference'] ?? null, 'received_by' => $request->user()->id,
                ]), $at)->save();
            }
            if ($admission) {
                PharmacyOnIpd::put($admission, $sale->fresh(), $request->user()->id);
            }

            return $sale;
        });
        EarlierDay::log($at, $sale->patient_id, $sale, 'Pharmacy bill '.$sale->fresh()->bill_no);

        return response()->json($sale->fresh()->load(['items', 'patient:id,uhid,title,name', 'seller:id,name']), 201);
    }

    /**
     * The patients admitted now, for the counter to pick from when the ward sends for medicines:
     * who they are, where they lie, and whether their bill can still take a charge.
     */
    public function inpatients(Request $request): JsonResponse
    {
        $this->authorizePharmacy($request);

        return response()->json(
            Admission::where('status', 'admitted')->with(['patient:id,uhid,title,name,gender,date_of_birth,mobile', 'bed:id,ward,bed_no', 'doctor:id,name'])
                ->orderBy('admitted_at')->get()
                ->map(fn (Admission $a) => [
                    'admission_id' => $a->id, 'ipd_no' => $a->ipd_no, 'patient' => $a->patient,
                    'bed' => $a->bed ? "{$a->bed->ward} · Bed {$a->bed->bed_no}" : null, 'doctor' => $a->doctor?->name,
                    'bill_approved' => $a->bill_approved_at !== null,
                ])
        );
    }

    private function authorizePharmacy(Request $request): void
    {
        abort_unless($request->user()->mayInPharmacy($request), 403, 'You are not allowed to do this in the pharmacy.');
    }
}
