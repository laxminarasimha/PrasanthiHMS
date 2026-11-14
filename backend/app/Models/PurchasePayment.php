<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One payment made to a supplier against one of their invoices. */
#[Fillable(['purchase_invoice_id', 'amount', 'mode', 'reference', 'paid_on', 'remarks', 'paid_by'])]
class PurchasePayment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'float', 'paid_on' => 'date:Y-m-d'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
