<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A family member whose appointments and records are handled from someone else's app login. */
#[Fillable(['patient_account_id', 'patient_id', 'relation'])]
class PatientAccountMember extends Model
{
    public const RELATIONS = ['Spouse', 'Father', 'Mother', 'Son', 'Daughter', 'Brother', 'Sister', 'Grandparent', 'Other'];

    public const MOST = 8;

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
