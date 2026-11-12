<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['stockist_id', 'returned_on', 'total', 'remarks', 'created_by'])]
class StockReturn extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['returned_on' => 'date:Y-m-d', 'total' => 'float'];
    }

    protected static function booted(): void
    {
        static::created(function (StockReturn $return) {
            $return->return_no = 'SR'.str_pad((string) $return->id, 5, '0', STR_PAD_LEFT);
            $return->saveQuietly();
        });
    }

    public function stockist(): BelongsTo
    {
        return $this->belongsTo(Stockist::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockReturnItem::class);
    }
}
