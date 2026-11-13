<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'admission_id', 'diagnosis', 'complaints', 'history', 'examination', 'investigations', 'treatment_given', 'procedures', 'operative_findings', 'course',
    'condition_at_discharge', 'medicines', 'advice', 'tests_requested', 'other_instructions', 'acknowledgement', 'follow_up_on', 'prepared_by', 'approved_at', 'approved_by',
])]
class DischargeSummary extends Model
{
    public const TEXTS = ['diagnosis', 'complaints', 'history', 'examination', 'investigations', 'treatment_given', 'procedures', 'operative_findings', 'course', 'condition_at_discharge', 'advice', 'tests_requested', 'other_instructions', 'acknowledgement'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['medicines' => 'array', 'follow_up_on' => 'date:Y-m-d', 'approved_at' => 'datetime:Y-m-d H:i'];
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
