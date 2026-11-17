<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One test value copied from an old report. */
#[Fillable(['past_record_id', 'patient_id', 'test_name', 'parameter', 'value', 'unit', 'reference_range', 'flag', 'taken_on', 'position'])]
class PastRecordResult extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['taken_on' => 'date:Y-m-d'];
    }
}
