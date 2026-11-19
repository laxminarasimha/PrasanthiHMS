<?php

namespace App\Models;

use App\Workforce\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment out of the hospital's money that is not for a patient or a medicine supplier's invoice.
 *
 * It may be written down already paid (the cashier's usual entry), or go through stages first: a draft, sent for
 * approval, approved or refused, then paid. Only a paid one that is not cancelled is money out: it counts on the day
 * it was paid (`paid_on`). An entry posted by payroll or an advance carries its `source_type` and is put right there,
 * never here.
 */
#[Fillable([
    'branch_id', 'status', 'spent_on', 'category', 'category_id', 'department_id', 'paid_to', 'bill_no', 'description', 'amount', 'tax', 'mode', 'reference', 'paid_on', 'paid_by',
    'created_by', 'submitted_by', 'submitted_at', 'decided_by', 'decided_at', 'decision_note', 'receipt_path', 'receipt_name', 'receipt_mime', 'source_type', 'source_id',
    'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
#[Hidden(['receipt_path'])]
class Expense extends Model
{
    /** Offered when nothing has been entered yet; after that the hospital's own words are offered too. */
    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'paid'];

    /** The heading staff used for salaries before payroll: closed to hand entries once payroll posts them itself. */
    public const OLD_SALARY_HEADING = 'Staff salary or advance';

    public const CATEGORIES = [
        'Staff salary or advance', 'Electricity and water', 'Housekeeping and laundry', 'Tea and refreshments', 'Repairs and maintenance',
        'Stationery and printing', 'Transport and courier', 'Medical supplies', 'Phone and internet', 'Rent', 'Doctor payment', 'Other',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'spent_on' => 'date:Y-m-d', 'paid_on' => 'date:Y-m-d', 'amount' => 'float', 'tax' => 'float', 'cancelled_at' => 'datetime:Y-m-d H:i',
            'submitted_at' => 'datetime:Y-m-d H:i', 'decided_at' => 'datetime:Y-m-d H:i',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Expense $expense) {
            $expense->expense_no = 'EX'.str_pad((string) $expense->id, 5, '0', STR_PAD_LEFT);
            $expense->saveQuietly();
        });
    }

    /** Entries that count: the ones not cancelled. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    /** Money that has actually gone out: paid, and not cancelled. */
    public function scopePaidOut(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')->where('status', 'paid');
    }

    public function heading(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(AdminEntry::class, 'department_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Whether payroll or an advance put this here. Such an entry is put right where it came from. */
    public function isPosted(): bool
    {
        return in_array($this->source_type, ['payroll_payment', 'advance'], true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
