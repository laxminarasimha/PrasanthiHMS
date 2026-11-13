<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A record that one duplicate patient was folded into another, and by whom. */
#[Fillable(['from_id', 'from_uhid', 'from_name', 'to_id', 'to_uhid', 'to_name', 'moved', 'merged_by'])]
class PatientMerge extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['moved' => 'array'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }
}
