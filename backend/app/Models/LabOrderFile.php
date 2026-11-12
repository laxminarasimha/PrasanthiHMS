<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A result file uploaded against a lab order, optionally for one test in it. */
#[Fillable(['lab_order_id', 'lab_order_item_id', 'title', 'path', 'original_name', 'mime', 'size', 'uploaded_by'])]
#[Hidden(['path'])]
class LabOrderFile extends Model
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
