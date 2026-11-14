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

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MedicineSubscription::class)->orderByDesc('id');
    }
}
