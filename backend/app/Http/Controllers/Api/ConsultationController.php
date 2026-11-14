<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AuditLog;
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
    private const PATIENT = 'patient:id,uhid,title,name,gender,date_of_birth,mobile,blood_group,allergies';

    /** The parts of a visit note, by the names used on screen, for the record of what was changed. */
    private const NOTE_LABELS = [
        'complaints' => 'Complaints', 'history' => 'History', 'past_medication' => 'Past medication', 'general_exam' => 'General examination',
        'general_exam_notes' => 'Examination notes', 'findings' => 'Findings', 'diagnosis' => 'Diagnosis', 'diagnosis_codes' => 'Diagnosis codes', 'assessment_plan' => 'Plan',
        'tests_advised' => 'Tests advised', 'investigations' => 'Test results', 'advice' => 'Advice', 'follow_up_on' => 'Next visit', 'refer_to' => 'Referral',
        'quick_notes' => 'Quick notes', 'weight_kg' => 'Weight', 'height_cm' => 'Height', 'bp_systolic' => 'BP', 'bp_diastolic' => 'BP ',
        'pulse' => 'Pulse', 'temperature_f' => 'Temperature', 'spo2' => 'SpO2', 'resp_rate' => 'Respiratory rate',
    ];

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
        AuditLog::record('visit.opened', $appointment->patient_id, $appointment);

        return response()->json($this->present($appointment));
    }

    public function save(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($request->user()->allows('canConsult'), 403, 'You are not allowed to write consultation notes.');
        abort_if($appointment->status === 'cancelled', 422, 'This appointment was cancelled.');
        if ($appointment->consultation?->isLocked()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['visit' => 'This visit was finished on '.$appointment->consultation->completed_at->format('j M Y').' and is locked. Add an amendment instead.']);
        }

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
            'diagnosis_codes' => ['sometimes', 'nullable', 'array', 'max:20'],
            'diagnosis_codes.*.code' => ['required', 'string', 'max:10', 'distinct', 'exists:icd_codes,code'],
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
            'diagnosis_codes.*.code.exists' => 'That diagnosis code is not in the list.',
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
        if (array_key_exists('diagnosis_codes', $data)) {
            // The names are taken from the list itself, never from what was sent.
            $names = \App\Models\IcdCode::whereIn('code', array_column($data['diagnosis_codes'] ?? [], 'code'))->pluck('name', 'code');
            $data['diagnosis_codes'] = collect($data['diagnosis_codes'] ?? [])->map(fn ($row) => ['code' => $row['code'], 'name' => $names[$row['code']]])->values()->all() ?: null;
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
            $wasComplete = $consultation->completed_at !== null;
            $before = $consultation->exists ? $consultation->only(array_keys(self::NOTE_LABELS)) : [];
            $medicinesBefore = $consultation->exists ? $consultation->medicines()->pluck('medicine')->all() : [];
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

            $summary = AuditLog::changed(self::NOTE_LABELS, $before, $consultation->fresh()->only(array_keys(self::NOTE_LABELS)));
            if ($medicines !== null && array_column($medicines, 'medicine') !== $medicinesBefore) {
                $summary = ($summary ? $summary.', ' : 'Changed: ').'Prescription';
            }
            if ($complete && ! $wasComplete) {
                AuditLog::record('visit.completed', $appointment->patient_id, $appointment, $summary);
            } elseif ($summary) {
                AuditLog::record('visit.saved', $appointment->patient_id, $appointment, $summary.($wasComplete ? ' (after the visit was finished)' : ''));
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

    /** Adds a dated note to a locked visit. The original notes are left exactly as they were. */
    public function amend(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($request->user()->allows('canConsult'), 403, 'You are not allowed to write consultation notes.');
        $consultation = $appointment->consultation;
        abort_unless($consultation?->completed_at, 422, 'Only a finished visit can be amended. Change the notes directly instead.');
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']], ['body.required' => 'Write what needs correcting or adding.']);

        $consultation->amendments()->create(['body' => trim($data['body']), 'created_by' => $request->user()->id]);
        AuditLog::record('visit.amended', $appointment->patient_id, $appointment);

        return response()->json($this->present($appointment->fresh()), 201);
    }

    /** Looks over a prescription for the patient's allergies and for the same medicine twice. */
    public function checkPrescription(Request $request, Patient $patient): JsonResponse
    {
        abort_unless($request->user()->allows('canConsult'), 403);
        $data = $request->validate(['medicines' => ['present', 'array', 'max:60'], 'medicines.*' => ['nullable', 'string', 'max:255'], 'allergies' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        // The pad may hold allergies typed but not yet saved; those count too.
        return response()->json(['warnings' => \App\Support\PrescriptionSafety::warnings(array_key_exists('allergies', $data) ? $data['allergies'] : $patient->allergies, $data['medicines'])]);
    }

    /** A patient's completed visits, newest first. */
    public function history(Patient $patient): JsonResponse
    {
        return response()->json(
            Consultation::where('patient_id', $patient->id)
                ->whereNotNull('completed_at')
                ->with(['medicines', 'doctor:id,name', 'amendments.author:id,name'])
                ->latest('completed_at')
                ->limit(30)
                ->get()
        );
    }

    /** Diagnoses from the ICD-10 list that match what is typed: a code ("E11") or words ("diabetes"). */
    public function icdCodes(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }
        $like = str_replace(['%', '_'], ['\%', '\_'], $term);
        $words = array_filter(explode(' ', $like));

        $found = \App\Models\IcdCode::query()
            ->where(fn ($q) => $q->where('code', 'like', $like.'%')->orWhere(fn ($w) => collect($words)->each(fn ($word) => $w->where('name', 'like', '%'.$word.'%'))))
            ->limit(300)->get(['code', 'name']);

        // A matching code first, then names that start with the words, the shorter and broader entries before the finer ones.
        $lower = mb_strtolower($term);
        $rank = fn ($c) => (str_starts_with(mb_strtolower($c->code), $lower) ? 0 : (str_starts_with(mb_strtolower($c->name), $lower) ? 1 : 2)) * 1000 + strlen($c->code) * 10 + min(9, intdiv(mb_strlen($c->name), 20));

        return response()->json($found->sortBy(fn ($c) => [$rank($c), $c->code])->take(25)->values());
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
        $appointment->load([self::PATIENT, 'doctor:id,name,department,signature_text', 'service:id,name', 'consultation.medicines', 'consultation.amendments.author:id,name']);

        return [
            // attributesToArray applies the model's date formats; only() would hand back raw UTC timestamps.
            'appointment' => array_intersect_key($appointment->attributesToArray(), array_flip(['id', 'scheduled_at', 'status', 'notes'])),
            'patient' => $appointment->patient,
            'doctor' => $appointment->doctor,
            'service' => $appointment->service,
            'consultation' => $appointment->consultation,
            // Locked from the day after it was finished: corrections then go in as amendments.
            'locked' => (bool) $appointment->consultation?->isLocked(),
            // How this doctor wants prescriptions printed, for whoever prints it.
            'rx_print' => array_intersect_key(\App\Models\User::find($appointment->doctor_id)?->rxSettings() ?? [], array_flip(['print_language', 'translations', 'capitalize_name', 'print'])),
        ];
    }
}
