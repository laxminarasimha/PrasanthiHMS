<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['medicine_id', 'stock_batch_id', 'medicine_name', 'batch_no', 'expiry', 'quantity', 'unit_price', 'discount_percent', 'gst_percent', 'amount'])]
class PharmacySaleItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expiry' => 'date:Y-m-d', 'unit_price' => 'float', 'discount_percent' => 'float', 'gst_percent' => 'float', 'amount' => 'float'];
    }
}
