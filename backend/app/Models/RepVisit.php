<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One time a medical representative came to meet a doctor: logged at the desk on arrival, closed after the meeting. */
#[Fillable(['branch_id', 'medical_rep_id', 'doctor_id', 'arrived_at', 'status', 'purpose', 'discussed', 'offers', 'next_on', 'closed_at', 'closed_by', 'created_by'])]
class RepVisit extends Model
{
    /** Waiting to be seen; met; not met that day; or asked to come on another day. */
    public const STATUSES = ['waiting', 'met', 'not_met', 'come_back'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['arrived_at' => 'datetime:Y-m-d H:i', 'closed_at' => 'datetime:Y-m-d H:i', 'next_on' => 'date:Y-m-d'];
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(MedicalRep::class, 'medical_rep_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function logger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
