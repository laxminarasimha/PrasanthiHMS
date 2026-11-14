<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'appointment_id', 'patient_id', 'doctor_id', 'branch_id',
    'weight_kg', 'height_cm', 'bp_systolic', 'bp_diastolic', 'pulse', 'temperature_f', 'spo2',
    'complaints', 'findings', 'diagnosis', 'tests_advised', 'investigations', 'advice', 'follow_up_on', 'completed_at',
    'quick_notes', 'resp_rate', 'history', 'past_medication', 'general_exam', 'general_exam_notes', 'assessment_plan', 'refer_to',
])]
class Consultation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'general_exam' => 'array',
            'investigations' => 'array',
            'weight_kg' => 'float', 'height_cm' => 'float', 'temperature_f' => 'float',
            'follow_up_on' => 'date:Y-m-d', 'completed_at' => 'datetime:Y-m-d H:i',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(ConsultationAmendment::class)->orderBy('id');
    }

    /**
     * A finished visit can be corrected for the rest of the day it was finished. From the next
     * day it is locked: the notes stay as they were and later corrections are added as amendments.
     */
    public function isLocked(): bool
    {
        return $this->completed_at !== null && $this->completed_at->lt(today());
    }

    public function medicines(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class)->orderBy('position');
    }
}
