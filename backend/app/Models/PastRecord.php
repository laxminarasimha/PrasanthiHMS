<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One old paper a patient brought, scanned. A machine reads it into a draft; a person checks the
 * draft against the scan; only what that person files becomes part of the patient's record.
 */
#[Fillable(['patient_id', 'kind', 'record_date', 'doctor_name', 'hospital_name', 'status', 'read_state', 'read_engine', 'read_text', 'read_error', 'read_claimed_at', 'read_at', 'ai_sent_at', 'draft', 'details', 'created_by', 'checked_by', 'checked_at'])]
#[Hidden(['read_text', 'draft'])]
class PastRecord extends Model
{
    /** What the paper is, as staff choose it. */
    public const KINDS = [
        'visit' => 'Prescription or consultation',
        'lab' => 'Lab report',
        'imaging' => 'Scan or X-ray report',
        'discharge' => 'Discharge summary',
        'other' => 'Other paper',
    ];

    /** The written parts of a checked record, in the order they are shown. */
    public const TEXTS = ['complaints', 'diagnosis', 'findings', 'advice', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'record_date' => 'date:Y-m-d', 'draft' => 'array', 'details' => 'array',
            'read_claimed_at' => 'datetime', 'read_at' => 'datetime:Y-m-d H:i', 'ai_sent_at' => 'datetime:Y-m-d H:i', 'checked_at' => 'datetime:Y-m-d H:i',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Attachment::class)->orderBy('page_no')->orderBy('id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(PastRecordResult::class)->orderBy('position')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
