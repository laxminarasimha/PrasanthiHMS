<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Some of a lot of samples leaving the shelf: given to someone, or thrown away. */
#[Fillable(['rep_sample_id', 'kind', 'quantity', 'given_on', 'patient_id', 'note', 'given_by'])]
class RepSampleIssue extends Model
{
    public const KINDS = ['given', 'discarded'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'given_on' => 'date:Y-m-d'];
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(RepSample::class, 'rep_sample_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }
}
