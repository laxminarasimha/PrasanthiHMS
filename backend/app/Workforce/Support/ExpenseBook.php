<?php

namespace App\Workforce\Support;

use App\Models\Expense;
use App\Workforce\Models\ExpenseCategory;
use Illuminate\Validation\ValidationException;

/**
 * The rules expenses live by: which need approval and whose, and how payroll and advances post the money they pay
 * out into the same expenses, once, so the day's cash count and every report see it and nobody types it again.
 */
final class ExpenseBook
{
    /** The system's own headings. Payroll and advances post into them; a hand entry there is refused. */
    public const SALARIES = 'Salaries (from payroll)';

    public const ADVANCES = 'Staff advances and loans';

    /** Whether an expense under this heading, of this amount, must be approved before it is paid. */
    public static function needsApproval(?ExpenseCategory $heading, float $amount): bool
    {
        return $heading !== null && $heading->approval_above !== null && $amount > (float) $heading->approval_above;
    }

    /** Whether approving it needs "Approve large expenses" rather than the ordinary approval. */
    public static function needsHigherApproval(?ExpenseCategory $heading, float $amount): bool
    {
        return $heading !== null && $heading->approver_limit !== null && $amount > (float) $heading->approver_limit;
    }

    /** Whether payroll or advances have begun posting here. From then on, the old salary heading is closed to hand entries. */
    public static function payrollPosts(): bool
    {
        return Expense::query()->whereIn('source_type', ['payroll_payment', 'advance'])->exists();
    }

    /** Refuses a hand entry that would count salaries or advances a second time. */
    public static function refuseDoubleCounting(?ExpenseCategory $heading, string $name): void
    {
        if ($heading?->is_payroll || in_array($name, [self::SALARIES, self::ADVANCES], true)) {
            throw ValidationException::withMessages(['category' => 'Salaries and staff advances are written here by Payroll and Advances themselves. Enter them there, so they are not counted twice.']);
        }
        if ($name === Expense::OLD_SALARY_HEADING && self::payrollPosts()) {
            throw ValidationException::withMessages(['category' => 'Salaries and advances are now recorded from Payroll and Advances, which write them here themselves. Enter them there, so they are not counted twice.']);
        }
    }

    /**
     * Writes money that payroll or an advance paid out as one paid expense. The day lock applies as to any expense:
     * a payment cannot be dated into a closed day.
     */
    public static function post(string $sourceType, int $sourceId, string $heading, mixed $amount, string $date, string $mode, ?string $reference, ?string $paidTo, string $description): Expense
    {
        $category = ExpenseCategory::query()->firstOrCreate(['name' => $heading], ['is_payroll' => true, 'is_active' => true, 'sort' => 900]);
        if (! $category->is_payroll) {
            $category->update(['is_payroll' => true]);
        }

        // Payroll says "bank"; the hospital's own list of modes calls it a bank transfer.
        $mode = $mode === 'bank' ? 'bank_transfer' : $mode;

        return Expense::create([
            'branch_id' => Tenant::id(), 'status' => 'paid', 'spent_on' => $date, 'paid_on' => $date, 'category' => $category->name, 'category_id' => $category->id,
            'paid_to' => $paidTo, 'description' => mb_substr($description, 0, 500), 'amount' => (float) Money::text($amount), 'mode' => $mode, 'reference' => $reference,
            'created_by' => auth()->id(), 'paid_by' => auth()->id(), 'source_type' => $sourceType, 'source_id' => $sourceId,
        ]);
    }
}
