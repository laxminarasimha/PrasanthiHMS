<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['branch_id', 'patient_id', 'appointment_id', 'bill_id', 'referred_by', 'status', 'ordered_at', 'created_by'])]
class LabOrder extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['ordered_at' => 'datetime:Y-m-d H:i'];
    }

    protected static function booted(): void
    {
        static::created(function (LabOrder $order) {
            $order->order_no = 'L'.str_pad((string) $order->id, 5, '0', STR_PAD_LEFT);
            $order->saveQuietly();
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LabOrderItem::class);
    }

    /** Pending until any test is reported, completed once all are. */
    public function refreshStatus(): void
    {
        $total = $this->items()->count();
        $reported = $this->items()->whereNotNull('reported_at')->count();

        $this->update(['status' => $reported === 0 ? 'pending' : ($reported < $total ? 'partial' : 'completed')]);
    }
}
