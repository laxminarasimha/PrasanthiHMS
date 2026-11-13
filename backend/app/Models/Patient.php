<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'branch_id', 'title', 'name', 'gender', 'date_of_birth', 'dob_estimated', 'mobile', 'mobile_alt', 'email',
    'blood_group', 'allergies', 'preferred_language', 'address', 'city', 'pincode', 'care_of', 'occupation',
    'referred_by', 'channel', 'tag', 'existing_id', 'created_by',
])]
class Patient extends Model
{
    use HasFactory;

    public const TITLES = ['Mr', 'Mrs', 'Ms', 'Dr', 'Md', 'Smt', 'Sri', 'Kumari', 'Master', 'Baby'];

    protected $appends = ['age', 'age_label'];

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

    /** The age as it is read out: days for a newborn, months for an infant, years after that ("12d", "8m", "34y"). */
    public function getAgeLabelAttribute(): ?string
    {
        if (! $this->date_of_birth) {
            return null;
        }
        $days = (int) $this->date_of_birth->diffInDays(now());
        $months = (int) $this->date_of_birth->diffInMonths(now());

        return match (true) {
            $months < 1 => "{$days}d",
            $months < 24 => "{$months}m",
            default => $this->age.'y',
        };
    }
}
