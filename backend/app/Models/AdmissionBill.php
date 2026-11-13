<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A numbered bill for part of an inpatient stay: the charges it lists stay fixed until the bill is cancelled. */
#[Fillable(['admission_id', 'billed_on', 'notes', 'created_by', 'deleted_by'])]
class AdmissionBill extends Model
{
    use SoftDeletes;

    protected $appends = ['total'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['billed_on' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        static::created(function (AdmissionBill $bill) {
            $prefix = Setting::group('billing', ['ipd_bill_prefix' => 'IPB'])['ipd_bill_prefix'] ?: 'IPB';
            $bill->bill_no = $prefix.str_pad((string) $bill->id, 5, '0', STR_PAD_LEFT);
            $bill->saveQuietly();
        });
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(AdmissionCharge::class)->orderBy('charged_on')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getTotalAttribute(): float
    {
        return round((float) ($this->relationLoaded('charges') ? $this->charges->sum('amount') : $this->charges()->sum('amount')), 2);
    }
}
