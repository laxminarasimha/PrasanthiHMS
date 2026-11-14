<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One set of bedside observations for an inpatient. */
#[Fillable(['admission_id', 'recorded_at', 'temperature_f', 'pulse', 'bp_systolic', 'bp_diastolic', 'resp_rate', 'spo2', 'blood_sugar', 'pain_score', 'notes', 'recorded_by'])]
class AdmissionVital extends Model
{
    public const READINGS = ['temperature_f', 'pulse', 'bp_systolic', 'bp_diastolic', 'resp_rate', 'spo2', 'blood_sugar', 'pain_score'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime:Y-m-d H:i', 'temperature_f' => 'float'];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
