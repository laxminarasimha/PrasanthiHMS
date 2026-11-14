<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['branch_id', 'patient_id', 'doctor_id', 'service_id', 'scheduled_at', 'duration_minutes', 'status', 'token_no', 'arrived_at', 'notes', 'booked_via', 'created_by'])]
class Appointment extends Model
{
    public const STATUSES = ['booked', 'arrived', 'ongoing', 'reviewed', 'cancelled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime:Y-m-d H:i', 'arrived_at' => 'datetime:Y-m-d H:i'];
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
