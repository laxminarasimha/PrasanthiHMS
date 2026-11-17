<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/** A patient's own login for the mobile app. Never a staff member: staff routes refuse it. */
#[Fillable(['patient_id', 'name', 'mobile', 'password', 'is_active', 'verified_at', 'last_login_at'])]
#[Hidden(['password'])]
class PatientAccount extends Authenticatable
{
    use HasApiTokens;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'verified_at' => 'datetime:Y-m-d H:i', 'last_login_at' => 'datetime:Y-m-d H:i'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(PatientAccountMember::class)->orderBy('id');
    }

    /**
     * Every patient record this login looks after: the person's own first, then their family.
     *
     * @return list<int>
     */
    public function patientIds(): array
    {
        return array_values(array_unique([$this->patient_id, ...$this->members()->pluck('patient_id')->all()]));
    }

    /**
     * The patient a request is about: the one named in it, if this login looks after them, otherwise the person's own.
     * Naming anyone else is answered as "not found", so the app cannot be used to probe for other patients.
     */
    public function patientFor(mixed $patientId): int
    {
        if ($patientId === null || $patientId === '') {
            return $this->patient_id;
        }
        abort_unless(in_array((int) $patientId, $this->patientIds(), true), 404, 'That person is not on your account.');

        return (int) $patientId;
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MedicineSubscription::class)->orderByDesc('id');
    }
}
