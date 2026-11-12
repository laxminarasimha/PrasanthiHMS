<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'appointment_id', 'patient_id', 'doctor_id', 'branch_id',
    'weight_kg', 'height_cm', 'bp_systolic', 'bp_diastolic', 'pulse', 'temperature_f', 'spo2',
    'complaints', 'findings', 'diagnosis', 'tests_advised', 'advice', 'follow_up_on', 'completed_at',
])]
class Consultation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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

    public function medicines(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class)->orderBy('position');
    }
}
