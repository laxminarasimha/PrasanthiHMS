<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money paid to a consultant for a month's work. Each one also stands in the cash book as a paid expense, so the
 * day's cash count and every report of money out see it. One made by mistake is cancelled with its reason.
 */
#[Fillable(['branch_id', 'doctor_id', 'for_month', 'paid_on', 'amount', 'mode', 'reference', 'note', 'expense_id', 'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason'])]
class ConsultantPayment extends Model
{
    public const MODES = ['cash', 'upi', 'bank_transfer', 'cheque'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['paid_on' => 'date:Y-m-d', 'amount' => 'float', 'cancelled_at' => 'datetime:Y-m-d H:i'];
    }

    public function scopeStanding(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }
}
