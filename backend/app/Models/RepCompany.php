<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/** A medicine company whose representatives call on the doctors. */
#[Fillable(['name', 'notes', 'is_active', 'created_by'])]
class RepCompany extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function reps(): HasMany
    {
        return $this->hasMany(MedicalRep::class)->orderBy('name');
    }

    /** Every time one of the company's people came. */
    public function visits(): HasManyThrough
    {
        return $this->hasManyThrough(RepVisit::class, MedicalRep::class);
    }

    /** The company's catalogue and price lists, as the files its representative handed over. */
    public function files(): HasMany
    {
        return $this->hasMany(RepCompanyFile::class)->orderByDesc('id');
    }
}
