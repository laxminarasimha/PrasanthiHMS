<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PrescriptionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ConsultationController extends Controller
{
    private const PATIENT = 'patient:id,uhid,name,gender,date_of_birth,mobile,blood_group,allergies';

    public const GENERAL_EXAM_SIGNS = ['pallor', 'icterus', 'cyanosis', 'clubbing', 'lymphadenopathy', 'edema'];

    /**
     * One day's patients for the doctor's screen. Doctors see their own list;
     * other roles see everyone's and may narrow it with doctor_id.
     */
    public function queue(Request $request): JsonResponse
    {
        $filters = $request->validate(['date' => ['nullable', 'date'], 'doctor_id' => ['nullable', 'integer']]);
        $user = $request->user();
        $doctorId = $user->role === Role::Doctor ? $user->id : ($filters['doctor_id'] ?? null);

        $appointments = Appointment::query()
            ->whereDate('scheduled_at', $filters['date'] ?? today())
            ->where('status', '!=', 'cancelled')
            ->when($doctorId, fn ($q, $id) => $q->where('doctor_id', $id))
            ->with([self::PATIENT, 'doctor:id,name', 'service:id,name', 'consultation:id,appointment_id,completed_at,weight_kg,bp_systolic,pulse,temperature_f,spo2'])
            ->orderBy('scheduled_at')->orderBy('id')
            ->get();

        // Earlier completed visits per patient, for the "visits" and "last seen" columns.
        $past = Consultation::query()
            ->whereIn('patient_id', $appointments->pluck('patient_id')->unique())
            ->whereNotNull('completed_at')
            ->selectRaw('patient_id, count(*) as visits, max(completed_at) as last_visit')
            ->groupBy('patient_id')
            ->get()->keyBy('patient_id');

        return response()->json($appointments->map(fn (Appointment $a) => $a->toArray() + [
            'visits' => (int) ($past[$a->patient_id]->visits ?? 0),
            'last_visit' => isset($past[$a->patient_id]) ? substr((string) $past[$a->patient_id]->last_visit, 0, 10) : null,
            // True once the desk or a nurse has recorded any vital sign for this visit.
            'vitals_ready' => (bool) collect($a->consultation?->only(['weight_kg', 'bp_systolic', 'pulse', 'temperature_f', 'spo2']))->filter()->count(),
        ]));
    }

    /** The visit pad for an appointment: saved notes if any, otherwise a blank pad. */
    public function show(Appointment $appointment): JsonResponse
    {
        return response()->json($this->present($appointment));
    }

    public function save(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($request->user()->role->canConsult(), 403, 'You are not allowed to write consultation notes.');
        abort_if($appointment->status === 'cancelled', 422, 'This appointment was cancelled.');

        $data = $request->validate([
            'weight_kg' => ['nullable', 'numeric', 'between:0.5,400'],
            'height_cm' => ['nullable', 'numeric', 'between:20,260'],
            'bp_systolic' => ['nullable', 'integer', 'between:40,300', 'required_with:bp_diastolic'],
            'bp_diastolic' => ['nullable', 'integer', 'between:20,200', 'required_with:bp_systolic'],
            'pulse' => ['nullable', 'integer', 'between:20,250'],
            'temperature_f' => ['nullable', 'numeric', 'between:85,112'],
            'spo2' => ['nullable', 'integer', 'between:40,100'],
            'resp_rate' => ['nullable', 'integer', 'between:4,80'],
            'allergies' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'quick_notes' => ['nullable', 'string', 'max:5000'],
            'history' => ['nullable', 'string', 'max:5000'],
            'past_medication' => ['nullable', 'string', 'max:5000'],
            'general_exam' => ['nullable', 'array:'.implode(',', self::GENERAL_EXAM_SIGNS)],
            'general_exam.*' => ['nullable', Rule::in(['present', 'absent'])],
            'general_exam_notes' => ['nullable', 'string', 'max:5000'],
            'assessment_plan' => ['nullable', 'string', 'max:5000'],
            'refer_to' => ['nullable', 'string', 'max:500'],
            'complaints' => ['nullable', 'string', 'max:5000'],
            'findings' => ['nullable', 'string', 'max:5000'],
            'diagnosis' => ['nullable', 'string', 'max:5000'],
            'tests_advised' => ['nullable', 'string', 'max:5000'],
            'investigations' => ['sometimes', 'nullable', 'array', 'max:40'],
            'investigations.*.name' => ['required', 'string', 'max:100'],
            'investigations.*.result' => ['nullable', 'string', 'max:100'],
            'investigations.*.date' => ['nullable', 'date', 'before_or_equal:today'],
            'advice' => ['nullable', 'string', 'max:5000'],
            'follow_up_on' => ['nullable', 'date', 'after_or_equal:today'],
            'complete' => ['sometimes', 'boolean'],
            'medicines' => ['sometimes', 'array', 'max:40'],
            'medicines.*.medicine' => ['required', 'string', 'max:255'],
            'medicines.*.dose' => ['nullable', 'string', 'max:50'],
            'medicines.*.frequency' => ['nullable', 'string', 'max:50'],
            'medicines.*.timing' => ['nullable', 'string', 'max:30'],
            'medicines.*.duration' => ['nullable', 'string', 'max:50'],
            'medicines.*.instructions' => ['nullable', 'string', 'max:255'],
        ], [
            'medicines.*.medicine.required' => 'Enter the medicine name or remove the empty row.',
            'investigations.*.name.required' => 'Enter the test name or remove the empty row.',
            'investigations.*.date.before_or_equal' => 'A test result cannot be dated in the future.',
            'bp_systolic.required_with' => 'Enter both blood pressure values.',
            'bp_diastolic.required_with' => 'Enter both blood pressure values.',
        ], [
            'weight_kg' => 'weight', 'height_cm' => 'height', 'bp_systolic' => 'BP systolic', 'bp_diastolic' => 'BP diastolic',
            'temperature_f' => 'temperature', 'spo2' => 'SpO₂', 'resp_rate' => 'respiratory rate', 'follow_up_on' => 'next visit date',
        ]);

        $complete = (bool) ($data['complete'] ?? false);
        $medicines = $data['medicines'] ?? null;
        $allergies = array_key_exists('allergies', $data) ? ['allergies' => $data['allergies']] : null;
        // Keep only the signs that were actually marked.
        if (isset($data['general_exam'])) {
            $data['general_exam'] = array_filter($data['general_exam']) ?: null;
        }
        if (array_key_exists('investigations', $data)) {
            $data['investigations'] = collect($data['investigations'] ?? [])->map(fn ($row) => ['name' => $row['name'], 'result' => $row['result'] ?? null, 'date' => $row['date'] ?? null])->values()->all() ?: null;
        }
        unset($data['complete'], $data['medicines'], $data['allergies']);

        DB::transaction(function () use ($appointment, $data, $medicines, $complete, $allergies) {
            if ($allergies) {
                $appointment->patient()->update($allergies);
            }

            $consultation = Consultation::firstOrNew(['appointment_id' => $appointment->id]);
            $consultation->fill($data + [
                'patient_id' => $appointment->patient_id,
                'doctor_id' => $appointment->doctor_id,
                'branch_id' => $appointment->branch_id,
            ]);
            if ($complete && ! $consultation->completed_at) {
                $consultation->completed_at = now();
            }
            $consultation->save();

            if ($medicines !== null) {
                $consultation->medicines()->delete();
                foreach (array_values($medicines) as $position => $row) {
                    $consultation->medicines()->create($row + ['position' => $position]);
                }
            }

            // Keep the front desk queue in step with what the doctor is doing.
            if ($complete) {
                DoctorLibraryController::learn($appointment->doctor_id, $consultation, $consultation->medicines()->get()->toArray());
                $appointment->update(['status' => 'reviewed']);
            } elseif (in_array($appointment->status, ['booked', 'arrived'], true)) {
                $appointment->update(['status' => 'ongoing', 'arrived_at' => $appointment->arrived_at ?? now()]);
            }
        });

        return response()->json($this->present($appointment->fresh()));
    }

    /** A patient's completed visits, newest first. */
    public function history(Patient $patient): JsonResponse
    {
        return response()->json(
            Consultation::where('patient_id', $patient->id)
                ->whereNotNull('completed_at')
                ->with(['medicines', 'doctor:id,name'])
                ->latest('completed_at')
                ->limit(30)
                ->get()
        );
    }

    /** Medicine names to offer while typing: the pharmacy's own list first, then names prescribed before. */
    public function suggestMedicines(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));

        $stocked = Medicine::where('is_active', true)
            ->when($term !== '', fn ($q) => $q->where('name', 'like', "%{$term}%"))
            ->orderBy('name')->limit(200)->pluck('name');

        $prescribed = PrescriptionItem::query()
            ->when($term !== '', fn ($q) => $q->where('medicine', 'like', "%{$term}%"))
            ->selectRaw('medicine, count(*) as uses')
            ->groupBy('medicine')
            ->orderByDesc('uses')
            ->limit(50)
            ->pluck('medicine');

        return response()->json($stocked->merge($prescribed)->unique(fn ($name) => mb_strtolower($name))->values());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Appointment $appointment): array
    {
        $appointment->load([self::PATIENT, 'doctor:id,name,department,signature_text', 'service:id,name', 'consultation.medicines']);

        return [
            // attributesToArray applies the model's date formats; only() would hand back raw UTC timestamps.
            'appointment' => array_intersect_key($appointment->attributesToArray(), array_flip(['id', 'scheduled_at', 'status', 'notes'])),
            'patient' => $appointment->patient,
            'doctor' => $appointment->doctor,
            'service' => $appointment->service,
            'consultation' => $appointment->consultation,
        ];
    }
}
