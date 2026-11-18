<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\PurchasePayment;
use Illuminate\Support\Carbon;

/**
 * Money that left the hospital in a period: its day-to-day expenses, and what was paid to medicine suppliers.
 * The cash part of both comes out of the drawer, which is why the day close needs it.
 */
class PaidOut
{
    /**
     * @return array{expenses: float, expenses_cash: float, suppliers: float, suppliers_cash: float, cash: float, total: float,
     *     by_category: array<string, float>, by_mode: array<string, float>}
     */
    public static function between(Carbon $from, Carbon $to): array
    {
        $expenses = Expense::live()->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])->get(['category', 'mode', 'amount']);
        $suppliers = PurchasePayment::whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->get(['mode', 'amount']);
        $sum = fn ($rows) => round((float) $rows->sum('amount'), 2);

        $byMode = [];
        foreach ($expenses->concat($suppliers) as $row) {
            $byMode[$row->mode] = round(($byMode[$row->mode] ?? 0) + (float) $row->amount, 2);
        }

        return [
            'expenses' => $sum($expenses), 'expenses_cash' => $sum($expenses->where('mode', 'cash')),
            'suppliers' => $sum($suppliers), 'suppliers_cash' => $sum($suppliers->where('mode', 'cash')),
            'cash' => round($sum($expenses->where('mode', 'cash')) + $sum($suppliers->where('mode', 'cash')), 2),
            'total' => round($sum($expenses) + $sum($suppliers), 2),
            'by_category' => $expenses->groupBy('category')->map(fn ($rows) => $sum($rows))->sortDesc()->all(),
            'by_mode' => $byMode,
        ];
    }
}
