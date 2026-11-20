<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['branch_id', 'ward', 'bed_no', 'daily_charge', 'day_care_charge', 'is_active'])]
class Bed extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['daily_charge' => 'float', 'day_care_charge' => 'float', 'is_active' => 'boolean'];
    }

    /** The patient lying in this bed right now, if any. */
    public function currentAdmission(): HasOne
    {
        return $this->hasOne(Admission::class)->where('status', 'admitted');
    }

    /** The rate a day in this bed is charged at for this stay: the day-care rate for a day-care admission, where the bed has one. */
    public function rateFor(?Admission $admission): float
    {
        return $admission?->is_day_care && $this->day_care_charge !== null ? (float) $this->day_care_charge : (float) $this->daily_charge;
    }
}
