<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['branch_id', 'stockist_id', 'invoice_no', 'invoice_date', 'subtotal', 'tax', 'total', 'remarks', 'created_by'])]
class PurchaseInvoice extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['invoice_date' => 'date:Y-m-d', 'subtotal' => 'float', 'tax' => 'float', 'total' => 'float'];
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
