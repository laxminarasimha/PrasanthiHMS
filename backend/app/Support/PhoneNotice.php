<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

use function Illuminate\Support\defer;

/**
 * What is sent to a person's phone, and when.
 *
 * A notification is sent after the answer has gone back to whoever is at the screen, so the desk never
 * waits for a phone. It is sent to every device the person has switched notifications on for, and a
 * failure to send never undoes or holds up the work that caused it.
 */
final class PhoneNotice
{
    /**
     * A doctor is told when someone else puts a patient on their list: the desk booking or checking a patient in,
     * or a patient booking from their own phone. Not told about a visit the doctor started themselves, nor about
     * an old visit being entered late.
     */
    public static function appointmentAdded(Appointment $appointment, ?int $addedBy, bool $fromPatientApp = false): void
    {
        $at = Carbon::parse($appointment->getRawOriginal('scheduled_at'));
        // A visit just made may not have read its starting status back yet: it starts as booked.
        $status = $appointment->status ?? 'booked';
        if ($addedBy === (int) $appointment->doctor_id || ! in_array($status, ['booked', 'arrived'], true) || $at->lt(today())) {
            return;
        }

        $day = $at->isToday() ? 'Today' : ($at->isTomorrow() ? 'Tomorrow' : $at->format('D j M'));
        $words = [$appointment->patient?->name ?? 'A patient', $day.' '.$at->format('g:i A')];
        if ($appointment->token_no) {
            $words[] = 'Token '.$appointment->token_no;
        }
        if ($fromPatientApp) {
            $words[] = 'booked from the patient app';
        }

        self::later((int) $appointment->doctor_id, [
            'title' => $status === 'arrived' ? 'Patient waiting' : 'New appointment',
            'body' => implode(' · ', $words),
            'url' => '/doctor',
            'tag' => 'appointment-'.$appointment->id,
            'kind' => 'appointment',
        ]);
    }

    /**
     * A doctor is told when the desk marks a patient who was booked earlier as having reached the hospital.
     * It takes the place of the "New appointment" notice for the same visit on the phone, and sounds again.
     * Not told when the doctor marked it themselves, nor for a visit of an earlier day being tidied up.
     */
    public static function patientArrived(Appointment $appointment, ?int $markedBy): void
    {
        $at = Carbon::parse($appointment->getRawOriginal('scheduled_at'));
        if ($markedBy === (int) $appointment->doctor_id || $at->lt(today())) {
            return;
        }

        $words = [$appointment->patient?->name ?? 'A patient', 'has arrived', 'booked for '.$at->format('g:i A')];
        if ($appointment->token_no) {
            $words[] = 'Token '.$appointment->token_no;
        }

        self::later((int) $appointment->doctor_id, [
            'title' => 'Patient waiting',
            'body' => implode(' · ', $words),
            'url' => '/doctor',
            'tag' => 'appointment-'.$appointment->id,
            'kind' => 'appointment',
        ]);
    }

    /**
     * Sends once the answer to the present request has gone out.
     *
     * @param  array<string, mixed>  $message
     */
    public static function later(int $userId, array $message): void
    {
        // Most people have not switched notifications on: nothing is set aside for them.
        if (! PushSubscription::where('user_id', $userId)->exists()) {
            return;
        }
        defer(function () use ($userId, $message) {
            try {
                self::now($userId, $message);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Sends to each of the person's devices, and says how many took it.
     *
     * @param  array<string, mixed>  $message
     */
    public static function now(int $userId, array $message): int
    {
        if (! User::whereKey($userId)->where('is_active', true)->exists()) {
            return 0;
        }

        return PushSubscription::where('user_id', $userId)->get()->filter(fn (PushSubscription $device) => $device->deliver($message))->count();
    }
}
