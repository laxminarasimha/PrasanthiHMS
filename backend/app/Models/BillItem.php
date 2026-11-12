<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['service_id', 'description', 'quantity', 'unit_price', 'discount', 'gst_percent', 'amount', 'tax'])]
class BillItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['unit_price' => 'float', 'discount' => 'float', 'gst_percent' => 'float', 'amount' => 'float', 'tax' => 'float'];
    }
}
