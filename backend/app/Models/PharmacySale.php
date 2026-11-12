<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id', 'patient_id', 'customer_name', 'customer_phone', 'referred_doctor', 'sold_at', 'subtotal', 'discount',
    'tax', 'round_off', 'total', 'paid', 'payment_mode', 'reference', 'created_by',
])]
class PharmacySale extends Model
{
    protected $appends = ['due'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime:Y-m-d H:i', 'subtotal' => 'float', 'discount' => 'float', 'tax' => 'float',
            'round_off' => 'float', 'total' => 'float', 'paid' => 'float',
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

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getDueAttribute(): float
    {
        return round($this->total - $this->paid, 2);
    }
}
