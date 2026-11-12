<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['medicine_id', 'stock_batch_id', 'medicine_name', 'batch_no', 'expiry', 'quantity', 'returned_quantity', 'unit_price', 'unit_cost', 'discount_percent', 'gst_percent', 'amount'])]
class PharmacySaleItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expiry' => 'date:Y-m-d', 'unit_price' => 'float', 'unit_cost' => 'float', 'discount_percent' => 'float', 'gst_percent' => 'float', 'amount' => 'float'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PharmacySale::class, 'pharmacy_sale_id');
    }
}
