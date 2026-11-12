<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['lab_test_id', 'test_name', 'department', 'sample_type', 'price', 'remarks', 'reported_at', 'reported_by'])]
class LabOrderItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price' => 'float', 'reported_at' => 'datetime:Y-m-d H:i'];
    }

    public function results(): HasMany
    {
        return $this->hasMany(LabResult::class)->orderBy('position');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
