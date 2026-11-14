<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A dated correction or addition to a visit that is already locked. */
#[Fillable(['consultation_id', 'body', 'created_by'])]
class ConsultationAmendment extends Model
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
