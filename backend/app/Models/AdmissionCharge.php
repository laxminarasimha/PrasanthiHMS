<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['ipd_service_id', 'description', 'category', 'charged_on', 'quantity', 'unit_price', 'amount', 'created_by'])]
class AdmissionCharge extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['charged_on' => 'date:Y-m-d', 'quantity' => 'float', 'unit_price' => 'float', 'amount' => 'float'];
    }
}
