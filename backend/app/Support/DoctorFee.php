<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Service;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Each doctor's own OPD consultation fee. It is not a second price list: the fee is a service that belongs to the
 * doctor (`services.doctor_id`), so bills, reports and the rule about who may change a price work as for any other
 * service. Choosing the doctor for a visit chooses this service, and its price, by itself.
 */
class DoctorFee
{
    public const NAME = 'OPD consultation';

    /** The service billed when this doctor is chosen: the one marked as theirs, else the first of their own in the list. */
    public static function of(int $doctorId): ?Service
    {
        return Service::where('doctor_id', $doctorId)->where('type', 'appointment')->where('is_active', true)
            ->orderByDesc('is_doctor_fee')->orderBy('priority')->orderBy('name')->first();
    }

    /**
     * Sets the fee from the doctor's own page, by someone who may set prices. Nothing happens when the amount is the
     * one already on record, so saving the rest of the page asks for no permission. Empty means the doctor has no fee
     * of their own and follows the hospital's list: their service is switched off, never removed, because old bills name it.
     */
    public static function change(User $doctor, mixed $fee, User $by): void
    {
        $fee = $fee === null || $fee === '' ? null : round((float) $fee, 2);
        $now = self::of($doctor->id);
        if ($fee === ($now ? round((float) $now->price, 2) : null)) {
            return;
        }
        if (! $by->allows('canSetPrices')) {
            throw ValidationException::withMessages(['opd_fee' => 'Only someone allowed to set prices can set a doctor’s consultation fee. Ask the owner or an admin.']);
        }
        $was = $now ? '₹'.(float) $now->price : 'none';
        if ($fee === null) {
            $now->update(['is_active' => false]);
        } else {
            // One switched off earlier is opened again, so a doctor never collects a row of old fees.
            $service = $now ?? Service::where('doctor_id', $doctor->id)->where('type', 'appointment')->orderByDesc('is_doctor_fee')->orderByDesc('id')->first();
            if ($service) {
                $service->update(['price' => $fee, 'is_active' => true, 'is_doctor_fee' => true]);
            } else {
                $service = Service::create(['name' => self::NAME, 'type' => 'appointment', 'price' => $fee, 'gst_percent' => 0, 'priority' => 1, 'doctor_id' => $doctor->id, 'is_active' => true, 'is_doctor_fee' => true]);
            }
            self::only($service);
        }
        AuditLog::record('doctor.fee', null, $doctor, "{$doctor->name} · OPD consultation fee {$was} → ".($fee === null ? 'none' : '₹'.$fee));
    }

    /** A doctor has one usual consultation: marking a service takes the mark off their others. */
    public static function only(Service $service): void
    {
        Service::where('doctor_id', $service->doctor_id)->whereKeyNot($service->id)->where('is_doctor_fee', true)->update(['is_doctor_fee' => false]);
    }

    /** A doctor's own service is billed only for that doctor's visits; one open to every doctor, for anyone's. */
    public static function fits(?int $serviceId, int $doctorId): void
    {
        $service = $serviceId ? Service::with('doctor:id,name')->find($serviceId) : null;
        if ($service?->doctor_id && (int) $service->doctor_id !== $doctorId) {
            throw ValidationException::withMessages(['service_id' => "“{$service->name}” at ₹".(float) $service->price." is {$service->doctor?->name}’s own. Choose a service for this doctor."]);
        }
    }
}
