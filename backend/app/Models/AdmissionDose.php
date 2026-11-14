<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry on the medicine chart: a dose given, held back, or refused. */
#[Fillable(['admission_order_id', 'given_at', 'status', 'remarks', 'given_by'])]
class AdmissionDose extends Model
{
    public const STATUSES = ['given', 'held', 'refused'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['given_at' => 'datetime:Y-m-d H:i'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AdmissionOrder::class, 'admission_order_id');
    }

    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }
}
