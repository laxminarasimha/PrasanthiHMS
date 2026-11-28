<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A medical representative: a person who calls on the doctors for a medicine company. */
#[Fillable(['rep_company_id', 'name', 'mobile', 'division', 'area', 'manager_name', 'manager_mobile', 'notes', 'is_active', 'created_by'])]
class MedicalRep extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(RepCompany::class, 'rep_company_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(RepVisit::class)->orderByDesc('arrived_at');
    }

    public function lastVisit(): HasOne
    {
        return $this->hasOne(RepVisit::class)->latestOfMany('arrived_at');
    }
}
