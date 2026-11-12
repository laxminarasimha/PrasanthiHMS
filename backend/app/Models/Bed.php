<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['branch_id', 'ward', 'bed_no', 'daily_charge', 'is_active'])]
class Bed extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['daily_charge' => 'float', 'is_active' => 'boolean'];
    }

    /** The patient lying in this bed right now, if any. */
    public function currentAdmission(): HasOne
    {
        return $this->hasOne(Admission::class)->where('status', 'admitted');
    }
}
