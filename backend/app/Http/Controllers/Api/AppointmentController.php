<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Bill;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    private const WITH = ['patient:id,uhid,name,gender,date_of_birth,mobile', 'doctor:id,name', 'service:id,name', 'bills:id,appointment_id,bill_no,total,paid', 'bills.labOrder:id,bill_id,order_no'];

    /** One day's appointments, oldest first, with counts per status for the filter tabs. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(Appointment::STATUSES)],
            'doctor_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $day = Appointment::query()
            ->whereDate('scheduled_at', $filters['date'] ?? today())
            ->when($filters['doctor_id'] ?? null, fn ($q, $id) => $q->where('doctor_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereHas('patient', fn ($p) => $p
                ->where('name', 'like', "%{$term}%")
                ->orWhere('mobile', 'like', "{$term}%")
                ->orWhere('uhid', 'like', "{$term}%")));

        return response()->json([
            'counts' => (clone $day)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'data' => $day->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->with(self::WITH)->orderBy('scheduled_at')->orderBy('id')->get(),
        ]);
    }

    /** Books the visit and opens a bill for the chosen service in one step. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'patient_id' => ['required', 'exists:patients,id'],
        ]);

        $appointment = DB::transaction(function () use ($data, $request) {
            $appointment = Appointment::create($data + [
                'branch_id' => $request->user()->branch_id,
                'created_by' => $request->user()->id,
                'arrived_at' => ($data['status'] ?? 'booked') === 'arrived' ? now() : null,
            ]);

            if ($service = Service::find($data['service_id'] ?? null)) {
                Bill::create([
                    'branch_id' => $appointment->branch_id,
                    'patient_id' => $appointment->patient_id,
                    'appointment_id' => $appointment->id,
                    'created_by' => $request->user()->id,
                ])->addService($service);
            }

            return $appointment;
        });

        return response()->json($appointment->fresh()->load(self::WITH), 201);
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(array_map(fn ($rule) => array_merge(['sometimes'], $rule), $this->rules()));

        // Record when the patient first reached the hospital, for wait-time reporting later.
        if (($data['status'] ?? null) === 'arrived' && ! $appointment->arrived_at) {
            $data['arrived_at'] = now();
        }

        $appointment->update($data);

        return response()->json($appointment->load(self::WITH));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'doctor_id' => ['required', Rule::exists('users', 'id')->where('role', Role::Doctor->value)->where('is_active', true)],
            'service_id' => ['nullable', Rule::exists('services', 'id')->where('type', 'appointment')],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'status' => ['nullable', Rule::in(Appointment::STATUSES)],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
