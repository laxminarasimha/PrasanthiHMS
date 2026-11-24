<?php

namespace App\Models;

use App\Support\Referral;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['direction', 'name', 'specialization', 'phone', 'place'])]
class ReferralDoctor extends Model
{
    protected static function booted(): void
    {
        // Names typed at the desk are matched against this list, so a change here has to be seen at once.
        static::saved(fn () => Referral::forget());
        static::deleted(fn () => Referral::forget());
    }
}
