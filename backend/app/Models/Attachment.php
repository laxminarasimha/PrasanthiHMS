<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['patient_id', 'appointment_id', 'title', 'document_date', 'path', 'original_name', 'mime', 'size', 'uploaded_by'])]
#[Hidden(['path'])]
class Attachment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['document_date' => 'date:Y-m-d'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
