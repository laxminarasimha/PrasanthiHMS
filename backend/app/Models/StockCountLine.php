<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One batch on a stock count: what the records said, what was counted, and why they differ. */
#[Fillable([
    'stock_batch_id', 'medicine_id', 'medicine_name', 'batch_no', 'expiry', 'rack_no', 'unit_cost', 'unit_mrp',
    'expected_units', 'counted_units', 'counted_by', 'counted_at', 'recount', 'reason', 'note', 'applied_units',
])]
class StockCountLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expiry' => 'date:Y-m-d', 'unit_cost' => 'float', 'unit_mrp' => 'float', 'recount' => 'boolean', 'counted_at' => 'datetime'];
    }

    /** Counted less the records: negative is short, positive is extra, null is not counted yet. */
    public function difference(): ?int
    {
        return $this->counted_units === null ? null : $this->counted_units - (int) $this->expected_units;
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
