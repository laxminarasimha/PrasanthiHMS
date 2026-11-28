<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\AdmissionBed;
use App\Models\AdmissionBill;
use App\Models\AdmissionCharge;
use App\Models\AdmissionPayment;
use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\Bill;
use App\Models\Consultation;
use App\Models\IpdBillTemplate;
use App\Models\IpdService;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\Payment;
use App\Models\PharmacySale;
use App\Models\Setting;
use App\Models\Patient;
use App\Support\AdmitAdvice;
use App\Support\BedCharges;
use App\Support\BillOnIpd;
use App\Support\EarlierDay;
use App\Support\PharmacyOnIpd;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IpdController extends Controller
{
    private const LIST_WITH = ['patient:id,uhid,title,name,gender,date_of_birth,mobile', 'doctor:id,name', 'bed:id,ward,bed_no', 'payer:id,name'];

    // ---- Beds ----

    public function beds(): JsonResponse
    {
        return response()->json(
            Bed::with(['currentAdmission:id,bed_id,ipd_no,patient_id,admitted_at', 'currentAdmission.patient:id,title,name,uhid'])
                ->orderBy('ward')->orderByRaw('LENGTH(bed_no)')->orderBy('bed_no')
                ->get()
        );
    }

    public function storeBed(Request $request): JsonResponse
    {
        $data = $this->bedData($request);

        return response()->json(Bed::create($data + ['branch_id' => $request->user()->branch_id]), 201);
    }

    public function updateBed(Request $request, Bed $bed): JsonResponse
    {
        $data = $this->bedData($request, $bed);

        if (($data['is_active'] ?? true) === false && $bed->currentAdmission()->exists()) {
            throw ValidationException::withMessages(['is_active' => 'This bed is occupied. Discharge or move the patient first.']);
        }

        $bed->update($data);

        return response()->json($bed);
    }

    /**
     * Removes a bed set up by mistake. Only a bed no patient has ever been in: a bed with past stays is named on those
     * patients' cases and bills, so it stays and can only be switched off ("In use" unticked).
     */
    public function destroyBed(Bed $bed): JsonResponse
    {
        if (Admission::where('bed_id', $bed->id)->exists() || AdmissionBed::where('bed_id', $bed->id)->exists()) {
            throw ValidationException::withMessages(['_' => 'Patients have stayed in this bed, so it is kept for their records. Untick "In use" to stop offering it.']);
        }

        $bed->delete();
        AuditLog::record('ipd.bed_deleted', null, null, "Bed deleted: {$bed->ward} {$bed->bed_no}");

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function bedData(Request $request, ?Bed $bed = null): array
    {
        return $request->validate([
            'ward' => ['required', 'string', 'max:255'],
            'bed_no' => [
                'required', 'string', 'max:20',
                Rule::unique('beds')->where('branch_id', $request->user()->branch_id)->where('ward', $request->input('ward'))->ignore($bed),
            ],
            'daily_charge' => ['nullable', 'numeric', 'min:0'],
            // For a ward bed also used for day care: what a day-care stay is charged instead. Empty: the usual rate.
            'day_care_charge' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['bed_no.unique' => 'This ward already has a bed with that number.']) + ['daily_charge' => 0];
    }

    // ---- IPD services (price list) ----

    // ---- Medicine rounds ----

    /** When the wards give once, twice, three and four times a day medicines. */
    public function roundTimes(): JsonResponse
    {
        return response()->json(Setting::group('ipd_rounds', Setting::ROUND_DEFAULTS));
    }

    public function saveRoundTimes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'od' => ['required', 'array', 'size:1'],
            'bd' => ['required', 'array', 'size:2'],
            'tds' => ['required', 'array', 'size:3'],
            'qid' => ['required', 'array', 'size:4'],
            'od.*' => ['date_format:H:i'],
            'bd.*' => ['date_format:H:i', 'distinct'],
            'tds.*' => ['date_format:H:i', 'distinct'],
            'qid.*' => ['date_format:H:i', 'distinct'],
        ], ['*.*.distinct' => 'Two rounds cannot be at the same time.', '*.*.date_format' => 'Give each round a time.']);

        Setting::updateOrCreate(['key' => 'ipd_rounds'], ['value' => $data]);

        return $this->roundTimes();
    }

    public function services(Request $request): JsonResponse
    {
        return response()->json(
            IpdService::when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))->orderBy('category')->orderBy('name')->get()
        );
    }

    public function storeService(Request $request): JsonResponse
    {
        return response()->json(IpdService::create($this->serviceData($request)), 201);
    }

    public function updateService(Request $request, IpdService $service): JsonResponse
    {
        $service->update($this->serviceData($request));

        return response()->json($service);
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    // ---- Admissions ----

    public function admissions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['admitted', 'all', ...Admission::CLOSED_STATUSES])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Cases that ended on or after this day, whenever the patient came in.
            'left_from' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'admitted';
        // So the amounts on the list include today's bed charges without each case having to be opened.
        BedCharges::sweep();
        $term = trim((string) ($filters['q'] ?? ''));

        return response()->json(
            Admission::query()
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                // Patients still in a bed always show, whenever they came in.
                ->when($status !== 'admitted' && ($filters['from'] ?? null), fn ($q) => $q->whereDate('admitted_at', '>=', $filters['from']))
                ->when($status !== 'admitted' && ($filters['to'] ?? null), fn ($q) => $q->whereDate('admitted_at', '<=', $filters['to']))
                ->when($filters['left_from'] ?? null, fn ($q) => $q->where('status', '!=', 'admitted')->whereDate('discharged_at', '>=', $filters['left_from']))
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('ipd_no', 'like', "{$term}%")
                    ->orWhereHas('patient', fn ($p) => $p->where('name', 'like', "%{$term}%")
                        ->orWhere('mobile', 'like', "{$term}%")
                        ->orWhere(fn ($id) => Patient::idStarts($id, $term)))))
                ->with(self::LIST_WITH)
                // Patients still in a bed first; those who have left below them. Newest first within each.
                ->orderByRaw("CASE WHEN status = 'admitted' THEN 0 ELSE 1 END")
                ->latest('admitted_at')->latest('id')
                ->limit(300)
                ->get()
        );
    }

    /** Whether each day's bed charge is added to the bill by the app, and since when. */
    public function bedCharging(): JsonResponse
    {
        return response()->json(BedCharges::settings());
    }

    public function saveBedCharging(Request $request): JsonResponse
    {
        $data = $request->validate(['auto' => ['required', 'boolean']]);
        $now = BedCharges::settings();
        if ($data['auto'] !== $now['auto']) {
            // Days of stay that had already begun when it is switched on were left to the staff, and stay that way.
            // Admission times are entered to the minute, so the switch-on is counted from the start of its minute.
            Setting::updateOrCreate(['key' => BedCharges::SETTING], ['value' => ['auto' => $data['auto'], 'from' => $data['auto'] ? now()->startOfMinute()->format('Y-m-d H:i:s') : null]]);
            AuditLog::record('ipd.bed_charging', null, null, $data['auto'] ? 'Automatic bed charges switched on' : 'Automatic bed charges switched off');
            BedCharges::sweep(true);
        }

        return response()->json(BedCharges::settings());
    }

    public function admit(Request $request): JsonResponse
    {
        $data = $request->validate($this->detailRules() + [
            'patient_id' => ['required', 'exists:patients,id'],
            'admitted_at' => ['required', 'date', 'before_or_equal:now'],
            'is_day_care' => ['sometimes', 'boolean'],
            'consultation_id' => ['nullable', 'integer', 'exists:consultations,id'],
        ]);

        if (Admission::where('patient_id', $data['patient_id'])->where('status', 'admitted')->exists()) {
            throw ValidationException::withMessages(['patient_id' => 'This patient is already admitted.']);
        }
        // The visit this admission was advised at. Admitted through the plain form, the waiting advice is found by itself.
        if (! empty($data['consultation_id'])) {
            if ((int) Consultation::whereKey($data['consultation_id'])->value('patient_id') !== (int) $data['patient_id']) {
                throw ValidationException::withMessages(['patient_id' => 'That advice was for a different patient.']);
            }
        } else {
            $data['consultation_id'] = AdmitAdvice::for((int) $data['patient_id'])?->id;
        }

        $admission = DB::transaction(function () use ($data, $request) {
            $this->claimBed($data['bed_id'] ?? null);

            $admission = Admission::create($data + ['branch_id' => $request->user()->branch_id, 'created_by' => $request->user()->id]);
            BedCharges::moveTo($admission, $admission->bed_id, $admission->admitted_at, $request->user()->id);

            return $admission;
        });

        return response()->json($this->present($admission), 201);
    }

    /** Admission advised by a doctor on the visit pad and not acted on yet. A doctor is shown their own. */
    public function advised(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(AdmitAdvice::waiting($user->role === Role::Doctor ? $user->id : null)->map(fn (Consultation $c) => [
            'id' => $c->id,
            'appointment_id' => $c->appointment_id,
            'advised_at' => $c->admit_advised_at->format('Y-m-d H:i'),
            'reason' => $c->admit_reason,
            'patient' => $c->patient,
            'doctor' => $c->doctor,
        ]));
    }

    /** The patient is not coming in after all: the advice stops being chased. What the doctor wrote stays on the visit. */
    public function adviceNotComing(Request $request, Consultation $consultation): JsonResponse
    {
        abort_unless($consultation->admit_advised_at, 422, 'Admission was not advised at this visit.');
        if (! $consultation->admit_closed_at) {
            $consultation->forceFill(['admit_closed_at' => now()])->save();
            AuditLog::record('ipd.advice_closed', $consultation->patient_id, $consultation, 'Admission was advised; marked as not coming');
        }

        return response()->json(null, 204);
    }

    public function show(Admission $admission): JsonResponse
    {
        return response()->json($this->present($admission));
    }

    /** Edits the case details, moves the patient to another bed, or sets the discount. */
    public function update(Request $request, Admission $admission): JsonResponse
    {
        $data = $request->validate(array_map(fn ($rules) => array_merge(['sometimes'], $rules), $this->detailRules()) + [
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'discharge_summary' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'aadhaar_no' => ['sometimes', 'nullable', 'regex:/^[0-9]{12}$/'],
            'gst_no' => ['sometimes', 'nullable', 'string', 'max:20'],
            'auto_bed_charge' => ['sometimes', 'boolean'],
            'is_day_care' => ['sometimes', 'boolean'],
        ], ['aadhaar_no.regex' => 'An Aadhaar number has 12 digits.']);

        if (isset($data['discount'])) {
            $this->refuseIfBillApproved($admission);
        }
        if (isset($data['discount']) && $data['discount'] > $admission->total) {
            throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the total charges.']);
        }

        DB::transaction(function () use ($data, $admission, $request) {
            $moving = array_key_exists('bed_id', $data) && $data['bed_id'] !== $admission->bed_id && $admission->status === 'admitted';
            if ($moving) {
                // What is owed for the bed so far is settled before the bed changes.
                BedCharges::bringUpToDate($admission);
                $this->claimBed($data['bed_id']);
            }
            if ((array_key_exists('auto_bed_charge', $data) && (bool) $data['auto_bed_charge'] !== $admission->auto_bed_charge)
                || (array_key_exists('is_day_care', $data) && (bool) $data['is_day_care'] !== $admission->is_day_care)) {
                // Days up to now are dealt with under the old choice; the new one applies from the next day of stay.
                BedCharges::bringUpToDate($admission);
            }
            $admission->update($data);
            if ($moving) {
                BedCharges::moveTo($admission, $admission->bed_id, now(), $request->user()->id);
            }
        });

        return response()->json($this->present($admission));
    }

    public function addCharge(Request $request, Admission $admission): JsonResponse
    {
        $data = $request->validate([
            'ipd_service_id' => ['nullable', 'exists:ipd_services,id'],
            'description' => ['required_without:ipd_service_id', 'nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'charged_on' => ['required', 'date', 'before_or_equal:today'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ], ['description.required_without' => 'Choose a service or describe the charge.']);
        $this->refuseIfBillApproved($admission);
        $discount = round((float) ($data['discount'] ?? 0), 2);
        if ($discount > $data['quantity'] * $data['unit_price']) {
            throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the charge.']);
        }

        $service = IpdService::find($data['ipd_service_id'] ?? null);

        $admission->charges()->create([
            'ipd_service_id' => $service?->id,
            'description' => ($data['description'] ?? null) ?: $service->name,
            'category' => ($data['category'] ?? null) ?: $service?->category,
            'charged_on' => $data['charged_on'],
            'quantity' => $data['quantity'],
            'unit_price' => $data['unit_price'],
            'discount' => $discount,
            'amount' => round($data['quantity'] * $data['unit_price'] - $discount, 2),
            'remarks' => $data['remarks'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        $admission->recalculate();

        return response()->json($this->present($admission), 201);
    }

    /** Corrects a charge already on the bill: its date, quantity, rate, discount or remarks. */
    public function updateCharge(Request $request, Admission $admission, AdmissionCharge $charge): JsonResponse
    {
        abort_unless($charge->admission_id === $admission->id, 404);
        $this->refuseIfBillApproved($admission);
        $this->refuseIfBilled($charge);
        // A bed charge the app added can be corrected like any other line; a line that stands for another bill cannot.
        if ($charge->source_type && $charge->source_type !== 'bed') {
            throw ValidationException::withMessages(['charge' => 'This line stands for another bill. Remove it here and change that bill instead.']);
        }

        $data = $request->validate([
            'charged_on' => ['required', 'date', 'before_or_equal:today'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);
        $discount = round((float) ($data['discount'] ?? 0), 2);
        $amount = round($data['quantity'] * $data['unit_price'] - $discount, 2);
        if ($amount < 0) {
            throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the charge.']);
        }
        if ($admission->total - $charge->amount + $amount < $admission->discount) {
            throw ValidationException::withMessages(['charge' => 'Reduce the bill discount first; it would be more than the charges.']);
        }

        $charge->update(['charged_on' => $data['charged_on'], 'quantity' => $data['quantity'], 'unit_price' => $data['unit_price'], 'discount' => $discount, 'amount' => $amount, 'remarks' => $data['remarks'] ?? null]);
        $admission->recalculate();

        return response()->json($this->present($admission));
    }

    public function removeCharge(Request $request, Admission $admission, AdmissionCharge $charge): JsonResponse
    {
        abort_unless($charge->admission_id === $admission->id, 404);
        $this->refuseIfBillApproved($admission);
        $this->refuseIfBilled($charge);
        if ($charge->source_type === 'pharmacy_return') {
            throw ValidationException::withMessages(['charge' => 'This line is for medicines taken back at the pharmacy. It goes when the pharmacy bill itself is removed from this IPD bill.']);
        }

        // Medicines taken back since the pharmacy bill was moved here came off as lines of their own; they leave with it.
        $credits = $charge->source_type === 'pharmacy'
            ? AdmissionCharge::where('admission_id', $admission->id)->where('source_type', 'pharmacy_return')->where('source_id', $charge->source_id)->get()
            : collect();
        $credits->each(fn (AdmissionCharge $credit) => $this->refuseIfBilled($credit));
        $net = round($charge->amount + $credits->sum('amount'), 2);

        if ($admission->total - $net < $admission->discount) {
            throw ValidationException::withMessages(['charge' => 'Reduce the discount first; it would be more than the remaining charges.']);
        }

        DB::transaction(function () use ($charge, $credits, $net, $admission, $request) {
            // A charge that stood for another bill hands the amount back to that bill.
            if ($charge->source_type === 'bill' && ($bill = Bill::find($charge->source_id))) {
                $bill->payments()->where('mode', 'ipd')->where('reference', "IPD charge {$charge->id}")->delete();
                $bill->recalculate();
            }
            if ($charge->source_type === 'pharmacy' && ($sale = PharmacySale::find($charge->source_id))) {
                $sale->update(['ipd_transfer' => max(0, round($sale->ipd_transfer - $net, 2))]);
            }
            foreach ($credits as $credit) {
                $credit->update(['deleted_by' => $request->user()->id]);
                $credit->delete();
            }

            // Kept on record as a deleted line, with who removed it.
            $charge->update(['deleted_by' => $request->user()->id]);
            $charge->delete();
            $admission->recalculate();
        });

        return response()->json($this->present($admission));
    }

    /** The patient's unpaid front desk, lab and pharmacy bills raised since they were admitted. */
    public function outstanding(Admission $admission): JsonResponse
    {
        return response()->json($this->outstandingFor($admission));
    }

    /**
     * Moves the unpaid amount of another bill onto this IPD bill. The other bill is marked
     * as settled through IPD so the money is not asked for twice.
     */
    public function transfer(Request $request, Admission $admission): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['bill', 'pharmacy'])],
            'id' => ['required', 'integer'],
        ]);

        // Held until this is saved, so the same bill sent twice (a double click) is moved once: the second finds nothing left to move.
        $admission = Admission::whereKey($admission->id)->lockForUpdate()->firstOrFail();
        $this->refuseIfBillApproved($admission);
        $entry = collect($this->outstandingFor($admission))->first(fn ($e) => $e['type'] === $data['type'] && $e['id'] === (int) $data['id']);
        if (! $entry) {
            throw ValidationException::withMessages(['id' => 'That bill has nothing left to pay, or does not belong to this stay.']);
        }

        DB::transaction(function () use ($entry, $admission, $request) {
            if ($entry['type'] === 'bill') {
                BillOnIpd::put($admission, Bill::findOrFail($entry['id']), $request->user()->id, today()->toDateString());

                return;
            }
            $charge = $admission->charges()->create([
                'description' => $entry['label'],
                'category' => $entry['category'],
                'charged_on' => today(),
                'quantity' => 1,
                'unit_price' => $entry['due'],
                'amount' => $entry['due'],
                'created_by' => $request->user()->id,
                'source_type' => $entry['type'],
                'source_id' => $entry['id'],
            ]);

            PharmacySale::whereKey($entry['id'])->increment('ipd_transfer', $entry['due']);

            $admission->recalculate();
        });

        return response()->json($this->present($admission), 201);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function outstandingFor(Admission $admission): array
    {
        $since = $admission->admitted_at->copy()->startOfDay();
        $until = ($admission->discharged_at ?? now())->copy()->endOfDay();

        $bills = Bill::where('patient_id', $admission->patient_id)
            ->whereBetween('created_at', [$since, $until])
            ->whereColumn('paid', '<', 'total')
            ->with('labOrder:id,bill_id,order_no')
            ->get()
            ->map(fn (Bill $bill) => [
                'type' => 'bill',
                'id' => $bill->id,
                'label' => $bill->labOrder ? "Lab order {$bill->labOrder->order_no} (bill {$bill->bill_no})" : "Front desk bill {$bill->bill_no}",
                'category' => $bill->labOrder ? 'Lab' : 'OPD',
                'date' => $bill->created_at->format('Y-m-d'),
                'due' => $bill->due,
            ]);

        $sales = PharmacySale::where('patient_id', $admission->patient_id)
            ->whereBetween('sold_at', [$since, $until])
            ->whereNull('voided_at')
            ->whereRaw('paid + ipd_transfer + returned < total')
            ->get()
            ->map(fn (PharmacySale $sale) => [
                'type' => 'pharmacy',
                'id' => $sale->id,
                'label' => "Pharmacy bill {$sale->bill_no}",
                'category' => 'Pharmacy',
                'date' => $sale->sold_at->format('Y-m-d'),
                'due' => $sale->due,
            ]);

        return $bills->concat($sales)->sortBy('date')->values()->all();
    }

    /** Advances and payments both count towards the bill; refunds return money already taken. */
    public function addPayment(Request $request, Admission $admission): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['advance', 'payment', 'refund'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(Payment::MODES)],
            'reference' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:255'],
            // The day the money actually changed hands, when it is entered later.
            'paid_on' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:'.$admission->admitted_at->toDateString()],
        ], ['paid_on.after_or_equal' => 'The payment date cannot be before the admission.']);

        // Held until this is saved, so two entries arriving together are taken one after the other.
        $admission = Admission::whereKey($admission->id)->lockForUpdate()->firstOrFail();
        if ($data['type'] === 'refund' && $data['amount'] > $admission->paid) {
            throw ValidationException::withMessages(['amount' => "Only ₹{$admission->paid} has been received on this case."]);
        }
        // An advance has no upper limit to catch a repeat, so the very same entry by the same person seconds later is taken as a double click.
        $last = AdmissionPayment::where('admission_id', $admission->id)->latest('id')->first();
        if ($last && $last->type === $data['type'] && (float) $last->amount === (float) $data['amount'] && $last->mode === $data['mode']
            && $last->received_by === $request->user()->id && $last->updated_at?->gt(now()->subSeconds(5))) {
            throw ValidationException::withMessages(['amount' => 'This entry was recorded a moment ago. Look at the list below before entering it again.']);
        }

        // Reports count money on the day it was received, so a back-dated entry carries that day, from someone allowed
        // to enter one. The date is set before saving, so a day whose accounts are closed refuses it like any other entry.
        $at = EarlierDay::at($data['paid_on'] ?? null);
        unset($data['paid_on']);
        EarlierDay::date($admission->payments()->make($data + ['received_by' => $request->user()->id]), $at)->save();
        $admission->recalculate();
        EarlierDay::log($at, $admission->patient_id, $admission, "{$admission->ipd_no}: ₹".(float) $data['amount'].' '.($data['type'] === 'refund' ? 'refunded' : 'received'));

        return response()->json($this->present($admission), 201);
    }

    /** Removes a payment entered by mistake. Money actually handed back is a refund, not a deletion. */
    /**
     * Corrects how an advance, payment or refund was taken when it was entered under the wrong mode:
     * cash put down as UPI, and the like. The amount and the day stay as they are, and the change is
     * kept in the record with who made it and why.
     */
    public function updatePayment(Request $request, Admission $admission, AdmissionPayment $payment): JsonResponse
    {
        abort_unless($payment->admission_id === $admission->id, 404);

        $data = $request->validate([
            'mode' => ['required', Rule::in(Payment::MODES)],
            'reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], ['reason.required' => 'Say why the payment mode is being changed.']);

        // An entry that is not money taken at the counter, such as one moved from another bill, keeps its mode.
        if (! in_array($payment->mode, Payment::MODES, true)) {
            throw ValidationException::withMessages(['mode' => 'This entry is not money taken at the counter, so its mode cannot be changed.']);
        }
        $reference = $data['reference'] ?? null;
        if ($payment->mode === $data['mode'] && $payment->reference === $reference) {
            throw ValidationException::withMessages(['mode' => 'That is already how this payment is recorded.']);
        }

        $was = $payment->mode;
        $payment->update(['mode' => $data['mode'], 'reference' => $reference]);
        AuditLog::record('ipd.payment_mode', $admission->patient_id, $admission, 'IPD '.$admission->ipd_no.': '.$payment->type.' of ₹'.(float) $payment->amount
            .' on '.$payment->created_at->format('j M Y').' changed from '.$was.' to '.$data['mode'].($reference ? " ({$reference})" : '').'. Reason: '.$data['reason']);

        return response()->json($this->present($admission));
    }

    public function removePayment(Request $request, Admission $admission, AdmissionPayment $payment): JsonResponse
    {
        abort_unless($payment->admission_id === $admission->id, 404);
        abort_unless($request->user()->allows('canApproveIpd'), 403, 'You are not allowed to delete IPD payments.');

        $after = $admission->paid - ($payment->type === 'refund' ? -1 : 1) * $payment->amount;
        if ($after < 0) {
            throw ValidationException::withMessages(['payment' => 'Delete the refund first; more would have been refunded than was received.']);
        }
        $payment->delete();
        $admission->recalculate();

        return response()->json($this->present($admission));
    }

    /**
     * Lab results during the stay, laid out as one row per test parameter and one column per day,
     * so a value can be followed across the admission.
     */
    public function investigations(Admission $admission): JsonResponse
    {
        $from = $admission->admitted_at->copy()->startOfDay();
        $to = ($admission->discharged_at ?? now())->copy()->endOfDay();
        $items = LabOrderItem::whereIn('lab_order_id', LabOrder::where('patient_id', $admission->patient_id)->whereBetween('ordered_at', [$from, $to])->select('id'))
            ->whereNotNull('reported_at')->with('results')->orderBy('reported_at')->get();

        $dates = [];
        $rows = [];
        foreach ($items as $item) {
            $day = $item->reported_at->format('Y-m-d');
            $dates[$day] = true;
            foreach ($item->results as $result) {
                if ($result->is_heading || blank($result->value)) {
                    continue;
                }
                $key = $item->test_name.'|'.$result->parameter;
                $rows[$key] ??= ['test' => $item->test_name, 'parameter' => $result->parameter, 'unit' => $result->unit, 'reference_range' => $result->reference_range, 'values' => []];
                // The latest result of the day stands for that day.
                $rows[$key]['values'][$day] = ['value' => $result->value, 'flag' => $result->flag];
            }
        }
        ksort($dates);

        return response()->json(['dates' => array_keys($dates), 'rows' => array_values($rows)]);
    }

    /** Closes the case and frees the bed. Money can still be collected afterwards. */
    public function discharge(Request $request, Admission $admission): JsonResponse
    {
        if ($admission->status !== 'admitted') {
            throw ValidationException::withMessages(['status' => 'This case is already closed.']);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(Admission::CLOSED_STATUSES)],
            'discharged_at' => ['required', 'date', 'before_or_equal:now', 'after_or_equal:'.$admission->admitted_at->format('Y-m-d H:i')],
            'discharge_summary' => ['nullable', 'string', 'max:20000'],
        ], ['discharged_at.after_or_equal' => 'The discharge time cannot be before the admission time.']);

        DB::transaction(function () use ($admission, $data) {
            $admission->update($data);
            // The last days of the stay go on the bill, and the bed is given up at the time of leaving.
            BedCharges::bringUpToDate($admission);
            BedCharges::leave($admission, $admission->discharged_at);
        });

        return response()->json($this->present($admission));
    }

    /**
     * Puts right a discharge time entered wrongly, on a case already closed, with the reason. The bed and the daily
     * bed charges follow the new time. Not once the bill is approved: withdraw the approval first.
     */
    public function correctDischarge(Request $request, Admission $admission): JsonResponse
    {
        if ($admission->status === 'admitted') {
            throw ValidationException::withMessages(['discharged_at' => 'This patient is still admitted. Use Discharge to close the case.']);
        }
        $this->refuseIfBillApproved($admission);
        $data = $request->validate([
            'discharged_at' => ['required', 'date', 'before_or_equal:now', 'after_or_equal:'.$admission->admitted_at->format('Y-m-d H:i')],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'discharged_at.after_or_equal' => 'The discharge time cannot be before the admission time ('.$admission->admitted_at->format('j M Y, g:i A').').',
            'discharged_at.before_or_equal' => 'The discharge time cannot be in the future.', 'reason.required' => 'Say why the time is being corrected.',
        ]);
        $was = $admission->discharged_at->copy();
        $now = Carbon::parse($data['discharged_at'])->seconds(0);
        if ($now->equalTo($was)) {
            throw ValidationException::withMessages(['discharged_at' => 'That is the time already recorded.']);
        }
        DB::transaction(function () use ($admission, $was, $now, $request) {
            $admission->update(['discharged_at' => $now]);
            BedCharges::endMoved($admission->fresh(), $was, $now, $request->user()->id);
        });
        AuditLog::record('ipd.discharge_time', $admission->patient_id, $admission,
            'IPD '.$admission->ipd_no.': discharge time changed from '.$was->format('j M Y, g:i A').' to '.$now->format('j M Y, g:i A').'. Reason: '.$data['reason']);

        return response()->json($this->present($admission));
    }

    /**
     * Opens a closed case again: one closed by mistake, or under the wrong outcome. Only those who approve IPD bills may,
     * and the reason is kept in the record. The patient goes back to their bed if it is still free.
     */
    public function reopen(Request $request, Admission $admission): JsonResponse
    {
        abort_unless($request->user()->allows('canApproveIpd'), 403, 'Only those who approve IPD bills can reopen a case.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], ['reason.required' => 'Say why the case is being reopened.']);

        if ($admission->status === 'admitted') {
            throw ValidationException::withMessages(['reason' => 'This case is already open.']);
        }
        if (Admission::where('patient_id', $admission->patient_id)->where('status', 'admitted')->exists()) {
            throw ValidationException::withMessages(['reason' => 'This patient has another case open. Close that one first.']);
        }

        $was = $admission->status;
        DB::transaction(function () use ($admission) {
            // The bed may have been given to someone else since; the case then opens without a bed, to be given one.
            $bed = $admission->bed_id ? Bed::whereKey($admission->bed_id)->lockForUpdate()->first() : null;
            $taken = $bed && Admission::where('bed_id', $bed->id)->where('status', 'admitted')->exists();
            $admission->update(['status' => 'admitted', 'discharged_at' => null] + ($taken ? ['bed_id' => null] : []));
            BedCharges::moveTo($admission, $admission->bed_id, now(), request()->user()->id);
        });
        AuditLog::record('ipd.reopened', $admission->patient_id, $admission, 'IPD '.$admission->ipd_no.': reopened (was closed as '.$was.'). Reason: '.$data['reason']);

        return response()->json($this->present($admission));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function detailRules(): array
    {
        return [
            'doctor_id' => ['required', Rule::exists('users', 'id')->where('role', Role::Doctor->value)],
            'bed_id' => ['nullable', Rule::exists('beds', 'id')->where('is_active', true)],
            'payer_id' => ['nullable', Rule::exists('admin_entries', 'id')->where('kind', 'payer')],
            'reason' => ['nullable', 'string', 'max:500'],
            'attendant_name' => ['nullable', 'string', 'max:255'],
            'attendant_phone' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** An approved bill is final: its charges and discount cannot change until the approval is withdrawn. */
    private function refuseIfBillApproved(Admission $admission): void
    {
        if ($admission->bill_approved_at) {
            throw ValidationException::withMessages(['bill' => 'This bill has been approved. Withdraw the approval to change it.']);
        }
    }

    /** A charge printed on a numbered bill stays as printed until that bill is cancelled. */
    private function refuseIfBilled(AdmissionCharge $charge): void
    {
        if ($charge->admission_bill_id && ($bill = $charge->bill)) {
            throw ValidationException::withMessages(['charge' => "This charge is on bill {$bill->bill_no}. Cancel that bill to change it."]);
        }
    }

    /**
     * Makes a numbered bill from the charges not yet on one, e.g. an interim bill every few days.
     * With up_to, only charges dated on or before that day are taken.
     */
    public function makeBill(Request $request, Admission $admission): JsonResponse
    {
        $data = $request->validate([
            'up_to' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $admission, $request) {
            $charges = AdmissionCharge::where('admission_id', $admission->id)->whereNull('admission_bill_id')
                ->when($data['up_to'] ?? null, fn ($q, $day) => $q->whereDate('charged_on', '<=', $day))
                ->lockForUpdate()->pluck('id');
            if ($charges->isEmpty()) {
                throw ValidationException::withMessages(['up_to' => isset($data['up_to']) ? 'There are no unbilled charges up to that date.' : 'Every charge is already on a bill.']);
            }

            $bill = $admission->bills()->create(['billed_on' => today(), 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->id]);
            AdmissionCharge::whereIn('id', $charges)->update(['admission_bill_id' => $bill->id]);
        });

        return response()->json($this->present($admission), 201);
    }

    /** Cancels a numbered bill: its charges become unbilled again and its number is not reused. */
    public function cancelBill(Request $request, Admission $admission, AdmissionBill $bill): JsonResponse
    {
        abort_unless($bill->admission_id === $admission->id, 404);
        $this->refuseIfBillApproved($admission);

        DB::transaction(function () use ($bill, $request) {
            AdmissionCharge::withTrashed()->where('admission_bill_id', $bill->id)->update(['admission_bill_id' => null]);
            $bill->update(['deleted_by' => $request->user()->id]);
            $bill->delete();
        });

        return response()->json($this->present($admission));
    }

    /** Approves the bill (locking it) or withdraws the approval. */
    public function approveBill(Request $request, Admission $admission): JsonResponse
    {
        abort_unless($request->user()->allows('canApproveIpd'), 403, 'You are not allowed to approve IPD bills.');
        $data = $request->validate(['approved' => ['required', 'boolean']]);
        $admission->update($data['approved']
            ? ['bill_approved_at' => now(), 'bill_approved_by' => $request->user()->id]
            : ['bill_approved_at' => null, 'bill_approved_by' => null]);

        return response()->json($this->present($admission));
    }

    /** Adds every service in a bill template as a charge, dated today unless an earlier day is given, at the current price list rates. */
    public function applyTemplate(Request $request, Admission $admission, IpdBillTemplate $template): JsonResponse
    {
        $day = $request->validate(['charged_on' => ['nullable', 'date', 'before_or_equal:today']])['charged_on'] ?? today()->toDateString();
        $this->refuseIfBillApproved($admission);
        $services = IpdService::whereIn('id', array_column($template->items, 'ipd_service_id'))->where('is_active', true)->get()->keyBy('id');
        if ($services->isEmpty()) {
            throw ValidationException::withMessages(['template' => 'None of the services in this template are on the price list any more.']);
        }

        DB::transaction(function () use ($template, $services, $admission, $request, $day) {
            foreach ($template->items as $item) {
                if ($service = $services->get($item['ipd_service_id'])) {
                    $quantity = (float) ($item['quantity'] ?? 1);
                    $admission->charges()->create([
                        'ipd_service_id' => $service->id, 'description' => $service->name, 'category' => $service->category, 'charged_on' => $day,
                        'quantity' => $quantity, 'unit_price' => $service->price, 'amount' => round($quantity * $service->price, 2), 'created_by' => $request->user()->id,
                    ]);
                }
            }
            $admission->recalculate();
        });

        return response()->json($this->present($admission), 201);
    }

    /** Fails if someone else is already in the bed; the row lock stops two admissions grabbing it at once. */
    private function claimBed(?int $bedId): void
    {
        if (! $bedId) {
            return;
        }

        Bed::whereKey($bedId)->lockForUpdate()->first();

        if (Admission::where('bed_id', $bedId)->where('status', 'admitted')->exists()) {
            throw ValidationException::withMessages(['bed_id' => 'That bed is occupied. Choose another bed.']);
        }
    }

    private function present(Admission $admission): Admission
    {
        // Any day of stay that has begun since the case was last looked at gets its bed charge first.
        BedCharges::bringUpToDate($admission);
        $admission = $admission->fresh()->load([
            'bedStays.mover:id,name',
            'patient:id,uhid,title,name,gender,date_of_birth,mobile,address,city,blood_group', 'doctor:id,name,department,signature_text',
            'bed:id,ward,bed_no,daily_charge,day_care_charge', 'charges.creator:id,name', 'payments.receiver:id,name', 'billApprover:id,name', 'payer:id,name,details', 'bills.creator:id,name', 'bills.charges:id,admission_bill_id,amount',
        ]);
        $admission->bills->each->makeHidden('charges');
        if (request()->user()?->allows('canWorkIpd')) {
            $admission->makeVisible('aadhaar_no');
        }
        $admission->setAttribute('bed_charges_auto', BedCharges::settings()['auto']);
        // Under each pharmacy bill on the IPD bill, the medicines that were on it.
        $medicines = PharmacyOnIpd::medicines($admission->charges);
        // And under each lab line, the tests that were done.
        $tests = BillOnIpd::tests($admission->charges);
        foreach ($admission->charges as $charge) {
            if ($medicines->has($charge->id)) {
                $charge->setAttribute('medicines', $medicines[$charge->id]);
            }
            if ($tests->has($charge->id)) {
                $charge->setAttribute('tests', $tests[$charge->id]);
            }
        }
        // The OPD visit at which a doctor advised this admission, when there was one.
        $visit = $admission->consultation_id ? Consultation::with('doctor:id,name')->find($admission->consultation_id) : null;
        $admission->setAttribute('advised', $visit ? ['appointment_id' => $visit->appointment_id, 'on' => ($visit->admit_advised_at ?? $visit->created_at)?->format('Y-m-d'), 'doctor' => $visit->doctor?->name] : null);
        // Lines taken off the bill, kept so there is a record of what was removed and by whom.
        $admission->setRelation('deleted_charges', AdmissionCharge::onlyTrashed()->where('admission_id', $admission->id)->with('deleter:id,name')->orderByDesc('deleted_at')->get());

        return $admission;
    }
}
