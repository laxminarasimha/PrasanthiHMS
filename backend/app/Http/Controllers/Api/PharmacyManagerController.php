<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Medicine;
use App\Models\PurchaseOrder;
use App\Models\Stockist;
use App\Support\PharmacyDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

        $actions = collect([
            $line('order', 'danger', $out, $say($out, 'medicine is', 'medicines are').' out of stock or will run out before a delivery can arrive. Order today.'),
            $line('pay', 'danger', $overdue->count(), $say($overdue->count(), 'supplier invoice is', 'supplier invoices are').' past the due date, '.$rupees((float) $overdue->sum('owed')).' in all. Pay, or call the supplier.'),
            $line('expiry', 'danger', $expiry['expired']->count(), $say($expiry['expired']->count(), 'expired batch is', 'expired batches are').' still on the shelf. Take it off and return it to the supplier.'),
            $line('order', 'warn', $low, $say($low, 'medicine is', 'medicines are').' at or below the minimum. Add to the next order.'),
            $line('pay', 'warn', $dueSoon->count(), $say($dueSoon->count(), 'supplier invoice falls', 'supplier invoices fall').' due within a week, '.$rupees((float) $dueSoon->sum('owed')).' in all.'),
            $line('expiry', 'warn', $month->count(), $say($month->count(), 'batch expires', 'batches expire').' within 30 days, worth '.$rupees((float) $month->sum('value')).' at cost. Sell first, or return.'),
            $line('match', 'warn', $late, $say($late, 'order has', 'orders have').' not arrived by the day expected. Call the supplier.'),
            $line('match', 'note', $drafts, $say($drafts, 'suggested order is', 'suggested orders are').' waiting to be approved and sent.'),
            $line('stock', 'note', $never, $say($never, 'medicine in the list has', 'medicines in the list have').' never had stock entered. Until opening stock is in, nothing here is suggested for them.'),
        ])->where('count', '>', 0)->values();

        return response()->json([
            'today' => today()->toDateString(),
            'actions' => $actions,
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
     */
    public function makeOrders(Request $request): JsonResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.medicine_id' => ['required', 'integer', 'distinct', 'exists:medicines,id'],
            'lines.*.stockist_id' => ['required', 'integer', 'exists:stockists,id'],
            'lines.*.packs' => ['required', 'integer', 'min:1', 'max:100000'],
        ], ['lines.required' => 'Tick at least one medicine.', 'lines.*.stockist_id.required' => 'Choose a supplier for every ticked medicine.']);

        $medicines = Medicine::whereIn('id', array_column($data['lines'], 'medicine_id'))->get(['id', 'name', 'units_per_pack'])->keyBy('id');
        $stockists = Stockist::whereIn('id', array_column($data['lines'], 'stockist_id'))->get(['id', 'name', 'lead_days'])->keyBy('id');

        $orders = DB::transaction(fn () => collect($data['lines'])->groupBy('stockist_id')->map(function ($lines, $stockistId) use ($medicines, $stockists, $request) {
            $order = PurchaseOrder::create([
                'stockist_id' => $stockistId, 'order_date' => today()->toDateString(), 'status' => 'draft',
                'expected_on' => today()->addDays((int) $stockists[$stockistId]->lead_days)->toDateString(),
                'remarks' => 'Suggested from stock levels and recent sales', 'created_by' => $request->user()->id,
            ]);
            foreach ($lines as $line) {
                $medicine = $medicines[$line['medicine_id']];
                $order->items()->create(['medicine_id' => $medicine->id, 'medicine_name' => $medicine->name, 'units_per_pack' => $medicine->units_per_pack, 'packs' => $line['packs']]);
            }

            return ['id' => $order->id, 'order_no' => $order->fresh()->order_no, 'stockist' => $stockists[$stockistId]->name, 'items' => count($lines)];
        })->values());

        AuditLog::record('change', null, null, 'Made draft purchase orders from the reorder list · '.$orders->map(fn ($o) => "{$o['order_no']} to {$o['stockist']} ({$o['items']} ".($o['items'] === 1 ? 'medicine' : 'medicines').')')->implode(', '));

        return response()->json(['orders' => $orders], 201);
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
        abort_unless($request->user()->allows('canRunPharmacy'), 403, 'You are not allowed to do this in the pharmacy.');
    }
}
