<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['branch_id', 'stockist_id', 'invoice_no', 'invoice_date', 'subtotal', 'tax', 'other_charges', 'cash_discount', 'round_off', 'total', 'paid', 'remarks', 'created_by'])]
class PurchaseInvoice extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['invoice_date' => 'date:Y-m-d', 'subtotal' => 'float', 'tax' => 'float', 'other_charges' => 'float', 'cash_discount' => 'float', 'round_off' => 'float', 'total' => 'float', 'paid' => 'float'];
    }

    /**
     * Works the totals out again from the lines: what the lines come to, their GST, then freight
     * and the like added, a cash discount on the whole bill taken off, and the rounding.
     *
     * @return array{subtotal: float, tax: float, total: float}
     */
    public function figures(): array
    {
        $batches = $this->batches()->get();
        $subtotal = round($batches->sum(fn (StockBatch $b) => $b->lineTaxable()), 2);
        $tax = round($batches->sum(fn (StockBatch $b) => $b->lineTax()), 2);

        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => round($subtotal + $tax + $this->other_charges - $this->cash_discount + $this->round_off, 2)];
    }

    /** The invoice as the screens want it: each line with its real cost per unit and margin. */
    public function present(): array
    {
        $this->load(['stockist', 'batches.medicine:id,name']);

        return ['batches' => $this->batches->map(fn (StockBatch $b) => $b->withCosting())->all()] + $this->toArray();
    }

    public function stockist(): BelongsTo
    {
        return $this->belongsTo(Stockist::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }
}
