<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\AdmissionCharge;
use App\Models\Bed;
use App\Models\IpdService;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IpdController extends Controller
{
    private const LIST_WITH = ['patient:id,uhid,name,gender,date_of_birth,mobile', 'doctor:id,name', 'bed:id,ward,bed_no'];

    // ---- Beds ----

    public function beds(): JsonResponse
    {
        return response()->json(
            Bed::with(['currentAdmission:id,bed_id,ipd_no,patient_id,admitted_at', 'currentAdmission.patient:id,name,uhid'])
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

        if ($admission->total - $charge->amount < $admission->discount) {
            throw ValidationException::withMessages(['charge' => 'Reduce the discount first; it would be more than the remaining charges.']);
        }

        $charge->delete();
        $admission->recalculate();

        return response()->json($this->present($admission));
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
            'patient:id,uhid,name,gender,date_of_birth,mobile,address,city,blood_group', 'doctor:id,name,department,signature_text',
            'bed:id,ward,bed_no,daily_charge', 'charges', 'payments.receiver:id,name',
        ]);
    }
}
