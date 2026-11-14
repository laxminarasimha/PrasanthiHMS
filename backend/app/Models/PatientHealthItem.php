<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One lasting fact on a patient's health summary, e.g. "Type 2 diabetes, since 2018". */
#[Fillable(['patient_id', 'kind', 'title', 'detail', 'since', 'is_active', 'ended_on', 'created_by', 'updated_by'])]
class PatientHealthItem extends Model
{
    /** The sections of the summary, in the order they are shown. */
    public const KINDS = [
        'condition' => 'Ongoing conditions',
        'medication' => 'Regular medicines',
        'surgery' => 'Past surgeries and admissions',
        'family' => 'Family history',
        'habit' => 'Habits',
        'immunisation' => 'Vaccines given',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'ended_on' => 'date:Y-m-d'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
