<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'manufacturer', 'category', 'sub_category', 'ved', 'sde', 'fsn', 'is_scheduled', 'hsn_code', 'gst_percent', 'units_per_pack', 'reorder_level', 'is_active'])]
class Medicine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['gst_percent' => 'float', 'is_active' => 'boolean', 'is_scheduled' => 'boolean'];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    /** Batches that can be sold today, soonest expiry first so older stock goes out first. */
    public function sellableBatches(): HasMany
    {
        return $this->batches()->where('units_in_stock', '>', 0)->whereDate('expiry', '>=', today())->orderBy('expiry')->orderBy('id');
    }
}
