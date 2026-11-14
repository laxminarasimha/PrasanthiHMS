<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Medicines a patient takes every month, to be kept ready by the pharmacy on a chosen day. */
#[Fillable(['patient_account_id', 'patient_id', 'title', 'items', 'day_of_month', 'status', 'next_due_on', 'last_prepared_on', 'last_prepared_by', 'notes'])]
class MedicineSubscription extends Model
{
    public const STATUSES = ['active', 'paused', 'cancelled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['items' => 'array', 'next_due_on' => 'date:Y-m-d', 'last_prepared_on' => 'date:Y-m-d'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PatientAccount::class, 'patient_account_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_prepared_by');
    }

    /** The next date on or after $from that falls on the chosen day of the month. */
    public static function dueOnOrAfter(int $day, ?Carbon $from = null): Carbon
    {
        $from = ($from ?? today())->copy()->startOfDay();
        $due = $from->copy()->day($day);

        return $due->lt($from) ? $due->addMonthNoOverflow() : $due;
    }
}
