<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\AdmissionCharge;
use App\Models\Bed;
use App\Models\Bill;
use App\Models\IpdService;
use App\Models\Payment;
use App\Models\PharmacySale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IpdController extends Controller
{
    private const LIST_WITH = ['patient:id,uhid,title,name,gender,date_of_birth,mobile', 'doctor:id,name', 'bed:id,ward,bed_no'];

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
            'is_active' => ['sometimes', 'boolean'],
        ], ['bed_no.unique' => 'This ward already has a bed with that number.']) + ['daily_charge' => 0];
    }

    // ---- IPD services (price list) ----

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
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'admitted';
        $term = trim((string) ($filters['q'] ?? ''));

        return response()->json(
            Admission::query()
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                // Patients still in a bed always show, whenever they came in.
                ->when($status !== 'admitted' && ($filters['from'] ?? null), fn ($q) => $q->whereDate('admitted_at', '>=', $filters['from']))
                ->when($status !== 'admitted' && ($filters['to'] ?? null), fn ($q) => $q->whereDate('admitted_at', '<=', $filters['to']))
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('ipd_no', 'like', "{$term}%")
                    ->orWhereHas('patient', fn ($p) => $p->where('name', 'like', "%{$term}%")
                        ->orWhere('mobile', 'like', "{$term}%")
                        ->orWhere('uhid', 'like', "{$term}%"))))
                ->with(self::LIST_WITH)
                ->latest('admitted_at')->latest('id')
                ->limit(300)
                ->get()
        );
    }

    public function admit(Request $request): JsonResponse
    {
        $data = $request->validate($this->detailRules() + [
            'patient_id' => ['required', 'exists:patients,id'],
            'admitted_at' => ['required', 'date', 'before_or_equal:now'],
        ]);

        if (Admission::where('patient_id', $data['patient_id'])->where('status', 'admitted')->exists()) {
            throw ValidationException::withMessages(['patient_id' => 'This patient is already admitted.']);
        }

        $admission = DB::transaction(function () use ($data, $request) {
            $this->claimBed($data['bed_id'] ?? null);

            return Admission::create($data + ['branch_id' => $request->user()->branch_id, 'created_by' => $request->user()->id]);
        });

        return response()->json($this->present($admission), 201);
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
        ]);

        if (isset($data['discount'])) {
            $this->refuseIfBillApproved($admission);
        }
        if (isset($data['discount']) && $data['discount'] > $admission->total) {
            throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the total charges.']);
        }

        DB::transaction(function () use ($data, $admission) {
            if (array_key_exists('bed_id', $data) && $data['bed_id'] !== $admission->bed_id && $admission->status === 'admitted') {
                $this->claimBed($data['bed_id']);
            }
            $admission->update($data);
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
        ], ['description.required_without' => 'Choose a service or describe the charge.']);
        $this->refuseIfBillApproved($admission);

        $service = IpdService::find($data['ipd_service_id'] ?? null);

        $admission->charges()->create([
            'ipd_service_id' => $service?->id,
            'description' => ($data['description'] ?? null) ?: $service->name,
            'category' => ($data['category'] ?? null) ?: $service?->category,
            'charged_on' => $data['charged_on'],
            'quantity' => $data['quantity'],
            'unit_price' => $data['unit_price'],
            'amount' => round($data['quantity'] * $data['unit_price'], 2),
            'created_by' => $request->user()->id,
        ]);
        $admission->recalculate();

        return response()->json($this->present($admission), 201);
    }

    public function removeCharge(Admission $admission, AdmissionCharge $charge): JsonResponse
    {
        abort_unless($charge->admission_id === $admission->id, 404);
        $this->refuseIfBillApproved($admission);

        if ($admission->total - $charge->amount < $admission->discount) {
            throw ValidationException::withMessages(['charge' => 'Reduce the discount first; it would be more than the remaining charges.']);
        }

        DB::transaction(function () use ($charge, $admission) {
            // A charge that stood for another bill hands the amount back to that bill.
            if ($charge->source_type === 'bill' && ($bill = Bill::find($charge->source_id))) {
                $bill->payments()->where('mode', 'ipd')->where('reference', "IPD charge {$charge->id}")->delete();
                $bill->recalculate();
            }
            if ($charge->source_type === 'pharmacy' && ($sale = PharmacySale::find($charge->source_id))) {
                $sale->update(['ipd_transfer' => max(0, $sale->ipd_transfer - $charge->amount)]);
            }

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

        $this->refuseIfBillApproved($admission);
        $entry = collect($this->outstandingFor($admission))->first(fn ($e) => $e['type'] === $data['type'] && $e['id'] === $data['id']);
        if (! $entry) {
            throw ValidationException::withMessages(['id' => 'That bill has nothing left to pay, or does not belong to this stay.']);
        }

        DB::transaction(function () use ($entry, $admission, $request) {
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

            if ($entry['type'] === 'bill') {
                $bill = Bill::findOrFail($entry['id']);
                $bill->payments()->create([
                    'type' => 'payment', 'amount' => $entry['due'], 'mode' => 'ipd',
                    'reference' => "IPD charge {$charge->id}", 'received_by' => $request->user()->id,
                ]);
                $bill->recalculate();
            } else {
                PharmacySale::whereKey($entry['id'])->increment('ipd_transfer', $entry['due']);
            }

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
        ]);

        if ($data['type'] === 'refund' && $data['amount'] > $admission->paid) {
            throw ValidationException::withMessages(['amount' => "Only ₹{$admission->paid} has been received on this case."]);
        }

        $admission->payments()->create($data + ['received_by' => $request->user()->id]);
        $admission->recalculate();

        return response()->json($this->present($admission), 201);
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

        $admission->update($data);

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

    /** Adds every service in a bill template as a charge dated today, at the current price list rates. */
    public function applyTemplate(Request $request, Admission $admission, \App\Models\IpdBillTemplate $template): JsonResponse
    {
        $this->refuseIfBillApproved($admission);
        $services = IpdService::whereIn('id', array_column($template->items, 'ipd_service_id'))->where('is_active', true)->get()->keyBy('id');
        if ($services->isEmpty()) {
            throw ValidationException::withMessages(['template' => 'None of the services in this template are on the price list any more.']);
        }

        DB::transaction(function () use ($template, $services, $admission, $request) {
            foreach ($template->items as $item) {
                if ($service = $services->get($item['ipd_service_id'])) {
                    $quantity = (float) ($item['quantity'] ?? 1);
                    $admission->charges()->create([
                        'ipd_service_id' => $service->id, 'description' => $service->name, 'category' => $service->category, 'charged_on' => today(),
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
        return $admission->fresh()->load([
            'patient:id,uhid,title,name,gender,date_of_birth,mobile,address,city,blood_group', 'doctor:id,name,department,signature_text',
            'bed:id,ward,bed_no,daily_charge', 'charges', 'payments.receiver:id,name', 'billApprover:id,name',
        ]);
    }
}
