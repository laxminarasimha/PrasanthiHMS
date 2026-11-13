<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\LabOrder;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The doctor's view of one patient across time: start a visit, read the whole story, follow test results. */
class CaseHistoryController extends Controller
{
    /**
     * Opens a visit pad for a patient who was not booked at the front desk.
     * If the patient already has an open visit with this doctor today, that one is used.
     */
    public function startVisit(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'doctor_id' => [Rule::requiredIf($user->role !== Role::Doctor), 'nullable', Rule::exists('users', 'id')->where('role', Role::Doctor->value)],
        ], ['doctor_id.required' => 'Choose the doctor for this visit.']);
        $doctorId = $user->role === Role::Doctor ? $user->id : (int) $data['doctor_id'];

        [$appointment, $created] = DB::transaction(function () use ($data, $doctorId, $user) {
            $open = Appointment::where('patient_id', $data['patient_id'])->where('doctor_id', $doctorId)
                ->whereDate('scheduled_at', today())->whereIn('status', ['booked', 'arrived', 'ongoing'])
                ->orderBy('scheduled_at')->first();
            if ($open) {
                if ($open->status === 'booked') {
                    $open->update(['status' => 'arrived', 'arrived_at' => now()]);
                }

                return [$open, false];
            }

            return [Appointment::create([
                'branch_id' => $user->branch_id,
                'patient_id' => $data['patient_id'],
                'doctor_id' => $doctorId,
                'scheduled_at' => now()->format('Y-m-d H:i'),
                'status' => 'arrived',
                'arrived_at' => now(),
                'notes' => 'Visit started by the doctor, not booked at the front desk',
                'created_by' => $user->id,
                'token_no' => (int) Appointment::where('doctor_id', $doctorId)->whereDate('scheduled_at', today())->lockForUpdate()->max('token_no') + 1,
            ]), true];
        });

        return response()->json(['id' => $appointment->id, 'created' => $created], $created ? 201 : 200);
    }

    /** Every visit, admission and lab order of one patient, newest first, with the clinical notes. */
    public function show(Request $request, Patient $patient): JsonResponse
    {
        $this->guard($request);

        $visits = Consultation::where('patient_id', $patient->id)
            ->with(['medicines', 'doctor:id,name', 'appointment:id,scheduled_at,status'])
            ->latest('id')->limit(200)->get()
            ->map(fn (Consultation $c) => [
                'kind' => 'visit',
                'key' => "v{$c->id}",
                'at' => $c->appointment?->scheduled_at?->format('Y-m-d H:i') ?? $c->created_at->format('Y-m-d H:i'),
                'appointment_id' => $c->appointment_id,
                'visit' => $c->makeHidden('appointment'),
            ]);

        $admissions = Admission::where('patient_id', $patient->id)
            ->with(['doctor:id,name', 'summary'])
            ->latest('admitted_at')->limit(100)->get()
            ->map(fn (Admission $a) => [
                'kind' => 'admission',
                'key' => "a{$a->id}",
                'at' => $a->admitted_at->format('Y-m-d H:i'),
                'admission' => [
                    'id' => $a->id, 'ipd_no' => $a->ipd_no, 'status' => $a->status, 'reason' => $a->reason, 'doctor' => $a->doctor?->only(['id', 'name']),
                    'admitted_at' => $a->admitted_at->format('Y-m-d H:i'), 'discharged_at' => $a->discharged_at?->format('Y-m-d H:i'),
                    'summary' => $a->summary?->only(['diagnosis', 'procedures', 'course', 'condition_at_discharge', 'advice', 'approved_at']),
                ],
            ]);

        $labs = LabOrder::where('patient_id', $patient->id)
            ->with(['items' => fn ($q) => $q->select('id', 'lab_order_id', 'test_name', 'collected_at', 'reported_at', 'approved_at')->with('results')])
            ->latest('ordered_at')->limit(100)->get()
            ->map(fn (LabOrder $o) => [
                'kind' => 'lab',
                'key' => "l{$o->id}",
                'at' => $o->ordered_at->format('Y-m-d H:i'),
                'lab' => [
                    'id' => $o->id, 'order_no' => $o->order_no, 'status' => $o->status,
                    'tests' => $o->items->map(fn ($i) => [
                        'name' => $i->test_name,
                        'reported' => $i->reported_at !== null,
                        'results' => $i->reported_at ? $i->results->where('is_heading', false)->filter(fn ($r) => filled($r->value))
                            ->map(fn ($r) => ['parameter' => $r->parameter, 'value' => $r->value, 'unit' => $r->unit, 'flag' => $r->flag])->values() : [],
                    ]),
                ],
            ]);

        return response()->json([
            'patient' => $patient,
            'events' => $visits->concat($admissions)->concat($labs)->sortByDesc('at')->values(),
        ]);
    }

    /**
     * Results of the same test side by side across dates, and the vital signs across visits.
     * Only results the lab has reported are shown.
     */
    public function trends(Request $request, Patient $patient): JsonResponse
    {
        $this->guard($request);

        $rows = DB::table('lab_results as r')
            ->join('lab_order_items as i', 'i.id', '=', 'r.lab_order_item_id')
            ->join('lab_orders as o', 'o.id', '=', 'i.lab_order_id')
            ->where('o.patient_id', $patient->id)->whereNotNull('i.reported_at')
            ->where('r.is_heading', false)->whereNotNull('r.value')->where('r.value', '!=', '')
            ->orderBy('i.reported_at')->orderBy('r.id')
            ->get(['r.parameter', 'r.unit', 'r.reference_range', 'r.value', 'r.flag', 'i.test_name', 'i.reported_at', 'o.id as order_id', 'o.order_no']);

        $tests = $rows->groupBy(fn ($r) => mb_strtolower(trim($r->test_name).'|'.trim($r->parameter)))->map(function ($group) {
            $last = $group->last();

            return [
                'test' => $last->test_name,
                'parameter' => $last->parameter,
                'unit' => $last->unit,
                'reference_range' => $last->reference_range,
                'points' => $group->map(fn ($r) => [
                    'date' => substr((string) $r->reported_at, 0, 10),
                    'value' => $r->value,
                    // A plain number can be drawn; "Positive" or "1:160" cannot.
                    'number' => is_numeric(str_replace(',', '', trim($r->value))) ? (float) str_replace(',', '', trim($r->value)) : null,
                    'flag' => $r->flag,
                    'order_id' => $r->order_id,
                    'order_no' => $r->order_no,
                ])->values(),
            ];
        })->sortBy([['test', 'asc']])->values();

        $vitals = Consultation::where('patient_id', $patient->id)
            ->where(fn ($q) => $q->whereNotNull('weight_kg')->orWhereNotNull('bp_systolic')->orWhereNotNull('pulse')->orWhereNotNull('temperature_f')->orWhereNotNull('spo2')->orWhereNotNull('height_cm'))
            ->oldest('created_at')->limit(200)
            ->get(['id', 'created_at', 'weight_kg', 'height_cm', 'bp_systolic', 'bp_diastolic', 'pulse', 'temperature_f', 'spo2', 'resp_rate'])
            ->map(fn (Consultation $c) => ['date' => $c->created_at->toDateString()] + $c->only(['weight_kg', 'height_cm', 'bp_systolic', 'bp_diastolic', 'pulse', 'temperature_f', 'spo2', 'resp_rate']));

        return response()->json(['tests' => $tests, 'vitals' => $vitals]);
    }

    /** Clinical notes are for the people who treat or test the patient, not for billing counters. */
    private function guard(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->allows('canConsult') || $user->allows('canRunLab'), 403, 'You are not allowed to see clinical notes.');
    }
}
