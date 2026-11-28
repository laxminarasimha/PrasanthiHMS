<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\PharmacyChange;
use App\Models\PharmacySale;
use App\Models\PharmacySaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\PhoneNotice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The pharmacy's stock audit: counting the shelf against the records, approving the differences,
 * and showing where stock went. Pharmacy staff count; someone trusted with the audit approves.
 */
class PharmacyAuditController extends Controller
{
    private const SPOT_SIZE = 15;

    /** A batch counted within this many days is left out of a spot check, so the checks move round the shelves. */
    private const RECENT_DAYS = 14;

    // ---- Overview ----

    public function overview(Request $request): JsonResponse
    {
        $this->mustAudit($request);
        $since = now()->subDays(30);

        $batches = StockBatch::where('units_in_stock', '>', 0)->get();
        $value = fn (Collection $some) => round($some->sum(fn (StockBatch $b) => $b->effectiveUnitCost() * $b->units_in_stock), 2);
        $countedLately = StockCountLine::whereNotNull('counted_at')->where('counted_at', '>=', $since)
            ->whereHas('stockCount', fn ($q) => $q->where('status', '!=', 'cancelled'))->pluck('stock_batch_id')->unique();
        $everCounted = StockCountLine::whereNotNull('counted_at')->whereHas('stockCount', fn ($q) => $q->where('status', '!=', 'cancelled'))->pluck('stock_batch_id')->unique();

        // Batches whose shelf record does not equal what the ledger adds up to: something changed stock outside the screens.
        $ledger = StockMovement::selectRaw('stock_batch_id, sum(units) as units')->groupBy('stock_batch_id')->pluck('units', 'stock_batch_id');
        $adrift = StockBatch::with('medicine:id,name')->get()->filter(fn (StockBatch $b) => (int) ($ledger[$b->id] ?? 0) !== (int) $b->units_in_stock)
            ->take(30)->map(fn (StockBatch $b) => [
                'batch_id' => $b->id, 'medicine_id' => $b->medicine_id, 'medicine' => $b->medicine?->name, 'batch_no' => $b->batch_no,
                'on_record' => (int) $b->units_in_stock, 'ledger' => (int) ($ledger[$b->id] ?? 0),
            ])->values();

        return response()->json([
            'stock' => ['value' => $value($batches), 'units' => (int) $batches->sum('units_in_stock'), 'batches' => $batches->count()],
            'coverage' => [
                'counted_value' => $value($batches->whereIn('id', $countedLately)),
                'counted_batches' => $batches->whereIn('id', $countedLately)->count(),
                'never_counted' => $batches->whereNotIn('id', $everCounted)->count(),
            ],
            'this_month' => $this->lossTotals(now()->startOfMonth(), now()),
            'last_month' => $this->lossTotals(now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()),
            'waiting' => StockCount::where('status', 'submitted')->with('starter:id,name')->withCount('lines')->latest('submitted_at')->get()->map(fn (StockCount $c) => $this->brief($c)),
            'in_progress' => StockCount::where('status', 'counting')->with('starter:id,name')->withCount('lines')->latest()->get()->map(fn (StockCount $c) => $this->brief($c)),
            'adrift' => $adrift,
            'signals' => $this->signals($since),
            'since' => $since->toDateString(),
        ]);
    }

    /**
     * What each person did in the pharmacy that is worth a second look: bills deleted, medicines taken back,
     * bills changed after they were made, and stock differences they reported.
     *
     * @return array<int, array<string, mixed>>
     */
    private function signals(Carbon $since): array
    {
        $rows = [];
        $add = function (?int $userId, string $key, float $count, float $value = 0) use (&$rows) {
            if ($userId === null) {
                return;
            }
            $rows[$userId] ??= ['bills' => 0, 'deleted' => 0, 'deleted_value' => 0.0, 'returns' => 0, 'returns_value' => 0.0, 'edits' => 0, 'reported' => 0];
            $rows[$userId][$key] += $count;
            if ($value) {
                $rows[$userId]["{$key}_value"] += $value;
            }
        };

        foreach (PharmacySale::where('sold_at', '>=', $since)->selectRaw('created_by, count(*) as n')->groupBy('created_by')->get() as $r) {
            $add($r->created_by, 'bills', (int) $r->n);
        }
        foreach (PharmacySale::whereNotNull('voided_at')->where('voided_at', '>=', $since)->selectRaw('voided_by, count(*) as n, sum(total) as value')->groupBy('voided_by')->get() as $r) {
            $add($r->voided_by, 'deleted', (int) $r->n, (float) $r->value);
        }
        foreach (PharmacySaleReturn::where('created_at', '>=', $since)->selectRaw('created_by, count(*) as n, sum(amount) as value')->groupBy('created_by')->get() as $r) {
            $add($r->created_by, 'returns', (int) $r->n, (float) $r->value);
        }
        foreach (PharmacyChange::where('kind', 'sale')->where('created_at', '>=', $since)->selectRaw('created_by, count(*) as n')->groupBy('created_by')->get() as $r) {
            $add($r->created_by, 'edits', (int) $r->n);
        }
        foreach (StockCount::where('scope', 'reported')->where('created_at', '>=', $since)->selectRaw('started_by, count(*) as n')->groupBy('started_by')->get() as $r) {
            $add($r->started_by, 'reported', (int) $r->n);
        }
        // Quantities changed straight on the stock list count against the person who changed them.
        foreach (StockAdjustment::whereNull('stock_count_id')->where('created_at', '>=', $since)->selectRaw('created_by, count(*) as n')->groupBy('created_by')->get() as $r) {
            $add($r->created_by, 'reported', (int) $r->n);
        }

        $names = User::whereIn('id', array_keys($rows))->pluck('name', 'id');

        return collect($rows)->map(fn ($row, $id) => ['user' => $names[$id] ?? 'Someone no longer on staff'] + $row
            + ['deleted_value' => round($row['deleted_value'], 2), 'returns_value' => round($row['returns_value'], 2)])
            ->sortByDesc(fn ($row) => $row['deleted'] + $row['returns'] + $row['edits'] + $row['reported'])->values()->all();
    }

    // ---- Stock counts ----

    /** What a count can cover, with how many batches each choice holds. */
    public function scopes(Request $request): JsonResponse
    {
        $this->mustWork($request);
        $batches = StockBatch::where('units_in_stock', '>', 0)->get(['id', 'rack_no']);
        $busy = $this->busyBatchIds();

        return response()->json([
            'in_stock' => $batches->count(),
            'racks' => $batches->groupBy(fn (StockBatch $b) => trim((string) $b->rack_no))->map(fn ($some, $rack) => ['rack' => (string) $rack, 'batches' => $some->count()])
                ->reject(fn ($r) => $r['rack'] === '')->sortBy('rack', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'no_rack' => $batches->filter(fn (StockBatch $b) => trim((string) $b->rack_no) === '')->count(),
            'on_open_counts' => $busy->count(),
            'spot_size' => self::SPOT_SIZE,
        ]);
    }

    public function counts(Request $request): JsonResponse
    {
        $this->mustWork($request);
        $status = $request->validate(['status' => ['nullable', Rule::in(['counting', 'submitted', 'approved', 'cancelled'])]])['status'] ?? null;

        return response()->json(
            StockCount::when($status, fn ($q) => $q->where('status', $status))
                ->with(['starter:id,name', 'approver:id,name'])->withCount('lines')->latest('id')->limit(100)->get()
                ->map(fn (StockCount $c) => $this->brief($c, $request->user()->allows('canAuditPharmacy')))
        );
    }

    /** Starts a count: the batches it covers are written down now, to be counted on the shelf. */
    public function storeCount(Request $request): JsonResponse
    {
        $this->mustWork($request);
        $data = $request->validate([
            'scope' => ['required', Rule::in(['all', 'rack', 'letters', 'spot', 'chosen'])],
            'rack' => ['required_if:scope,rack', 'nullable', 'string', 'max:60'],
            'from' => ['required_if:scope,letters', 'nullable', 'alpha', 'size:1'],
            'to' => ['required_if:scope,letters', 'nullable', 'alpha', 'size:1'],
            'medicine_ids' => ['required_if:scope,chosen', 'nullable', 'array', 'min:1', 'max:100'],
            'medicine_ids.*' => ['integer', 'exists:medicines,id'],
            'size' => ['nullable', 'integer', 'min:3', 'max:60'],
            'blind' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['rack.required_if' => 'Choose the rack to count.', 'medicine_ids.required_if' => 'Choose at least one medicine to count.']);

        $inStock = StockBatch::where('units_in_stock', '>', 0)->with('medicine:id,name')->whereNotIn('id', $this->busyBatchIds());
        [$batches, $title, $detail] = match ($data['scope']) {
            'all' => [$inStock->get(), 'Whole pharmacy', null],
            'rack' => [$data['rack'] === '-' ? $inStock->where(fn ($q) => $q->whereNull('rack_no')->orWhere('rack_no', ''))->get() : $inStock->where('rack_no', $data['rack'])->get(),
                $data['rack'] === '-' ? 'Batches with no rack' : "Rack {$data['rack']}", $data['rack']],
            'letters' => $this->byLetters($inStock->get(), $data['from'], $data['to']),
            'chosen' => [$inStock->whereIn('medicine_id', $data['medicine_ids'])->get(), 'Chosen medicines', null],
            'spot' => [$this->spotPick($inStock->get(), (int) ($data['size'] ?? self::SPOT_SIZE)), 'Spot check', null],
        };
        if ($batches->isEmpty()) {
            throw ValidationException::withMessages(['scope' => 'There is nothing in stock to count there, or it is already on a count that is still open.']);
        }

        $count = DB::transaction(function () use ($batches, $title, $detail, $data, $request) {
            $count = StockCount::create([
                'title' => $title, 'scope' => $data['scope'], 'scope_detail' => $detail, 'blind' => $data['blind'] ?? true, 'status' => 'counting',
                'notes' => $data['notes'] ?? null, 'started_by' => $request->user()->id,
            ]);
            $batches->each(fn (StockBatch $b) => $count->lines()->create($this->lineFor($b)));

            return $count;
        });

        return response()->json($this->present($count, $request), 201);
    }

    public function showCount(Request $request, StockCount $count): JsonResponse
    {
        $this->mustWork($request);

        return response()->json($this->present($count, $request));
    }

    /** Writes down what was counted for one batch. What the records say at that moment is kept beside it. */
    public function countLine(Request $request, StockCount $count, StockCountLine $line): JsonResponse
    {
        $this->mustWork($request);
        abort_unless($line->stock_count_id === $count->id, 404);
        $this->mustBe($count, 'counting', 'This count has been handed in. It can be counted again only if it is sent back.');

        $data = $request->validate([
            'counted_units' => ['present', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $line->update($data['counted_units'] === null
            ? ['counted_units' => null, 'expected_units' => null, 'counted_by' => null, 'counted_at' => null, 'note' => $data['note'] ?? $line->note]
            : [
                'counted_units' => $data['counted_units'],
                'expected_units' => (int) StockBatch::whereKey($line->stock_batch_id)->value('units_in_stock'),
                'counted_by' => $request->user()->id, 'counted_at' => now(), 'recount' => false, 'note' => $data['note'] ?? $line->note,
            ]);

        return response()->json($this->presentLine($line->fresh('counter:id,name'), $this->sees($count, $request)));
    }

    /** Adds a batch found on the shelf that the count did not list, such as one the records say is finished. */
    public function addLine(Request $request, StockCount $count): JsonResponse
    {
        $this->mustWork($request);
        $this->mustBe($count, 'counting', 'This count has been handed in.');
        $batch = StockBatch::with('medicine:id,name')->findOrFail($request->validate(['stock_batch_id' => ['required', 'integer']])['stock_batch_id']);

        if ($count->lines()->where('stock_batch_id', $batch->id)->exists()) {
            throw ValidationException::withMessages(['stock_batch_id' => 'That batch is already on this count.']);
        }
        if ($this->busyBatchIds()->contains($batch->id)) {
            throw ValidationException::withMessages(['stock_batch_id' => 'That batch is on another count that is still open.']);
        }
        $count->lines()->create($this->lineFor($batch));

        return response()->json($this->present($count, $request), 201);
    }

    /** Batches of a medicine that could be added to a count, including ones the records say are empty. */
    public function findBatches(Request $request): JsonResponse
    {
        $this->mustWork($request);
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);

        return response()->json(
            StockBatch::whereHas('medicine', fn ($q) => $q->where('name', 'like', "%{$term}%"))->orWhere('batch_no', 'like', "{$term}%")
                ->with('medicine:id,name')->orderByDesc('units_in_stock')->orderByDesc('expiry')->limit(20)->get()
                ->map(fn (StockBatch $b) => ['id' => $b->id, 'medicine' => $b->medicine?->name, 'batch_no' => $b->batch_no, 'expiry' => $b->expiry?->toDateString(), 'rack_no' => $b->rack_no])
        );
    }

    /** Hands the count in for checking. What was not counted is left as it is. */
    public function submit(Request $request, StockCount $count): JsonResponse
    {
        $this->mustWork($request);
        $this->mustBe($count, 'counting', 'This count has already been handed in.');
        if (! $count->lines()->whereNotNull('counted_units')->exists()) {
            throw ValidationException::withMessages(['count' => 'Nothing has been counted yet.']);
        }
        $count->update(['status' => 'submitted', 'submitted_by' => $request->user()->id, 'submitted_at' => now()]);
        PhoneNotice::stockCountHandedIn($count, $request->user());

        return response()->json($this->present($count, $request));
    }

    /** The checker's notes on the differences: the reason for each, and which to count again. */
    public function review(Request $request, StockCount $count): JsonResponse
    {
        $this->mustAudit($request);
        $this->mustBe($count, 'submitted', 'Only a count that has been handed in can be checked.');
        $this->saveReview($request, $count);

        return response()->json($this->present($count, $request));
    }

    /** Sends the count back to the shelf. Lines marked for a recount are cleared, to be counted afresh. */
    public function reopen(Request $request, StockCount $count): JsonResponse
    {
        $this->mustAudit($request);
        $this->mustBe($count, 'submitted', 'Only a count that has been handed in can be sent back.');
        $this->saveReview($request, $count);
        $count->lines()->where('recount', true)->update(['counted_units' => null, 'expected_units' => null, 'counted_by' => null, 'counted_at' => null]);
        $count->update(['status' => 'counting', 'submitted_by' => null, 'submitted_at' => null]);

        return response()->json($this->present($count, $request));
    }

    /**
     * Accepts the count. Each difference is put into the stock, with its reason, as a correction that
     * shows in the ledger and the missing-stock report. Sales made since the batch was counted stay as they are:
     * the difference is applied, not the counted number.
     */
    public function approve(Request $request, StockCount $count): JsonResponse
    {
        $this->mustAudit($request);

        DB::transaction(function () use ($count, $request) {
            // Held while it is applied, so approving twice at once (a double click) cannot put the differences into stock twice.
            $count = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->mustBe($count, 'submitted', 'Only a count that has been handed in can be approved.');
            $this->saveReview($request, $count);
            $lines = $count->lines()->whereNotNull('counted_units')->get();
            $unexplained = $lines->filter(fn (StockCountLine $l) => $l->difference() !== 0 && ! isset(StockCount::REASONS[$l->reason]));
            if ($unexplained->isNotEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Choose a reason for each difference first: '.$unexplained->take(5)->pluck('medicine_name')->implode(', ').($unexplained->count() > 5 ? ' and more' : '').'.']);
            }

            foreach ($lines as $line) {
                $difference = $line->difference();
                if ($difference === 0) {
                    $line->update(['applied_units' => 0]);

                    continue;
                }
                $applied = $this->correct($line->batch()->lockForUpdate()->first(), $difference, $line->reason, $line->note, $count, $request->user()->id);
                $line->update(['applied_units' => $applied]);
            }
            $count->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        });

        return response()->json($this->present($count->fresh(), $request));
    }

    public function cancel(Request $request, StockCount $count): JsonResponse
    {
        $this->mustWork($request);
        abort_unless($request->user()->allows('canAuditPharmacy') || ($count->started_by === $request->user()->id && $count->status === 'counting'), 403, 'Only the person who started this count, before handing it in, or someone who approves counts can cancel it.');
        if (! in_array($count->status, ['counting', 'submitted'], true)) {
            throw ValidationException::withMessages(['count' => 'This count is already closed.']);
        }
        $count->update(['status' => 'cancelled']);

        return response()->json($this->present($count, $request));
    }

    // ---- A single correction, outside a full count ----

    /**
     * Someone has counted one batch and it is not what the records say. Whoever works in the pharmacy corrects it
     * there and then; the ledger keeps who, when, by how much and what kind of difference it was.
     */
    public function correctBatch(Request $request, StockBatch $batch): JsonResponse
    {
        $this->mustWork($request);
        $data = $request->validate([
            'units_in_stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'category' => ['required', Rule::in(array_keys(StockCount::REASONS))],
            // The kind of difference is always chosen; a note in words is for when there is something to add.
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['category.required' => 'Choose what kind of difference this is.']);
        $data['reason'] = trim((string) ($data['reason'] ?? '')) ?: null;

        $result = DB::transaction(function () use ($batch, $data, $request) {
            $locked = StockBatch::whereKey($batch->id)->lockForUpdate()->first();
            $difference = $data['units_in_stock'] - $locked->units_in_stock;
            if ($difference === 0) {
                throw ValidationException::withMessages(['units_in_stock' => 'That is already the count for this batch.']);
            }

            // A batch being counted is corrected through that count, or the same difference would be taken off twice.
            if ($this->busyBatchIds()->contains($locked->id)) {
                throw ValidationException::withMessages(['units_in_stock' => 'This batch is on a stock count that is still open. Approve or cancel that count first.']);
            }

            // Anyone who works in the pharmacy changes the quantity there and then (the owner's choice, 2026-11-23).
            // Who changed it, by how much and why is kept in the ledger and counted against their name on the audit overview.
            $this->correct($locked, $difference, $data['category'], $data['reason'], null, $request->user()->id);

            return ['applied' => true];
        });

        return response()->json($result + ['batch' => $batch->fresh()->load(['medicine:id,name,reorder_level', 'invoice:id,invoice_no'])]);
    }

    // ---- Where stock went ----

    /** Differences found and written off or added, over a period: by reason, by medicine, and each one. */
    public function missing(Request $request): JsonResponse
    {
        $this->mustAudit($request);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($data['to'] ?? now()->toDateString())->endOfDay();

        $rows = StockAdjustment::whereBetween('created_at', [$from, $to])
            ->with(['medicine:id,name', 'user:id,name', 'stockCount:id,count_no'])->latest()->limit(2000)->get()
            ->map(function (StockAdjustment $a) {
                $units = $a->after - $a->before;
                // Corrections made before reasons were kept are told apart only by their direction.
                $category = $a->category ?? ($units < 0 ? 'missing' : 'found');

                return [
                    'id' => $a->id, 'on' => $a->created_at->toDateTimeString(), 'medicine_id' => $a->medicine_id, 'medicine' => $a->medicine?->name ?? '—',
                    'stock_batch_id' => $a->stock_batch_id, 'before' => $a->before, 'after' => $a->after, 'units' => $units,
                    'category' => $category, 'category_label' => StockCount::REASONS[$category], 'loss' => in_array($category, StockCount::LOSSES, true),
                    'reason' => $a->reason, 'count_no' => $a->stockCount?->count_no, 'by' => $a->user?->name,
                    'value' => round(abs($units) * (float) ($a->unit_cost ?? 0), 2), 'value_mrp' => round(abs($units) * (float) ($a->unit_mrp ?? 0), 2),
                ];
            });

        $losses = $rows->where('loss', true);

        return response()->json([
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'totals' => collect(StockCount::REASONS)->map(fn ($label, $key) => [
                'category' => $key, 'label' => $label, 'loss' => in_array($key, StockCount::LOSSES, true),
                // Net of what went up and what went down, so a batch mix-up, which moves stock between batches, comes to nothing.
                'units' => (int) abs($rows->where('category', $key)->sum('units')),
                'value' => round(abs($rows->where('category', $key)->sum(fn ($r) => $r['units'] < 0 ? -$r['value'] : $r['value'])), 2),
                'value_mrp' => round(abs($rows->where('category', $key)->sum(fn ($r) => $r['units'] < 0 ? -$r['value_mrp'] : $r['value_mrp'])), 2),
                'times' => $rows->where('category', $key)->count(),
            ])->values(),
            'loss_value' => round($losses->sum('value'), 2),
            'unexplained_value' => round($rows->where('category', 'missing')->sum('value'), 2),
            'by_medicine' => $losses->groupBy('medicine_id')->map(fn (Collection $some) => [
                'medicine_id' => $some->first()['medicine_id'], 'medicine' => $some->first()['medicine'],
                'units' => (int) abs($some->sum('units')), 'value' => round($some->sum('value'), 2), 'times' => $some->count(),
                'unexplained' => (int) abs($some->where('category', 'missing')->sum('units')), 'last_on' => $some->max('on'),
            ])->sortByDesc('value')->take(50)->values(),
            'rows' => $rows->take(300)->values(),
        ]);
    }

    /** Everything that happened to a medicine's batches, line by line with the balance after each. */
    public function history(Request $request): JsonResponse
    {
        $this->mustWork($request);
        $medicine = Medicine::findOrFail($request->validate(['medicine_id' => ['required', 'integer']])['medicine_id']);

        $batches = StockBatch::where('medicine_id', $medicine->id)->with('invoice:id,invoice_no')->orderByDesc('units_in_stock')->orderByDesc('id')->limit(60)->get();
        $moves = StockMovement::whereIn('stock_batch_id', $batches->pluck('id'))->with('user:id,name')->orderBy('id')->get()->groupBy('stock_batch_id');

        return response()->json([
            'medicine' => $medicine->only(['id', 'name', 'composition']),
            'batches' => $batches->map(fn (StockBatch $b) => [
                'id' => $b->id, 'batch_no' => $b->batch_no, 'expiry' => $b->expiry?->toDateString(), 'rack_no' => $b->rack_no,
                'units_in_stock' => (int) $b->units_in_stock, 'invoice_no' => $b->invoice?->invoice_no,
                'adds_up' => (int) ($moves[$b->id] ?? collect())->sum('units') === (int) $b->units_in_stock,
                'movements' => ($moves[$b->id] ?? collect())->map(fn (StockMovement $m) => [
                    'id' => $m->id, 'on' => $m->created_at->toDateTimeString(), 'kind' => $m->kind, 'label' => StockMovement::KINDS[$m->kind] ?? $m->kind,
                    'units' => $m->units, 'balance_after' => $m->balance_after, 'reference' => $m->reference, 'note' => $m->note, 'by' => $m->user?->name,
                ])->values(),
            ]),
        ]);
    }

    // ---- Shared ----

    /**
     * Puts a difference into a batch and leaves the trail for it: the ledger line and the correction record.
     * Stock never goes below nothing, so what is applied can be less than what was found short.
     */
    private function correct(StockBatch $batch, int $difference, string $category, ?string $note, ?StockCount $count, int $userId): int
    {
        $before = (int) $batch->units_in_stock;
        $applied = max(-$before, $difference);
        $label = StockCount::REASONS[$category];
        $reason = trim($label.($note ? ": {$note}" : ''));

        if ($applied !== 0) {
            $batch->move($applied, $count ? 'count' : 'correction', $count?->count_no, mb_substr($reason, 0, 255));
        }
        StockAdjustment::create([
            'stock_batch_id' => $batch->id, 'medicine_id' => $batch->medicine_id, 'before' => $before, 'after' => $before + $applied,
            'reason' => mb_substr($reason, 0, 255), 'category' => $category, 'stock_count_id' => $count?->id,
            'unit_cost' => $batch->effectiveUnitCost(), 'unit_mrp' => $batch->unit_mrp, 'created_by' => $userId,
        ]);

        return $applied;
    }

    private function saveReview(Request $request, StockCount $count): void
    {
        $data = $request->validate([
            'lines' => ['nullable', 'array'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.reason' => ['nullable', Rule::in(array_keys(StockCount::REASONS))],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
            'lines.*.recount' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $lines = $count->lines()->get()->keyBy('id');
        foreach ($data['lines'] ?? [] as $entry) {
            $lines->get($entry['id'])?->update(array_intersect_key($entry, array_flip(['reason', 'note', 'recount'])));
        }
        if (array_key_exists('notes', $data)) {
            $count->update(['notes' => $data['notes']]);
        }
    }

    /** Batches already on a count that is still open: a batch is counted on one sheet at a time. */
    private function busyBatchIds(): Collection
    {
        return StockCountLine::whereHas('stockCount', fn ($q) => $q->whereIn('status', ['counting', 'submitted']))->pluck('stock_batch_id');
    }

    /**
     * @return array{0: Collection, 1: string, 2: string}
     */
    private function byLetters(Collection $batches, string $from, string $to): array
    {
        [$from, $to] = [strtoupper(min($from, $to)), strtoupper(max($from, $to))];

        return [
            $batches->filter(fn (StockBatch $b) => ($first = strtoupper(mb_substr(trim((string) $b->medicine?->name), 0, 1))) >= $from && $first <= $to)->values(),
            $from === $to ? "Medicines starting with {$from}" : "Medicines {$from} to {$to}", "{$from}-{$to}",
        ];
    }

    /**
     * A handful of batches for a quick daily check: half are the costliest fast sellers not counted lately,
     * where a loss hurts most and is most likely; the other half are picked at random, so nothing is safe from a check.
     */
    private function spotPick(Collection $batches, int $size): Collection
    {
        $recent = StockCountLine::whereNotNull('counted_at')->where('counted_at', '>=', now()->subDays(self::RECENT_DAYS))->pluck('stock_batch_id');
        $fresh = $batches->whereNotIn('id', $recent)->values();
        $pool = $fresh->count() >= $size ? $fresh : $batches;

        $sold = DB::table('pharmacy_sale_items')->join('pharmacy_sales', 'pharmacy_sales.id', '=', 'pharmacy_sale_items.pharmacy_sale_id')
            ->where('pharmacy_sales.sold_at', '>=', now()->subDays(30))->whereNull('pharmacy_sales.voided_at')
            ->selectRaw('stock_batch_id, sum(quantity) as units')->groupBy('stock_batch_id')->pluck('units', 'stock_batch_id');

        $top = $pool->sortByDesc(fn (StockBatch $b) => $b->effectiveUnitCost() * $b->units_in_stock * (1 + (int) ($sold[$b->id] ?? 0)))->take((int) ceil($size / 2));
        $rest = $pool->whereNotIn('id', $top->pluck('id'));

        return $top->concat($rest->shuffle()->take($size - $top->count()))->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function lineFor(StockBatch $batch): array
    {
        return [
            'stock_batch_id' => $batch->id, 'medicine_id' => $batch->medicine_id, 'medicine_name' => $batch->medicine?->name ?? 'Medicine',
            'batch_no' => $batch->batch_no, 'expiry' => $batch->expiry?->toDateString(), 'rack_no' => $batch->rack_no,
            'unit_cost' => $batch->effectiveUnitCost(), 'unit_mrp' => $batch->unit_mrp,
        ];
    }

    /** Whether this person is shown what the records say: always once handed in to a checker, never to a counter on a blind count. */
    private function sees(StockCount $count, Request $request): bool
    {
        return $request->user()->allows('canAuditPharmacy') || ! $count->blind || $count->status === 'approved';
    }

    /**
     * @return array<string, mixed>
     */
    private function brief(StockCount $count, bool $withFigures = true): array
    {
        $lines = $count->lines()->get();
        $counted = $lines->whereNotNull('counted_units');
        $short = $counted->filter(fn (StockCountLine $l) => $l->difference() < 0);
        $extra = $counted->filter(fn (StockCountLine $l) => $l->difference() > 0);

        return [
            'id' => $count->id, 'count_no' => $count->count_no, 'title' => $count->title, 'scope' => $count->scope, 'status' => $count->status, 'blind' => $count->blind,
            'started_by' => $count->starter?->name, 'started_at' => $count->created_at->toDateTimeString(),
            'submitted_at' => $count->submitted_at?->toDateTimeString(), 'approved_by' => $count->approver?->name, 'approved_at' => $count->approved_at?->toDateTimeString(),
            'lines' => $lines->count(), 'counted' => $counted->count(),
        ] + ($withFigures ? [
            'short_lines' => $short->count(), 'short_units' => (int) abs($short->sum(fn (StockCountLine $l) => $l->difference())),
            'short_value' => round($short->sum(fn (StockCountLine $l) => abs($l->difference()) * $l->unit_cost), 2),
            'extra_lines' => $extra->count(), 'extra_units' => (int) $extra->sum(fn (StockCountLine $l) => $l->difference()),
            'extra_value' => round($extra->sum(fn (StockCountLine $l) => $l->difference() * $l->unit_cost), 2),
        ] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StockCount $count, Request $request): array
    {
        $count->loadMissing(['starter:id,name', 'approver:id,name', 'submitter:id,name']);
        $sees = $this->sees($count, $request);
        $lines = $count->lines()->with('counter:id,name')->get();
        $now = $sees ? StockBatch::whereIn('id', $lines->pluck('stock_batch_id'))->pluck('units_in_stock', 'id') : collect();

        return $this->brief($count, $sees) + [
            'notes' => $count->notes, 'submitted_by' => $count->submitter?->name, 'sees_records' => $sees,
            'can_approve' => $request->user()->allows('canAuditPharmacy'),
            'reasons' => StockCount::REASONS, 'losses' => StockCount::LOSSES,
            'rows' => $lines->map(fn (StockCountLine $l) => $this->presentLine($l, $sees, $now))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentLine(StockCountLine $line, bool $sees, ?Collection $now = null): array
    {
        return [
            'id' => $line->id, 'stock_batch_id' => $line->stock_batch_id, 'medicine_id' => $line->medicine_id, 'medicine' => $line->medicine_name,
            'batch_no' => $line->batch_no, 'expiry' => $line->expiry?->toDateString(), 'rack_no' => $line->rack_no,
            'counted_units' => $line->counted_units, 'counted_by' => $line->counter?->name, 'counted_at' => $line->counted_at?->toDateTimeString(),
            'recount' => $line->recount, 'note' => $line->note,
        ] + ($sees ? [
            'on_record' => $line->expected_units ?? (int) ($now[$line->stock_batch_id] ?? StockBatch::whereKey($line->stock_batch_id)->value('units_in_stock')),
            'difference' => $line->difference(), 'unit_cost' => $line->unit_cost, 'unit_mrp' => $line->unit_mrp,
            'value' => $line->difference() === null ? null : round(abs($line->difference()) * $line->unit_cost, 2),
            'reason' => $line->reason, 'applied_units' => $line->applied_units,
        ] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function lossTotals(Carbon $from, Carbon $to): array
    {
        $rows = StockAdjustment::whereBetween('created_at', [$from, $to])->get()->map(function (StockAdjustment $a) {
            $units = $a->after - $a->before;

            return ['category' => $a->category ?? ($units < 0 ? 'missing' : 'found'), 'value' => abs($units) * (float) ($a->unit_cost ?? 0)];
        });

        return [
            'loss_value' => round($rows->whereIn('category', StockCount::LOSSES)->sum('value'), 2),
            'unexplained_value' => round($rows->where('category', 'missing')->sum('value'), 2),
            'found_value' => round($rows->where('category', 'found')->sum('value'), 2),
            'corrections' => $rows->count(),
        ];
    }

    private function mustBe(StockCount $count, string $status, string $message): void
    {
        if ($count->status !== $status) {
            throw ValidationException::withMessages(['count' => $message]);
        }
    }

    private function mustWork(Request $request): void
    {
        abort_unless($request->user()->mayInPharmacy($request) || $request->user()->allows('canAuditPharmacy'), 403, 'You are not allowed to do this in the pharmacy.');
    }

    private function mustAudit(Request $request): void
    {
        abort_unless($request->user()->allows('canAuditPharmacy'), 403, 'Only someone trusted with the pharmacy stock audit can do this.');
    }
}
