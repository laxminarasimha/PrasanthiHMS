<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['ipd_service_id', 'description', 'category', 'charged_on', 'quantity', 'unit_price', 'discount', 'amount', 'remarks', 'created_by', 'source_type', 'source_id', 'deleted_by'])]
class AdmissionCharge extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['charged_on' => 'date:Y-m-d', 'quantity' => 'float', 'unit_price' => 'float', 'discount' => 'float', 'amount' => 'float'];
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deleter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
