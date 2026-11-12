<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'medicine_id', 'purchase_invoice_id', 'batch_no', 'expiry', 'units_per_pack', 'packs', 'free_packs', 'pack_price',
    'pack_mrp', 'unit_mrp', 'discount_percent', 'gst_percent', 'units_received', 'units_in_stock', 'rack_no',
])]
class StockBatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expiry' => 'date:Y-m-d', 'pack_price' => 'float', 'pack_mrp' => 'float', 'unit_mrp' => 'float',
            'discount_percent' => 'float', 'gst_percent' => 'float',
        ];
    }

    /** What one unit cost the pharmacy, after trade discount and before tax. */
    public function unitCost(): float
    {
        return $this->units_per_pack > 0 ? round($this->pack_price * (1 - $this->discount_percent / 100) / $this->units_per_pack, 2) : 0.0;
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }
}
