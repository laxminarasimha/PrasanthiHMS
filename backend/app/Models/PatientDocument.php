<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A certificate, letter or consent form issued to a patient. */
#[Fillable(['patient_id', 'kind', 'title', 'addressed_to', 'body', 'issued_on', 'doctor_id', 'appointment_id', 'admission_id', 'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason'])]
class PatientDocument extends Model
{
    /** Each kind: what it is called, and the letters its number starts with. */
    public const KINDS = [
        'medical' => ['Medical certificate', 'MC'],
        'fitness' => ['Fitness certificate', 'FC'],
        'referral' => ['Referral letter', 'RL'],
        'consent' => ['Consent form', 'CF'],
        'letter' => ['Letter', 'LT'],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['issued_on' => 'date:Y-m-d', 'cancelled_at' => 'datetime:Y-m-d H:i'];
    }

    protected static function booted(): void
    {
        static::created(function (PatientDocument $document) {
            $document->doc_no = self::KINDS[$document->kind][1].str_pad((string) $document->id, 5, '0', STR_PAD_LEFT);
            $document->saveQuietly();
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
