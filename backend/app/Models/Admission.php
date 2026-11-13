<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id', 'patient_id', 'doctor_id', 'payer_id', 'bed_id', 'admitted_at', 'discharged_at', 'status', 'reason',
    'attendant_name', 'attendant_phone', 'aadhaar_no', 'gst_no', 'discharge_summary', 'discount', 'created_by', 'bill_approved_at', 'bill_approved_by',
])]
class Admission extends Model
{
    public const CLOSED_STATUSES = ['discharged', 'lama', 'dama', 'absconded', 'died'];

    protected $appends = ['net', 'due'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'admitted_at' => 'datetime:Y-m-d H:i', 'discharged_at' => 'datetime:Y-m-d H:i',
            'total' => 'float', 'discount' => 'float', 'paid' => 'float', 'bill_approved_at' => 'datetime:Y-m-d H:i',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Admission $admission) {
            // The hospital can choose the letters before the number; cases already made keep theirs.
            $prefix = Setting::group('billing', ['ipd_prefix' => 'IP'])['ipd_prefix'] ?: 'IP';
            $admission->ipd_no = $prefix.str_pad((string) $admission->id, 5, '0', STR_PAD_LEFT);
            $admission->saveQuietly();
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(AdmissionCharge::class)->orderBy('charged_on')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AdmissionPayment::class)->orderBy('id');
    }

    /** Numbered bills made from this case's charges. */
    public function bills(): HasMany
    {
        return $this->hasMany(AdmissionBill::class)->orderBy('id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(AdmissionNote::class)->orderByDesc('noted_at')->orderByDesc('id');
    }

    public function summary(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DischargeSummary::class);
    }

    /** Who settles the bill when it is not the patient. */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(AdminEntry::class, 'payer_id');
    }

    public function billApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bill_approved_by');
    }

    /** Charges less discount. */
    public function getNetAttribute(): float
    {
        return round(max(0, $this->total - $this->discount), 2);
    }

    /** Positive when the patient owes money, negative when advances exceed the bill. */
    public function getDueAttribute(): float
    {
        return round($this->net - $this->paid, 2);
    }

    /** Recomputes the stored totals from the charge and payment rows. */
    public function recalculate(): void
    {
        $this->total = round((float) $this->charges()->sum('amount'), 2);
        $this->paid = round(
            (float) $this->payments()->whereIn('type', ['advance', 'payment'])->sum('amount')
            - (float) $this->payments()->where('type', 'refund')->sum('amount'),
            2
        );
        $this->save();
    }
}
