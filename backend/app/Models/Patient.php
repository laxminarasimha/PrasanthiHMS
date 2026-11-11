<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'branch_id', 'name', 'gender', 'date_of_birth', 'dob_estimated', 'mobile', 'mobile_alt', 'email',
    'blood_group', 'preferred_language', 'address', 'city', 'pincode', 'care_of', 'occupation',
    'referred_by', 'channel', 'tag', 'existing_id', 'created_by',
])]
class Patient extends Model
{
    use HasFactory;

    protected $appends = ['age'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'dob_estimated' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Patient number: registration year and month, then a running number (e.g. 2026110042).
        static::created(function (Patient $patient) {
            $patient->uhid = $patient->created_at->format('Ym').str_pad((string) $patient->id, 4, '0', STR_PAD_LEFT);
            $patient->saveQuietly();
        });
    }

    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth ? (int) $this->date_of_birth->diffInYears(now()) : null;
    }
}
