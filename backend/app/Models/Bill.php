<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['branch_id', 'patient_id', 'appointment_id', 'kind', 'discount', 'discount_reason', 'discount_allowed_by', 'created_by'])]
class Bill extends Model
{
    protected $appends = ['due'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['subtotal' => 'float', 'tax' => 'float', 'discount' => 'float', 'total' => 'float', 'paid' => 'float', 'cancelled_at' => 'datetime:Y-m-d H:i', 'entered_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::created(function (Bill $bill) {
            $bill->bill_no = 'B'.str_pad((string) $bill->id, 5, '0', STR_PAD_LEFT);
            $bill->saveQuietly();
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Set when this bill was raised for a lab order. */
    public function labOrder(): HasOne
    {
        return $this->hasOne(LabOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Adds `moved_to_ipd`: the part of the bill that will be collected through an IPD bill. */
    public function scopeWithIpdTransfers($query): void
    {
        $query->withSum(['payments as moved_to_ipd' => fn ($payments) => $payments->where('mode', 'ipd')], 'amount');
    }

    public function getDueAttribute(): float
    {
        return round($this->total - $this->paid, 2);
    }

    public function addService(Service $service, int $quantity = 1): BillItem
    {
        return $this->addLine($service->name, $service->price, $quantity, $service->gst_percent, $service->id);
    }

    /**
     * Adds a charge to the bill. The price, quantity and a rupee discount can differ from the
     * price list; GST is worked out on what is left after the discount.
     */
    public function addLine(string $description, float $unitPrice, int $quantity = 1, float $gstPercent = 0, ?int $serviceId = null, float $discount = 0): BillItem
    {
        $amount = round(max(0, $unitPrice * $quantity - $discount), 2);

        $item = $this->items()->create([
            'service_id' => $serviceId,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount' => $discount,
            'gst_percent' => $gstPercent,
            'amount' => $amount,
            'tax' => round($amount * $gstPercent / 100, 2),
        ]);

        $this->recalculate();

        return $item;
    }

    /** Recomputes the stored totals from the bill's items and payments. */
    public function recalculate(): void
    {
        // A cancelled bill keeps its lines for the record but is worth nothing, so every total that adds bills up leaves it out.
        $this->subtotal = $this->cancelled_at ? 0.0 : round((float) $this->items()->sum('amount'), 2);
        $this->tax = $this->cancelled_at ? 0.0 : round((float) $this->items()->sum('tax'), 2);
        $this->total = round(max(0, $this->subtotal + $this->tax - $this->discount), 2);
        $this->paid = round(
            (float) $this->payments()->where('type', 'payment')->sum('amount')
            - (float) $this->payments()->where('type', 'refund')->sum('amount'),
            2
        );
        $this->save();
    }
}
