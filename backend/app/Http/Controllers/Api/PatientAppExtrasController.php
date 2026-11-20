<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Consultation;
use App\Models\LabOrder;
use App\Models\LabResult;
use App\Models\MedicineSubscription;
use App\Models\Patient;
use App\Models\PatientAccountMember;
use App\Models\PharmacySale;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * More of the patient's mobile app: the family members one login looks after, bills and money
 * due, and the short list of things worth knowing today. Money and medical details are shown
 * only once the hospital has verified the account.
 */
class PatientAppExtrasController extends Controller
{
    // ---- Family ----

    public function family(Request $request): JsonResponse
    {
        return response()->json(['people' => PatientAppController::people($request->user()), 'relations' => PatientAccountMember::RELATIONS, 'most' => PatientAccountMember::MOST]);
    }

    /** Adds a family member. They get a fresh patient record, as the person did on registering; the front desk joins it to any old record. */
    public function addMember(Request $request): JsonResponse
    {
        $account = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'gender' => ['required', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'relation' => ['required', Rule::in(PatientAccountMember::RELATIONS)],
        ]);
        if ($account->members()->count() >= PatientAccountMember::MOST) {
            throw ValidationException::withMessages(['name' => 'An account can look after '.PatientAccountMember::MOST.' family members. Please ask the front desk for help with more.']);
        }
        $same = fn (Patient $p) => mb_strtolower(trim($p->name)) === mb_strtolower(trim($data['name'])) && $p->date_of_birth?->toDateString() === Carbon::parse($data['date_of_birth'])->toDateString();
        if (Patient::whereIn('id', $account->patientIds())->get()->contains($same)) {
            throw ValidationException::withMessages(['name' => 'This person is already on your account.']);
        }

        DB::transaction(function () use ($account, $data) {
            $patient = Patient::create(['name' => trim($data['name']), 'gender' => $data['gender'], 'date_of_birth' => $data['date_of_birth'], 'mobile' => $account->mobile, 'channel' => 'Mobile app']);
            $account->members()->create(['patient_id' => $patient->id, 'relation' => $data['relation']]);
        });

        return response()->json(['people' => PatientAppController::people($account->fresh())], 201);
    }

    /** Takes a family member off this login. Their hospital record stays; only the link goes. */
    public function removeMember(Request $request, Patient $patient): JsonResponse
    {
        $account = $request->user();
        abort_if($patient->id === $account->patient_id, 422, 'You cannot remove yourself from your own account.');
        abort_unless($account->members()->where('patient_id', $patient->id)->exists(), 404, 'That person is not on your account.');
        if (Appointment::where('patient_id', $patient->id)->where('status', 'booked')->where('scheduled_at', '>', now())->exists()) {
            throw ValidationException::withMessages(['patient' => 'This person has an appointment coming up. Cancel it first.']);
        }
        $account->members()->where('patient_id', $patient->id)->delete();

        return response()->json(['people' => PatientAppController::people($account->fresh())]);
    }

    /**
     * Closes the app login for good, at the person's own request: the login, its family links and its
     * monthly medicine lists go. The hospital's medical and billing records of the patient are kept,
     * as a hospital must keep them.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $account = $request->user();
        $data = $request->validate(['password' => ['required', 'string']], ['password.required' => 'Enter your password to close the account.']);
        if (! Hash::check($data['password'], $account->password)) {
            throw ValidationException::withMessages(['password' => 'That is not your password.']);
        }

        DB::transaction(function () use ($account) {
            AuditLog::record('app.deleted', $account->patient_id, null, "Mobile {$account->mobile}", null, $account->name);
            $account->tokens()->delete();
            $account->delete();
        });

        return response()->json(['message' => 'Your account is closed.']);
    }

    // ---- Bills ----

    /** What one person on the account has been billed, paid and still owes, at every counter. */
    public function bills(Request $request): JsonResponse
    {
        $account = $request->user();
        $patientId = $account->patientFor($request->query('patient_id'));
        if (! $account->verified_at) {
            return response()->json(['verified' => false, 'bills' => [], 'due' => 0]);
        }

        return response()->json(['verified' => true] + $this->billsOf([$patientId]));
    }

    /**
     * @param  list<int>  $patientIds
     * @return array{bills: list<array<string, mixed>>, due: float}
     */
    private function billsOf(array $patientIds): array
    {
        $labBills = LabOrder::whereIn('patient_id', $patientIds)->whereNotNull('bill_id')->pluck('bill_id')->flip();
        $row = fn (string $kind, string $no, $date, float $total, float $paid, float $due, int $patientId) => [
            'kind' => $kind, 'bill_no' => $no, 'date' => Carbon::parse($date)->toDateString(), 'total' => round($total, 2), 'paid' => round($paid, 2), 'due' => round(max(0, $due), 2), 'patient_id' => $patientId,
        ];

        $bills = collect()
            ->concat(Bill::whereIn('patient_id', $patientIds)->whereNull('cancelled_at')->latest('id')->limit(100)->get()
                ->map(fn (Bill $b) => $row($labBills->has($b->id) ? 'Lab' : 'Consultation', $b->bill_no, $b->created_at, $b->total, $b->paid, $b->due, $b->patient_id)))
            ->concat(PharmacySale::whereIn('patient_id', $patientIds)->whereNull('voided_at')->latest('id')->limit(100)->get()
                ->map(fn (PharmacySale $s) => $row('Pharmacy', $s->bill_no, $s->sold_at, $s->total - $s->returned, $s->paid, $s->due, $s->patient_id)))
            ->concat(Admission::whereIn('patient_id', $patientIds)->latest('id')->limit(30)->get()
                ->map(fn (Admission $a) => $row($a->status === 'admitted' ? 'Hospital stay (still admitted)' : 'Hospital stay', $a->ipd_no, $a->admitted_at, $a->net, $a->paid, $a->due, $a->patient_id)))
            ->sortByDesc('date')->values();

        return ['bills' => $bills->all(), 'due' => round((float) $bills->sum('due'), 2)];
    }

    // ---- Today ----

    /**
     * The few things worth knowing when the app is opened: an appointment coming up, a lab report
     * that is ready, a visit the doctor asked for, medicines due, money owed.
     */
    /**
     * A person's readings over time, for "My health": the vitals taken at their visits, and every lab value that has
     * been measured at least twice, oldest first. Only what the person may already see in their records: nothing
     * before the account is verified, and lab values only once reported (and approved, where the lab approves).
     */
    public function health(Request $request): JsonResponse
    {
        $account = $request->user();
        $patientId = $account->patientFor($request->query('patient_id'));
        if (! $account->verified_at) {
            return response()->json(['verified' => false, 'vitals' => [], 'lab' => []]);
        }

        $visits = Consultation::where('patient_id', $patientId)
            ->where(fn ($q) => $q->whereNotNull('bp_systolic')->orWhereNotNull('weight_kg')->orWhereNotNull('pulse')->orWhereNotNull('spo2')->orWhereNotNull('temperature_f'))
            ->orderBy('created_at')->limit(200)->get(['created_at', 'completed_at', 'bp_systolic', 'bp_diastolic', 'pulse', 'weight_kg', 'spo2', 'temperature_f']);
        $series = function (string $key, string $label, string $unit) use ($visits) {
            $points = $visits->filter(fn ($v) => $v->{$key} !== null)->map(fn ($v) => ['date' => ($v->completed_at ?? $v->created_at)->format('Y-m-d'), 'value' => (float) $v->{$key}])->values();

            return $points->isEmpty() ? null : ['key' => $key, 'label' => $label, 'unit' => $unit, 'points' => $points];
        };
        $bp = $visits->filter(fn ($v) => $v->bp_systolic !== null && $v->bp_diastolic !== null)
            ->map(fn ($v) => ['date' => ($v->completed_at ?? $v->created_at)->format('Y-m-d'), 'value' => (float) $v->bp_systolic, 'low' => (float) $v->bp_diastolic])->values();
        $vitals = collect([
            $bp->isEmpty() ? null : ['key' => 'bp', 'label' => 'Blood pressure', 'unit' => 'mmHg', 'points' => $bp],
            $series('weight_kg', 'Weight', 'kg'), $series('pulse', 'Pulse', '/min'), $series('spo2', 'Oxygen (SpO₂)', '%'), $series('temperature_f', 'Temperature', '°F'),
        ])->filter()->values();

        $needsApproval = (bool) Setting::group('lab', Setting::LAB_DEFAULTS)['approval_required'];
        $rows = LabResult::query()->join('lab_order_items as i', 'i.id', '=', 'lab_results.lab_order_item_id')->join('lab_orders as o', 'o.id', '=', 'i.lab_order_id')
            ->where('o.patient_id', $patientId)->whereNotNull('i.reported_at')->when($needsApproval, fn ($q) => $q->whereNotNull('i.approved_at'))
            ->where('lab_results.is_heading', false)->whereNotNull('lab_results.value')
            ->orderBy('o.ordered_at')->limit(3000)
            ->get(['lab_results.parameter', 'lab_results.value', 'lab_results.unit', 'lab_results.reference_range', 'lab_results.flag', 'o.ordered_at']);
        $lab = $rows->filter(fn ($r) => is_numeric(trim((string) $r->value)))
            ->groupBy(fn ($r) => mb_strtolower(trim((string) $r->parameter)))
            ->filter(fn ($list) => $list->count() >= 2)
            ->map(fn ($list) => [
                'name' => trim((string) $list->last()->parameter), 'unit' => $list->last()->unit, 'range' => $list->last()->reference_range,
                'points' => $list->map(fn ($r) => ['date' => Carbon::parse($r->ordered_at)->format('Y-m-d'), 'value' => (float) trim((string) $r->value), 'flag' => $r->flag])->values(),
            ])
            ->sortByDesc(fn ($s) => Carbon::parse($s['points']->last()['date'])->timestamp)->values()->take(30);

        return response()->json(['verified' => true, 'vitals' => $vitals, 'lab' => $lab]);
    }

    public function home(Request $request): JsonResponse
    {
        $account = $request->user();
        $ids = $account->patientIds();
        $names = Patient::whereIn('id', $ids)->pluck('name', 'id');
        // Named only when it is someone other than the person holding the phone.
        $who = fn (int $patientId) => $patientId === $account->patient_id ? '' : " for {$names[$patientId]}";
        $day = fn (Carbon $date) => $date->isToday() ? 'today' : ($date->isTomorrow() ? 'tomorrow' : 'on '.$date->format('D, j M'));
        $notices = collect();

        foreach (Appointment::whereIn('patient_id', $ids)->where('status', 'booked')->where('scheduled_at', '>=', now())->where('scheduled_at', '<=', now()->addDays(7)->endOfDay())
            ->with('doctor:id,name')->orderBy('scheduled_at')->get() as $a) {
            $notices->push(['kind' => 'appointment', 'tone' => 'info', 'tab' => 'appointments',
                'title' => 'Appointment '.$day($a->scheduled_at).$who($a->patient_id),
                'text' => trim(($a->doctor?->name ?? 'Doctor').' at '.$a->scheduled_at->format('g:i A').($a->token_no ? ", token {$a->token_no}" : ''))]);
        }

        foreach (MedicineSubscription::where('patient_account_id', $account->id)->where('status', 'active')->get() as $s) {
            $due = Carbon::parse($s->next_due_on ?? MedicineSubscription::dueOnOrAfter($s->day_of_month, today()));
            if ($due->gte(today()) && $due->lte(today()->addDays(3))) {
                $notices->push(['kind' => 'medicines', 'tone' => 'info', 'tab' => 'medicines', 'title' => 'Monthly medicines '.$day($due), 'text' => "“{$s->title}” will be ready at the hospital pharmacy."]);
            }
        }

        if (! $account->verified_at) {
            $notices->push(['kind' => 'verify', 'tone' => 'note', 'tab' => 'account', 'title' => 'Ask the front desk to verify your account',
                'text' => 'Show this app at the front desk once. After that your prescriptions, lab reports and bills appear here.']);

            return response()->json(['verified' => false, 'notices' => $notices->values(), 'due' => 0]);
        }

        $needsApproval = (bool) Setting::group('lab', Setting::LAB_DEFAULTS)['approval_required'];
        foreach (LabOrder::whereIn('patient_id', $ids)
            ->whereHas('items', fn ($q) => $q->where('reported_at', '>=', now()->subDays(14))->when($needsApproval, fn ($w) => $w->whereNotNull('approved_at')))
            ->with(['items' => fn ($q) => $q->whereNotNull('reported_at')->when($needsApproval, fn ($w) => $w->whereNotNull('approved_at'))])->latest('ordered_at')->limit(10)->get() as $o) {
            $notices->push(['kind' => 'report', 'tone' => 'ok', 'tab' => 'records', 'patient_id' => $o->patient_id,
                'title' => 'Lab report ready'.$who($o->patient_id), 'text' => $o->items->pluck('test_name')->take(4)->implode(', ').($o->items->count() > 4 ? ' and more' : '')." · {$o->order_no}"]);
        }

        foreach (Consultation::whereIn('patient_id', $ids)->whereNotNull('completed_at')->whereDate('follow_up_on', '>=', today())->whereDate('follow_up_on', '<=', today()->addDays(10))
            ->with('doctor:id,name')->orderBy('follow_up_on')->get() as $c) {
            // Only an appointment still to come counts; the visit that gave this advice does not.
            $booked = Appointment::where('patient_id', $c->patient_id)->where('doctor_id', $c->doctor_id)->where('status', 'booked')->where('scheduled_at', '>=', now())->exists();
            if (! $booked) {
                $notices->push(['kind' => 'follow_up', 'tone' => 'info', 'tab' => 'appointments', 'doctor_id' => $c->doctor_id, 'patient_id' => $c->patient_id, 'title' => 'Visit due '.$day($c->follow_up_on).$who($c->patient_id),
                    'text' => ($c->doctor?->name ?? 'The doctor').' asked to see '.($c->patient_id === $account->patient_id ? 'you' : $names[$c->patient_id]).' again. No appointment is booked yet.']);
            }
        }

        $due = $this->billsOf($ids)['due'];
        if ($due > 0) {
            $notices->push(['kind' => 'due', 'tone' => 'warn', 'tab' => 'bills', 'title' => '₹'.rtrim(rtrim(number_format($due, 2), '0'), '.').' is due', 'text' => 'This can be paid at the hospital counter. See Bills for the details.']);
        }

        return response()->json(['verified' => true, 'notices' => $notices->values(), 'due' => $due]);
    }
}
