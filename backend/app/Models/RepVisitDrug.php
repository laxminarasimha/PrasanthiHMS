<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One drug a representative spoke about at a visit, written the way a line of a prescription is. */
#[Fillable(['rep_visit_id', 'rep_product_id', 'name', 'composition', 'uses', 'dose', 'frequency', 'timing', 'duration', 'note', 'mrp', 'rate', 'scheme', 'keep', 'position'])]
class RepVisitDrug extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['keep' => 'boolean', 'mrp' => 'float', 'rate' => 'float'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(RepVisit::class, 'rep_visit_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(RepProduct::class, 'rep_product_id');
    }
}
