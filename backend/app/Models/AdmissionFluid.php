<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fluid taken in or passed out by an inpatient, in millilitres. */
#[Fillable(['admission_id', 'recorded_at', 'direction', 'kind', 'amount_ml', 'notes', 'recorded_by'])]
class AdmissionFluid extends Model
{
    public const KINDS = ['in' => ['Oral', 'IV fluid', 'Blood', 'Tube feed', 'Other'], 'out' => ['Urine', 'Drain', 'Vomit', 'Stool', 'Ryles tube', 'Other']];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime:Y-m-d H:i'];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
