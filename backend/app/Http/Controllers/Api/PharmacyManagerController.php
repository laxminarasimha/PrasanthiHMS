<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Medicine;
use App\Models\PurchaseOrder;
use App\Models\RepProduct;
use App\Models\StockCount;
use App\Models\Stockist;
use App\Support\PharmacyDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The pharmacy manager's daily screen: what to order, what expires, what is owed, and what does not match. */
class PharmacyManagerController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->guard($request);

        $reorder = PharmacyDesk::reorder();
        $expiry = PharmacyDesk::expiry();
        $payables = PharmacyDesk::payables();
        $unmatched = PharmacyDesk::unmatched();
        $slow = PharmacyDesk::slow();

        $soon = today()->addDays(7)->toDateString();
        $overdue = $payables['invoices']->where('days_overdue', '>', 0);
        $dueSoon = $payables['invoices']->filter(fn ($i) => $i['days_overdue'] <= 0 && $i['due_date'] <= $soon);
        $month = $expiry['expiring']->where('days_left', '<=', 30);
        $rupees = fn (float $amount) => '₹'.number_format($amount, 2);

        // What to do today, most pressing first. A line appears only when there is something to do.
        $say = fn (int $count, string $one, string $many) => $count === 1 ? $one : $many;
        $line = fn (string $key, string $tone, int $count, string $text) => ['key' => $key, 'tone' => $tone, 'count' => $count, 'text' => $text];
        $out = $reorder->whereIn('class', ['out', 'urgent'])->count();
        $low = $reorder->where('class', 'low')->count();
        $late = $unmatched['orders']->where('status', 'open')->count();
        $drafts = $unmatched['orders']->where('status', 'draft')->count();
        $never = PharmacyDesk::neverStocked();
        // Shelf counts handed in by the staff: nothing changes in stock until someone checks and approves them.
        $counts = StockCount::where('status', 'submitted')->count();

        $actions = collect([
            $line('order', 'danger', $out, $say($out, 'medicine is', 'medicines are').' out of stock or will run out before a delivery can arrive. Order today.'),
            $line('pay', 'danger', $overdue->count(), $say($overdue->count(), 'supplier invoice is', 'supplier invoices are').' past the due date, '.$rupees((float) $overdue->sum('owed')).' in all. Pay, or call the supplier.'),
            $line('expiry', 'danger', $expiry['expired']->count(), $say($expiry['expired']->count(), 'expired batch is', 'expired batches are').' still on the shelf. Take it off and return it to the supplier.'),
            $line('order', 'warn', $low, $say($low, 'medicine is', 'medicines are').' at or below the minimum. Add to the next order.'),
            $line('pay', 'warn', $dueSoon->count(), $say($dueSoon->count(), 'supplier invoice falls', 'supplier invoices fall').' due within a week, '.$rupees((float) $dueSoon->sum('owed')).' in all.'),
            $line('expiry', 'warn', $month->count(), $say($month->count(), 'batch expires', 'batches expire').' within 30 days, worth '.$rupees((float) $month->sum('value')).' at cost. Sell first, or return.'),
            $line('match', 'warn', $late, $say($late, 'order has', 'orders have').' not arrived by the day expected. Call the supplier.'),
            $line('audit', 'warn', $counts, $say($counts, 'stock count is', 'stock counts are').' waiting to be checked and approved. Open Stock audit.'),
            $line('match', 'note', $drafts, $say($drafts, 'suggested order is', 'suggested orders are').' waiting to be approved and sent.'),
            $line('stock', 'note', $never, $say($never, 'medicine in the list has', 'medicines in the list have').' never had stock entered. Until opening stock is in, nothing here is suggested for them.'),
        ])->where('count', '>', 0)->values();

        // How far the shelves have been labelled: of the medicines with stock to sell, how many have a rack.
        $inStock = Medicine::where('is_active', true)->whereHas('batches', fn ($q) => $q->where('units_in_stock', '>', 0)->whereDate('expiry', '>=', today()));
        $places = ['in_stock' => (clone $inStock)->count(), 'placed' => (clone $inStock)->whereNotNull('rack_no')->where('rack_no', '!=', '')->count()];

        return response()->json([
            'today' => today()->toDateString(),
            'actions' => $actions,
            'places' => $places,
            'never_stocked' => $never,
            'reorder' => $reorder,
            'expiring' => $expiry['expiring'],
            'expired' => $expiry['expired'],
            'payables' => $payables,
            'unmatched' => $unmatched,
            'slow' => $slow,
            'stockists' => Stockist::orderBy('name')->get(['id', 'name', 'lead_days', 'credit_days']),
            'rules' => [
                'sales_days' => PharmacyDesk::SALES_DAYS, 'safety_days' => PharmacyDesk::SAFETY_DAYS, 'cover_days' => PharmacyDesk::COVER_DAYS,
                'protect_days' => PharmacyDesk::PROTECT_DAYS, 'default_lead_days' => PharmacyDesk::DEFAULT_LEAD_DAYS,
                'expiry_watch_days' => PharmacyDesk::EXPIRY_WATCH_DAYS, 'slow_days' => PharmacyDesk::SLOW_DAYS,
            ],
        ]);
    }

    /**
     * Turns the chosen suggestions into purchase orders, one for each supplier. They are made as
     * drafts: nothing is ordered until someone approves the order.
     *
     * A line may name a brand from a company's catalogue instead of a medicine. It is ordered as the pharmacy's own
     * medicine of that name, from the supplier chosen on the line or else the stockist set for the company. The
     * pharmacy's medicines are only read here, never made or changed.
     */
    public function makeOrders(Request $request): JsonResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.medicine_id' => ['required_without:lines.*.rep_product_id', 'nullable', 'integer', 'distinct', 'exists:medicines,id'],
            'lines.*.rep_product_id' => ['nullable', 'integer', 'exists:rep_products,id', 'prohibits:lines.*.medicine_id'],
            'lines.*.stockist_id' => ['required_without:lines.*.rep_product_id', 'nullable', 'integer', 'exists:stockists,id'],
            'lines.*.packs' => ['required', 'integer', 'min:1', 'max:100000'],
            // Where the order was put together, for the note on the order: the reorder list, the options list or the catalogues.
            'from' => ['sometimes', Rule::in(['reorder', 'options', 'catalogue'])],
        ], [
            'lines.required' => 'Tick at least one medicine.',
            'lines.*.medicine_id.required_without' => 'Every line needs its medicine, or a brand from a catalogue.',
            'lines.*.stockist_id.required_without' => 'Choose a supplier for every ticked medicine.',
            'lines.*.rep_product_id.prohibits' => 'A line orders either a medicine or a brand from a catalogue, not both.',
            // A brand taken out of the catalogue, perhaps on another screen, after it was chosen.
            'lines.*.rep_product_id.exists' => 'That brand is no longer in the catalogue.',
            'lines.*.stockist_id.exists' => 'That supplier is not in the list of stockists. Choose another.',
        ]);
        $from = $data['from'] ?? 'reorder';
        $wanted = $this->resolveLines($data['lines']);

        $medicines = Medicine::whereIn('id', array_column($wanted, 'medicine_id'))->get(['id', 'name', 'units_per_pack'])->keyBy('id');
        $stockists = Stockist::whereIn('id', array_column($wanted, 'stockist_id'))->get(['id', 'name', 'lead_days'])->keyBy('id');
        $seen = [];
        foreach ($wanted as $index => $line) {
            // An order has one line for each medicine, as its invoices are matched against it.
            if (isset($seen[$line['medicine_id']])) {
                throw ValidationException::withMessages(["lines.{$index}.".($line['brand'] ? 'rep_product_id' : 'medicine_id') => "{$medicines[$line['medicine_id']]->name} is chosen twice. Order it once."]);
            }
            $seen[$line['medicine_id']] = true;
            if (! $stockists->has($line['stockist_id'])) {
                throw ValidationException::withMessages(["lines.{$index}.stockist_id" => 'Choose a supplier for '.($line['brand'] ?? $medicines[$line['medicine_id']]->name).'.']);
            }
        }

        $orders = DB::transaction(fn () => collect($wanted)->groupBy('stockist_id')->map(function ($lines, $stockistId) use ($medicines, $stockists, $request, $from) {
            $companies = $lines->pluck('company')->filter()->unique()->implode(', ');
            $order = PurchaseOrder::create([
                'stockist_id' => $stockistId, 'order_date' => today()->toDateString(), 'status' => 'draft',
                'expected_on' => today()->addDays((int) $stockists[$stockistId]->lead_days)->toDateString(),
                'remarks' => mb_substr(match ($from) {
                    'options' => 'Chosen from the options list',
                    'catalogue' => $companies !== '' ? "Chosen from the catalogue of {$companies}" : 'Chosen from the companies’ catalogues',
                    default => 'Suggested from stock levels and recent sales',
                }, 0, 500),
                'created_by' => $request->user()->id,
            ]);
            foreach ($lines as $line) {
                $medicine = $medicines[$line['medicine_id']];
                $order->items()->create(['medicine_id' => $medicine->id, 'medicine_name' => $medicine->name, 'units_per_pack' => $medicine->units_per_pack, 'packs' => $line['packs']]);
            }

            return ['id' => $order->id, 'order_no' => $order->fresh()->order_no, 'stockist' => $stockists[$stockistId]->name, 'items' => count($lines)];
        })->values());

        $source = ['options' => 'the options list', 'catalogue' => 'the companies’ catalogues'][$from] ?? 'the reorder list';
        AuditLog::record('change', null, null, "Made draft purchase orders from {$source} · ".$orders->map(fn ($o) => "{$o['order_no']} to {$o['stockist']} ({$o['items']} ".($o['items'] === 1 ? 'medicine' : 'medicines').')')->implode(', '));

        return response()->json(['orders' => $orders], 201);
    }

    /**
     * Each line as a medicine and a supplier. A line naming a catalogue brand takes the pharmacy's medicine it is
     * linked to by name, and the supplier chosen on the line or else its company's stockist. A brand the pharmacy
     * does not keep under that name, one whose medicine is not in use, or one with no supplier, is refused, saying why.
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return array<int|string, array{medicine_id: int, stockist_id: int, packs: int, brand: ?string, company: ?string}>
     */
    private function resolveLines(array $lines): array
    {
        $brands = RepProduct::with(['company:id,name,stockist_id', 'medicine:id,name,is_active'])
            ->whereIn('id', array_filter(array_column($lines, 'rep_product_id')))->get()->keyBy('id');
        // Validated lines come back in the order their fields were checked; taken in the order they were sent, a message names the right line.
        ksort($lines);

        $wanted = [];
        foreach ($lines as $index => $line) {
            $brand = empty($line['rep_product_id']) ? null : $brands->get($line['rep_product_id']);
            if (! empty($line['rep_product_id']) && ! $brand) {
                throw ValidationException::withMessages(["lines.{$index}.rep_product_id" => 'That brand is no longer in the catalogue.']);
            }
            if ($brand && ! $brand->medicine) {
                throw ValidationException::withMessages(["lines.{$index}.rep_product_id" => "{$brand->name} is not in the pharmacy’s medicines under this name, so it cannot be ordered here."]);
            }
            if ($brand && ! $brand->medicine->is_active) {
                throw ValidationException::withMessages(["lines.{$index}.rep_product_id" => "{$brand->medicine->name} is marked as not in use in the pharmacy, so it cannot be ordered."]);
            }
            $stockistId = $line['stockist_id'] ?? $brand?->company?->stockist_id;
            if (! $stockistId) {
                throw ValidationException::withMessages(["lines.{$index}.stockist_id" => 'Choose a supplier for '.($brand ? "{$brand->name}: ".($brand->company?->name ?? 'its company').' has no stockist set.' : 'every ticked medicine.')]);
            }
            $wanted[$index] = [
                'medicine_id' => (int) ($brand ? $brand->medicine_id : $line['medicine_id']), 'stockist_id' => (int) $stockistId, 'packs' => (int) $line['packs'],
                'brand' => $brand?->name, 'company' => $brand?->company?->name,
            ];
        }

        return $wanted;
    }

    public function statement(Request $request, Stockist $stockist): JsonResponse
    {
        $this->guard($request);
        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = Carbon::parse($range['to'] ?? today())->startOfDay();
        $from = Carbon::parse($range['from'] ?? $to->copy()->subMonths(3))->startOfDay();

        return response()->json(PharmacyDesk::statement($stockist, $from, $to));
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()->mayInPharmacy($request), 403, 'You are not allowed to do this in the pharmacy.');
    }
}
