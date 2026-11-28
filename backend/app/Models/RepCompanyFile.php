<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One file a company's representative left: a catalogue, a price list, a leaflet. */
#[Fillable(['rep_company_id', 'title', 'path', 'original_name', 'mime', 'size', 'uploaded_by'])]
#[Hidden(['path'])]
class RepCompanyFile extends Model
{
    public function company(): BelongsTo
    {
        return $this->belongsTo(RepCompany::class, 'rep_company_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
