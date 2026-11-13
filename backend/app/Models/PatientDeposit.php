<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One movement of a patient's deposit: money in, money used against a bill, or money handed back. */
#[Fillable(['patient_id', 'type', 'amount', 'mode', 'reference', 'bill_id', 'received_by'])]
class PatientDeposit extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** What the patient still has on deposit. */
    public static function balance(int $patientId): float
    {
        $sums = static::where('patient_id', $patientId)->selectRaw('type, sum(amount) as amount')->groupBy('type')->pluck('amount', 'type');

        return round((float) ($sums['deposit'] ?? 0) - (float) ($sums['used'] ?? 0) - (float) ($sums['refund'] ?? 0), 2);
    }
}
