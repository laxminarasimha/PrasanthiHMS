<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['stockist_id', 'order_date', 'status', 'remarks', 'created_by'])]
class PurchaseOrder extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['order_date' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        static::created(function (PurchaseOrder $order) {
            $order->order_no = 'PO'.str_pad((string) $order->id, 5, '0', STR_PAD_LEFT);
            $order->saveQuietly();
        });
    }

    public function stockist(): BelongsTo
    {
        return $this->belongsTo(Stockist::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
