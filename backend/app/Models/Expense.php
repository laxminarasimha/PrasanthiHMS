<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One payment out of the hospital's money that is not for a patient or a medicine supplier's invoice. */
#[Fillable(['branch_id', 'spent_on', 'category', 'paid_to', 'description', 'amount', 'mode', 'reference', 'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason'])]
class Expense extends Model
{
    /** Offered when nothing has been entered yet; after that the hospital's own words are offered too. */
    public const CATEGORIES = [
        'Staff salary or advance', 'Electricity and water', 'Housekeeping and laundry', 'Tea and refreshments', 'Repairs and maintenance',
        'Stationery and printing', 'Transport and courier', 'Medical supplies', 'Phone and internet', 'Rent', 'Doctor payment', 'Other',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['spent_on' => 'date:Y-m-d', 'amount' => 'float', 'cancelled_at' => 'datetime:Y-m-d H:i'];
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
