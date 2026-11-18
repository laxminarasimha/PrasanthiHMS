<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A physical count of the shelf: counted by pharmacy staff, then checked and approved, which corrects the stock. */
#[Fillable(['title', 'scope', 'scope_detail', 'blind', 'status', 'notes', 'started_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at'])]
class StockCount extends Model
{
    public const SCOPES = ['all', 'rack', 'letters', 'spot', 'chosen', 'reported'];

    /**
     * Why the shelf and the records differ. A loss takes value out of the pharmacy;
     * "missing" is the one with no explanation at all.
     */
    public const REASONS = [
        'missing' => 'Missing, not explained',
        'unbilled' => 'Given without a bill (ward, OT, staff)',
        'damaged' => 'Damaged or broken',
        'expired' => 'Expired and removed',
        'mixup' => 'Sold from another batch',
        'miscount' => 'Earlier counting or entry mistake',
        'found' => 'Found, more than recorded',
    ];

    public const LOSSES = ['missing', 'unbilled', 'damaged', 'expired'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['blind' => 'boolean', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::created(function (StockCount $count) {
            $count->count_no = 'SC'.str_pad((string) $count->id, 5, '0', STR_PAD_LEFT);
            $count->saveQuietly();
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class)->orderBy('rack_no')->orderBy('medicine_name')->orderBy('expiry')->orderBy('id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
