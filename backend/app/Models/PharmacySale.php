<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id', 'patient_id', 'customer_name', 'customer_phone', 'referred_doctor', 'sold_at', 'subtotal', 'discount',
    'tax', 'round_off', 'total', 'returned', 'paid', 'ipd_transfer', 'payment_mode', 'reference', 'created_by',
    'voided_at', 'voided_by', 'void_reason', 'consultation_id',
])]
class PharmacySale extends Model
{
    /**
     * What a bill is opened with on the screen: its medicines, the patient, who made it, its money, and the visit whose
     * prescription it was made from (only the day and the doctor; nothing clinical).
     */
    public const ON_SCREEN = ['items', 'patient:id,uhid,title,name', 'seller:id,name', 'payments.receiver:id,name', 'returns', 'consultation:id,doctor_id,completed_at', 'consultation.doctor:id,name'];

    protected $appends = ['due'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime:Y-m-d H:i', 'subtotal' => 'float', 'discount' => 'float', 'tax' => 'float',
            'round_off' => 'float', 'total' => 'float', 'returned' => 'float', 'paid' => 'float', 'ipd_transfer' => 'float',
            'voided_at' => 'datetime:Y-m-d H:i',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (PharmacySale $sale) {
            $sale->bill_no = 'P'.str_pad((string) $sale->id, 5, '0', STR_PAD_LEFT);
            $sale->saveQuietly();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(PharmacySaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PharmacyPayment::class)->orderBy('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PharmacySaleReturn::class)->orderBy('id');
    }

    /** Recomputes the money received from the payment rows. */
    public function recalculatePaid(): void
    {
        $this->paid = round(
            (float) $this->payments()->where('type', 'payment')->sum('amount') - (float) $this->payments()->where('type', 'refund')->sum('amount'),
            2
        );
        $this->save();
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** The visit whose prescription the bill was made from at the counter, if it was. */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getDueAttribute(): float
    {
        return $this->voided_at ? 0.0 : round($this->total - $this->returned - $this->paid - $this->ipd_transfer, 2);
    }
}
