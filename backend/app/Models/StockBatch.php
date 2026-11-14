<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'medicine_id', 'purchase_invoice_id', 'batch_no', 'expiry', 'units_per_pack', 'packs', 'free_packs', 'pack_price',
    'pack_mrp', 'unit_mrp', 'discount_percent', 'discount_amount', 'gst_percent', 'tax_on_free', 'hsn_code', 'units_received', 'units_in_stock', 'rack_no', 'box_no',
])]
class StockBatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expiry' => 'date:Y-m-d', 'pack_price' => 'float', 'pack_mrp' => 'float', 'unit_mrp' => 'float',
            'discount_percent' => 'float', 'discount_amount' => 'float', 'gst_percent' => 'float', 'tax_on_free' => 'boolean',
        ];
    }

    /** What the stockist charges for this line before tax: the paid packs, less the trade discount in % and in rupees. */
    public function lineTaxable(): float
    {
        return round(max(0, $this->packs * $this->pack_price * (1 - $this->discount_percent / 100) - (float) $this->discount_amount), 2);
    }

    /** GST on the line. Some stockists also charge it on the value of the free packs. */
    public function lineTax(): float
    {
        $onFree = $this->tax_on_free ? $this->free_packs * $this->pack_price : 0;

        return round(($this->lineTaxable() + $onFree) * $this->gst_percent / 100, 2);
    }

    /** The invoice rate of one unit, after trade discount and before tax. This is what a return to the stockist is credited at. */
    public function unitCost(): float
    {
        if ($this->units_per_pack <= 0) {
            return 0.0;
        }
        if ((float) $this->discount_amount <= 0) {
            return round($this->pack_price * (1 - $this->discount_percent / 100) / $this->units_per_pack, 2);
        }

        return $this->packs > 0 ? round($this->lineTaxable() / ($this->packs * $this->units_per_pack), 2) : 0.0;
    }

    /**
     * What one unit really cost, counting the free packs: on a "10 + 2" scheme the money for
     * ten is spread over twelve. Profit on a sale is measured against this.
     */
    public function effectiveUnitCost(): float
    {
        $units = ($this->packs + $this->free_packs) * $this->units_per_pack;

        return $units > 0 ? round($this->lineTaxable() / $units, 2) : 0.0;
    }

    /**
     * The batch with its worked-out figures, for the invoice screens.
     *
     * @return array<string, mixed>
     */
    public function withCosting(): array
    {
        $cost = $this->effectiveUnitCost();

        return $this->toArray() + [
            'line_taxable' => $this->lineTaxable(), 'line_tax' => $this->lineTax(),
            'unit_cost' => $cost,
            'margin_percent' => $this->unit_mrp > 0 ? round(($this->unit_mrp - $cost) / $this->unit_mrp * 100, 1) : null,
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }
}
