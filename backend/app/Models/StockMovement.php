<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** One change to the count of a batch: what came in or went out, what it was for, and who did it. */
#[Fillable(['stock_batch_id', 'medicine_id', 'kind', 'units', 'balance_after', 'reference', 'note', 'created_by'])]
class StockMovement extends Model
{
    /** What a movement was, in the words shown in the stock history. */
    public const KINDS = [
        'baseline' => 'In stock when the stock record began',
        'opening' => 'Opening stock entered',
        'purchase' => 'Received from stockist',
        'purchase_edit' => 'Purchase invoice corrected',
        'sale' => 'Sold',
        'sale_edit' => 'Bill changed',
        'sale_return' => 'Returned by customer',
        'sale_void' => 'Bill deleted',
        'stockist_return' => 'Returned to stockist',
        'correction' => 'Stock corrected',
        'count' => 'Stock count',
    ];

    /**
     * Writes the ledger line for a change that has just been made to a batch.
     * The balance is read back from the batch itself, so it is what the shelf record now says.
     */
    public static function record(StockBatch $batch, int $units, string $kind, ?string $reference = null, ?string $note = null): self
    {
        return static::create([
            'stock_batch_id' => $batch->id, 'medicine_id' => $batch->medicine_id, 'kind' => $kind, 'units' => $units,
            'balance_after' => (int) DB::table('stock_batches')->where('id', $batch->id)->value('units_in_stock'),
            'reference' => $reference, 'note' => $note, 'created_by' => auth()->id(),
        ]);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
