<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\DoctorBlock;
use App\Models\LabOrder;
use App\Models\RepVisit;
use App\Models\User;
use App\Support\DoctorFee;
use App\Support\DoctorMoney;
use App\Workforce\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One doctor at a glance, opened from their name anywhere: how to reach them, their hours and fee, whether they are
 * away, their patients today, who is admitted under them, representatives waiting, and (for those who see reports)
 * what they have brought in today. Each part goes only to someone whose work needs it.
 */
class DoctorCardController extends Controller
{
    public function show(Request $request, User $doctor): JsonResponse
    {
        $user = $request->user();
        abort_unless($doctor->role === Role::Doctor, 404);
        abort_unless(self::opens($user), 403, 'You are not allowed to see doctors’ details.');
        $today = today();
        $clinical = $user->seesClinical();

        $fee = DoctorFee::of($doctor->id);
        $card = [
            'doctor' => [
                'id' => $doctor->id, 'name' => $doctor->name, 'department' => $doctor->department, 'phone' => $doctor->phone,
                'active' => (bool) $doctor->is_active, 'availability' => $doctor->availability,
                'hours_today' => ($doctor->availability ?? [])[strtolower($today->format('D'))] ?? [], 'slot_minutes' => $doctor->slot_minutes,
                'fee' => $fee ? (float) $fee->price : null,
            ],
            'away' => DoctorBlock::where('doctor_id', $doctor->id)->between($today->toDateString(), $today->copy()->addDays(14)->toDateString())
                ->orderBy('from_date')->limit(5)->get()
                ->map(fn (DoctorBlock $b) => ['from' => $b->from_date->format('Y-m-d'), 'to' => $b->to_date->format('Y-m-d'), 'start' => $b->start_time, 'end' => $b->end_time, 'reason' => $b->reason, 'leave' => $b->leave_request_id !== null])->values(),
        ];

        $visits = Appointment::where('doctor_id', $doctor->id)->whereDate('scheduled_at', $today)->where('status', '!=', 'cancelled')
            ->with('patient:id,uhid,title,name')->orderBy('token_no')->orderBy('scheduled_at')->get();
        $card['today'] = [
            'booked' => $visits->where('status', 'booked')->count(), 'waiting' => $visits->where('status', 'arrived')->count(),
            'with_doctor' => $visits->where('status', 'ongoing')->count(), 'seen' => $visits->where('status', 'reviewed')->count(),
            // Who is here and waiting, in token order, so the desk can say who is next.
            'queue' => $visits->whereIn('status', ['arrived', 'ongoing'])->take(8)->map(fn (Appointment $a) => [
                'id' => $a->id, 'token' => $a->token_no, 'status' => $a->status, 'patient' => $a->patient?->only(['id', 'uhid', 'name']),
            ])->values(),
        ];

        if ($user->allows('canWorkIpd') || $clinical) {
            $card['inpatients'] = Admission::where('doctor_id', $doctor->id)->where('status', 'admitted')->with(['patient:id,uhid,title,name', 'bed:id,ward,bed_no'])
                ->orderBy('admitted_at')->get()->map(fn (Admission $a) => [
                    'id' => $a->id, 'ipd_no' => $a->ipd_no, 'patient' => $a->patient?->only(['id', 'uhid', 'name']),
                    'bed' => $a->bed ? "{$a->bed->ward} · Bed {$a->bed->bed_no}" : null, 'since' => $a->admitted_at->format('Y-m-d H:i'),
                ])->values();
        }
        if ($clinical) {
            $card['results_unseen'] = LabOrder::unseenBy($doctor->id)->count();
        }
        if ($user->allows('canWorkFrontDesk') || $user->id === $doctor->id || $user->role === Role::Admin) {
            $card['reps_waiting'] = RepVisit::where('doctor_id', $doctor->id)->where('status', 'waiting')->whereDate('arrived_at', $today)
                ->with(['rep:id,rep_company_id,name', 'rep.company:id,name'])->orderBy('arrived_at')->get()
                ->map(fn (RepVisit $v) => ['id' => $v->id, 'rep' => $v->rep?->name, 'company' => $v->rep?->company?->name])->values();
        }
        if ($user->allows('canViewReports')) {
            $row = DoctorMoney::between($today->copy()->startOfDay(), $today->copy()->endOfDay())->firstWhere('doctor_id', $doctor->id);
            $card['money_today'] = ['billed' => $row['billed'] ?? 0.0, 'collected' => $row['collected'] ?? 0.0];
        }
        // Their staff record, for those who keep staff records.
        if ($user->allows('wf:staff:view')) {
            $card['employee_id'] = Employee::withoutGlobalScopes()->where('user_id', $doctor->id)->value('id');
        }

        return response()->json($card);
    }

    /** Anyone who works at a clinic desk; not a login that only keeps its own attendance. */
    public static function opens(User $user): bool
    {
        foreach (['canWorkFrontDesk', 'canConsult', 'canWorkIpd', 'canRunLab', 'canRunPharmacy', 'canViewReports', 'canViewPharmacy', 'canViewClinical'] as $ability) {
            if ($user->allows($ability)) {
                return true;
            }
        }

        return false;
    }
}
