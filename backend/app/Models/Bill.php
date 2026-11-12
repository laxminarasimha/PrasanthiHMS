<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['branch_id', 'patient_id', 'appointment_id', 'discount', 'created_by'])]
class Bill extends Model
{
    protected $appends = ['due'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['subtotal' => 'float', 'tax' => 'float', 'discount' => 'float', 'total' => 'float', 'paid' => 'float'];
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

    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getDueAttribute(): float
    {
        return round($this->total - $this->paid, 2);
    }

    public function addService(Service $service, int $quantity = 1): BillItem
    {
        return $this->addLine($service->name, $service->price, $quantity, $service->gst_percent, $service->id);
    }

    /** Adds a charge that is not in the services list, such as a lab test. */
    public function addLine(string $description, float $unitPrice, int $quantity = 1, float $gstPercent = 0, ?int $serviceId = null): BillItem
    {
        $amount = round($unitPrice * $quantity, 2);

        $item = $this->items()->create([
            'service_id' => $serviceId,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
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
        $this->subtotal = round((float) $this->items()->sum('amount'), 2);
        $this->tax = round((float) $this->items()->sum('tax'), 2);
        $this->total = round(max(0, $this->subtotal + $this->tax - $this->discount), 2);
        $this->paid = round(
            (float) $this->payments()->where('type', 'payment')->sum('amount')
            - (float) $this->payments()->where('type', 'refund')->sum('amount'),
            2
        );
        $this->save();
    }
}
