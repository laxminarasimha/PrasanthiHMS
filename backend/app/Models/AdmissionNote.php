<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A clinical, nursing or operation note written during a stay. */
#[Fillable(['admission_id', 'kind', 'noted_at', 'body', 'details', 'created_by', 'updated_by'])]
class AdmissionNote extends Model
{
    public const KINDS = ['clinical', 'nursing', 'ot'];

    /** The extra fields each kind of note carries besides its text. */
    public const DETAILS = [
        'clinical' => [],
        'nursing' => ['bp', 'pulse', 'temperature', 'spo2', 'resp_rate', 'intake', 'output'],
        'ot' => ['procedure', 'surgeon', 'assistant', 'anaesthetist', 'anaesthesia', 'findings', 'post_op'],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['noted_at' => 'datetime:Y-m-d H:i', 'details' => 'array'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
