<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'amount', 'mode', 'reference', 'remarks', 'received_by'])]
class AdmissionPayment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
