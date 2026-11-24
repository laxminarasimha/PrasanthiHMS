<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['branch_id', 'patient_id', 'doctor_id', 'service_id', 'scheduled_at', 'duration_minutes', 'status', 'is_follow_up', 'awaiting_reports', 'token_no', 'arrived_at', 'notes', 'booked_via', 'created_by'])]
class Appointment extends Model
{
    public const STATUSES = ['booked', 'arrived', 'ongoing', 'reviewed', 'cancelled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime:Y-m-d H:i', 'arrived_at' => 'datetime:Y-m-d H:i', 'awaiting_reports' => 'boolean', 'is_follow_up' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Waiting for lab reports is a kind of on-going visit; once the visit is anything else, it no longer applies.
        // Whoever books it (the desk, the calendar, the patient's own phone), a visit within the hospital's follow-up
        // days of a consultation with the same doctor is a follow-up, unless the one booking it has already said which it is.
        static::creating(function (Appointment $appointment) {
            if ($appointment->is_follow_up === null && $appointment->patient_id && $appointment->doctor_id && $appointment->scheduled_at) {
                $appointment->is_follow_up = \App\Support\FreeFollowUp::of((int) $appointment->patient_id, (int) $appointment->doctor_id, $appointment->scheduled_at) !== null;
            }
        });
        // Moved to another day or doctor by a path that did not decide for itself (the patient's own phone): a follow-up
        // that is no longer within the days becomes a consultation, and a visit not yet billed may become a follow-up.
        static::updating(function (Appointment $appointment) {
            if ($appointment->isDirty('is_follow_up') || ! $appointment->isDirty(['doctor_id', 'scheduled_at'])) {
                return;
            }
            $within = \App\Support\FreeFollowUp::of((int) $appointment->patient_id, (int) $appointment->doctor_id, $appointment->scheduled_at, $appointment->id) !== null;
            $appointment->is_follow_up = $appointment->is_follow_up
                ? $within
                : $within && $appointment->status === 'booked' && ! $appointment->bills()->whereNull('cancelled_at')->exists();
        });
        static::saving(function (Appointment $appointment) {
            if ($appointment->status !== 'ongoing') {
                $appointment->awaiting_reports = false;
            }
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function consultation(): HasOne
    {
        return $this->hasOne(Consultation::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }
}
