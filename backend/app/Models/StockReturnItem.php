<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['stock_batch_id', 'medicine_id', 'medicine_name', 'batch_no', 'quantity', 'unit_cost', 'amount'])]
class StockReturnItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['unit_cost' => 'float', 'amount' => 'float'];
    }
}
