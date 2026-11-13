<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One filled-in form for a patient, usually during an IPD stay. */
#[Fillable(['form_template_id', 'patient_id', 'admission_id', 'appointment_id', 'name', 'fields', 'values', 'created_by', 'updated_by'])]
class FormRecord extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fields' => 'array', 'values' => 'array'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
