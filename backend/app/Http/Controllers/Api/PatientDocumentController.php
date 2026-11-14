<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Certificates, referral letters and consent forms. A doctor issues them; once issued the
 * wording cannot be changed, only cancelled with a reason, so a paper already handed over
 * always matches what the hospital holds.
 */
class PatientDocumentController extends Controller
{
    private const WITH = ['doctor:id,name,department,signature_text', 'issuer:id,name', 'canceller:id,name'];

    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->mustRead($request);

        return response()->json(PatientDocument::where('patient_id', $patient->id)->with(self::WITH)->latest('id')->limit(200)->get());
    }

    /** What the issue screen needs to offer sensible wording: the patient, the doctors, and the latest visit. */
    public function draft(Request $request, Patient $patient): JsonResponse
    {
        $this->mustIssue($request);
        $last = Consultation::where('patient_id', $patient->id)->with('medicines:id,consultation_id,medicine,dose,frequency,duration')->latest('id')->first();

        return response()->json([
            'kinds' => collect(PatientDocument::KINDS)->map(fn ($k, $key) => ['key' => $key, 'label' => $k[0]])->values(),
            'doctors' => User::where('role', Role::Doctor)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'department']),
            'me' => $request->user()->role === Role::Doctor ? $request->user()->id : null,
            'last_visit' => $last ? [
                'date' => $last->created_at->toDateString(), 'complaints' => $last->complaints, 'diagnosis' => $last->diagnosis, 'findings' => $last->findings,
                'medicines' => $last->medicines->map(fn ($m) => trim($m->medicine.' '.implode(' ', array_filter([$m->dose, $m->frequency, $m->duration]))))->all(),
            ] : null,
        ]);
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        $this->mustIssue($request);
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(PatientDocument::KINDS))],
            'title' => ['required', 'string', 'max:255'],
            'addressed_to' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'min:20', 'max:10000'],
            'issued_on' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.today()->subDays(7)->toDateString()],
            'doctor_id' => ['required', Rule::exists('users', 'id')->where('role', Role::Doctor->value)->where('is_active', true)],
            'appointment_id' => ['nullable', Rule::exists('appointments', 'id')->where('patient_id', $patient->id)],
            'admission_id' => ['nullable', Rule::exists('admissions', 'id')->where('patient_id', $patient->id)],
        ], [
            'body.required' => 'Write the wording of the document.', 'body.min' => 'The wording is too short to issue.',
            'issued_on.after_or_equal' => 'A document can be dated today or up to a week back, not earlier.',
            'issued_on.before_or_equal' => 'A document cannot be dated in the future.',
            'doctor_id.required' => 'Choose the doctor who signs it.',
        ]);

        $document = PatientDocument::create($data + ['patient_id' => $patient->id, 'created_by' => $request->user()->id])->fresh();
        AuditLog::record('document.issued', $patient->id, $document, $document->doc_no.' '.$document->title);

        return response()->json($document->load(self::WITH), 201);
    }

    /** One document with the patient's details, for printing. */
    public function show(Request $request, PatientDocument $document): JsonResponse
    {
        $this->mustRead($request);

        return response()->json($document->load(array_merge(self::WITH, ['patient:id,uhid,title,name,gender,date_of_birth,mobile,address,city'])));
    }

    public function cancel(Request $request, PatientDocument $document): JsonResponse
    {
        $this->mustIssue($request);
        if ($document->cancelled_at) {
            throw ValidationException::withMessages(['reason' => 'This document was already cancelled.']);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], ['reason.required' => 'Say why it is being cancelled.']);
        $document->update(['cancelled_at' => now(), 'cancelled_by' => $request->user()->id, 'cancel_reason' => $data['reason']]);
        AuditLog::record('document.cancelled', $document->patient_id, $document, $document->doc_no.': '.$data['reason']);

        return response()->json($document->load(self::WITH));
    }

    private function mustRead(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->allows('canConsult') || $user->allows('canWorkFrontDesk'), 403, 'You are not allowed to see these documents.');
    }

    /** Certificates and letters carry a doctor's name, so only doctor-level staff issue or cancel them. */
    private function mustIssue(Request $request): void
    {
        abort_unless($request->user()->allows('canApproveIpd'), 403, 'Only a doctor can issue or cancel certificates and letters.');
    }
}
