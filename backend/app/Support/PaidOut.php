<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\PurchasePayment;
use App\Workforce\Models\AdvanceLedgerLine;
use Illuminate\Support\Carbon;

/**
 * Money that left the hospital in a period: its expenses (including salaries and staff advances, which payroll and
 * advances post there themselves) and what was paid to medicine suppliers. The cash part comes out of the drawer,
 * which is why the day close needs it. Money staff hand back on an advance is shown beside it, because cash paid back
 * goes into the drawer.
 */
class PaidOut
{
    /**
     * @return array{expenses: float, expenses_cash: float, suppliers: float, suppliers_cash: float, cash: float, total: float,
     *     by_category: array<string, float>, by_mode: array<string, float>, staff_repaid: float, staff_repaid_cash: float}
     */
    public static function between(Carbon $from, Carbon $to): array
    {
        // Counted on the day it was paid. A draft, or one waiting for approval or payment, has not left yet.
        $expenses = Expense::paidOut()->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->get(['category', 'mode', 'amount']);
        $repaid = AdvanceLedgerLine::query()->where('kind', 'repayment')->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])->get(['mode', 'amount']);
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
            // Paid back by staff on advances and loans, by hand rather than from salary. Cash ones went into the drawer.
            'staff_repaid' => round(-(float) $repaid->sum('amount'), 2),
            'staff_repaid_cash' => round(-(float) $repaid->where('mode', 'cash')->sum('amount'), 2),
        ];
    }
}
