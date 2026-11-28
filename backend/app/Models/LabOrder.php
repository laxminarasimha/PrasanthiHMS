<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\PhoneNotice;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['branch_id', 'patient_id', 'appointment_id', 'bill_id', 'referred_by', 'referral_doctor_id', 'status', 'ordered_at', 'created_by', 'admission_id'])]
class LabOrder extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['ordered_at' => 'datetime:Y-m-d H:i', 'ready_at' => 'datetime:Y-m-d H:i', 'seen_at' => 'datetime:Y-m-d H:i'];
    }

    protected static function booted(): void
    {
        static::created(function (LabOrder $order) {
            $order->order_no = 'L'.str_pad((string) $order->id, 5, '0', STR_PAD_LEFT);
            $order->saveQuietly();
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LabOrderItem::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(LabOrderFile::class)->orderBy('id');
    }

    /** Pending until any test is reported, completed once all are. */
    public function refreshStatus(): void
    {
        $total = $this->items()->count();
        $reported = $this->items()->whereNotNull('reported_at')->count();

        $this->update(['status' => $reported === 0 ? 'pending' : ($reported < $total ? 'partial' : 'completed')]);
    }

    /**
     * Tells the doctor when results are ready to read, and takes that back if a result is reopened. Called after
     * anything that finishes or reopens a test. Results are ready once something is finished and nothing done here
     * is still awaited: a test sent to an outside lab does not hold the rest back, and is told again when it comes.
     * "Finished" is approved where the lab approves its results, otherwise reported.
     */
    public function markReady(?int $byUserId = null): void
    {
        $approves = (bool) Setting::group('lab', Setting::LAB_DEFAULTS)['approval_required'];
        $items = $this->items()->get(['id', 'reported_at', 'approved_at', 'outside_lab']);
        $finished = fn (LabOrderItem $i) => $approves ? $i->approved_at !== null : $i->reported_at !== null;
        $done = $items->filter($finished)->count();
        $awaitedHere = $items->reject($finished)->filter(fn (LabOrderItem $i) => blank($i->outside_lab))->count();

        if ($done > 0 && $awaitedHere === 0) {
            if ($done > (int) $this->ready_tests || ! $this->ready_at) {
                $doctor = $this->ready_for ?? $this->doctorToTell();
                // Finished by the doctor themself, there is nothing to tell them.
                $own = $doctor !== null && $doctor === $byUserId;
                $this->forceFill(['ready_at' => now(), 'ready_for' => $doctor, 'ready_tests' => $done, 'seen_at' => $own ? now() : null])->saveQuietly();
                if ($doctor && ! $own) {
                    PhoneNotice::resultsReady($this, $doctor);
                }
            } elseif ($done < (int) $this->ready_tests) {
                $this->forceFill(['ready_tests' => $done])->saveQuietly();
            }
        } elseif ($this->ready_at) {
            $this->forceFill(['ready_at' => null, 'ready_tests' => 0, 'seen_at' => null])->saveQuietly();
        }
    }

    /**
     * Whose patient this is: the doctor of the IPD case the tests were ordered from, else of the visit they were
     * ordered at, else of the bed the patient is in, else the doctor the patient saw that day or the day before
     * (an order made at the lab counter is not tied to a visit).
     */
    private function doctorToTell(): ?int
    {
        $doctor = $this->admission_id ? Admission::whereKey($this->admission_id)->value('doctor_id') : null;
        $doctor ??= $this->appointment_id ? Appointment::whereKey($this->appointment_id)->value('doctor_id') : null;
        $doctor ??= Admission::where('patient_id', $this->patient_id)->where('status', 'admitted')->value('doctor_id');
        $at = Carbon::parse($this->getRawOriginal('ordered_at') ?? now());
        $doctor ??= Appointment::where('patient_id', $this->patient_id)->where('status', '!=', 'cancelled')
            ->whereBetween('scheduled_at', [$at->copy()->subDay()->startOfDay(), $at->copy()->endOfDay()])->latest('scheduled_at')->value('doctor_id');

        return $doctor ? (int) $doctor : null;
    }

    /** Results that are ready and that this doctor has not looked at yet. */
    public function scopeUnseenBy($query, int $doctorId)
    {
        return $query->whereNotNull('ready_at')->whereNull('seen_at')->where('ready_for', $doctorId);
    }
}
