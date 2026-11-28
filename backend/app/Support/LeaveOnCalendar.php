<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\DoctorBlock;
use App\Models\User;
use App\Workforce\Models\Employee;
use App\Workforce\Models\LeaveRequest;

/**
 * A doctor's leave, entered once in the staff pages, also closes their appointment calendar for those days.
 * Without this the leave and the calendar block were two entries, and a forgotten block let patients be booked
 * for a doctor who was away.
 *
 * Only whole days are blocked: a half day at either end of a leave is left open, and the person approving is told
 * to block those hours on the calendar themself. The block carries the kind of leave, never the person's own reason.
 */
class LeaveOnCalendar
{
    /** The doctor whose calendar a leave belongs to, when the member of staff is one. */
    private static function doctor(LeaveRequest $leave): ?User
    {
        // Read by its own id, whichever branch is being worked in.
        $userId = Employee::withoutGlobalScopes()->whereKey($leave->employee_id)->value('user_id');

        return $userId ? User::whereKey($userId)->where('role', Role::Doctor->value)->first() : null;
    }

    /**
     * The whole days a leave covers: a half day at the start or the end is left out.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function wholeDays(LeaveRequest $leave): ?array
    {
        $from = $leave->from_date->copy();
        $to = $leave->to_date->copy();
        if (($leave->from_part ?? 'full') !== 'full') {
            $from->addDay();
        }
        if (($leave->to_part ?? 'full') !== 'full' && ! ($leave->from_date->equalTo($leave->to_date) && ($leave->from_part ?? 'full') !== 'full')) {
            $to->subDay();
        }

        return $from->lte($to) ? [$from->toDateString(), $to->toDateString()] : null;
    }

    /** Called as a leave is approved: closes the doctor's calendar for its whole days. */
    public static function block(LeaveRequest $leave): ?DoctorBlock
    {
        $doctor = self::doctor($leave);
        $days = self::wholeDays($leave);
        if (! $doctor || ! $days) {
            return null;
        }

        return DoctorBlock::updateOrCreate(['leave_request_id' => $leave->id], [
            'doctor_id' => $doctor->id, 'from_date' => $days[0], 'to_date' => $days[1], 'start_time' => null, 'end_time' => null,
            'reason' => mb_substr($leave->type?->name ?? 'Leave', 0, 100), 'created_by' => auth()->id(),
        ]);
    }

    /** Called as a leave is cancelled: the calendar opens again. */
    public static function unblock(LeaveRequest $leave): void
    {
        DoctorBlock::where('leave_request_id', $leave->id)->delete();
    }

    /**
     * What approving this leave did to the calendar, for the person who approved it: whether it is a doctor's, which
     * days are now closed, whether a half day was left for them to block, and the patients already booked in those days.
     *
     * @return array<string, mixed>|null  nothing for a member of staff who is not a doctor
     */
    public static function summary(LeaveRequest $leave): ?array
    {
        $doctor = self::doctor($leave);
        if (! $doctor) {
            return null;
        }
        $block = DoctorBlock::where('leave_request_id', $leave->id)->first();

        return [
            'doctor' => $doctor->name,
            'blocked' => $block ? ['from' => $block->from_date->toDateString(), 'to' => $block->to_date->toDateString()] : null,
            'half_day' => ($leave->from_part ?? 'full') !== 'full' || ($leave->to_part ?? 'full') !== 'full',
            'booked' => $block ? $block->booked()->map(fn ($a) => [
                'id' => $a->id, 'patient' => $a->patient?->name, 'patient_id' => $a->patient_id, 'mobile' => $a->patient?->mobile, 'at' => $a->scheduled_at->format('Y-m-d H:i'),
            ])->values()->all() : [],
        ];
    }
}
