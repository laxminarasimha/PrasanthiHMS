<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A consultant's visit to another doctor's inpatient, on one day. It earns the consultant a fee from the hospital:
 * more for the first visit to that patient, less for each later day. A wrong entry is cancelled with its reason,
 * never removed.
 */
#[Fillable(['branch_id', 'admission_id', 'patient_id', 'doctor_id', 'visited_on', 'kind', 'fee', 'note', 'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason'])]
class ConsultantVisit extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['visited_on' => 'date:Y-m-d', 'fee' => 'float', 'cancelled_at' => 'datetime:Y-m-d H:i'];
    }

    /** The visits that stand. */
    public function scopeStanding(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }
}
