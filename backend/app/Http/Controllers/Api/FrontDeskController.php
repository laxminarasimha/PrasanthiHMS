<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\Bill;
use App\Models\Consultation;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PharmacySale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The front desk's day: walk-in bills, the day's totals, the waiting-room queue, vitals, attachments and a patient's whole record. */
class FrontDeskController extends Controller
{
    /** Bills made on the day that do not belong to a visit: lab-only walk-ins and other services. */
    public function walkInBills(Request $request): JsonResponse
    {
        $filters = $request->validate(['date' => ['nullable', 'date'], 'q' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) ($filters['q'] ?? ''));

        return response()->json(
            Bill::whereNull('appointment_id')
                ->whereDate('created_at', $filters['date'] ?? today())
                ->when($term !== '', fn ($q) => $q->whereHas('patient', fn ($p) => $p->where('name', 'like', "%{$term}%")
                    ->orWhere('mobile', 'like', "{$term}%")->orWhere('uhid', 'like', "{$term}%")))
                ->with(['patient:id,uhid,name,gender,date_of_birth,mobile', 'labOrder:id,bill_id,order_no', 'labOrder.items:id,lab_order_id', 'items:id,bill_id,description'])
                ->withIpdTransfers()
                ->orderBy('created_at')->get()
        );
    }

    /** The day in numbers: money by department and appointments by doctor. */
    public function summary(Request $request): JsonResponse
    {
        $day = Carbon::parse($request->validate(['date' => ['nullable', 'date']])['date'] ?? today());
        [$from, $to] = [$day->copy()->startOfDay(), $day->copy()->endOfDay()];

        $bills = Bill::whereBetween('created_at', [$from, $to])->get(['id', 'kind', 'total', 'paid']);
        // Money moved to an IPD bill is not money received here.
        $money = Payment::whereBetween('payments.created_at', [$from, $to])->where('payments.mode', '!=', 'ipd')
            ->join('bills', 'bills.id', '=', 'payments.bill_id')
            ->selectRaw('bills.kind, payments.type, sum(payments.amount) as amount')
            ->groupBy('bills.kind', 'payments.type')->get();

        $row = function (?string $kind) use ($bills, $money) {
            $set = $kind ? $bills->where('kind', $kind) : $bills;
            $paid = (float) $money->when($kind, fn ($m) => $m->where('kind', $kind))->where('type', 'payment')->sum('amount');
            $refunded = (float) $money->when($kind, fn ($m) => $m->where('kind', $kind))->where('type', 'refund')->sum('amount');

            return [
                'billed' => round($set->sum('total'), 2), 'collected' => round($paid - $refunded, 2), 'refunded' => round($refunded, 2),
                'orders' => $set->count(), 'outstanding' => round($set->sum(fn ($b) => $b->total - $b->paid), 2),
            ];
        };

        $appointments = Appointment::whereBetween('scheduled_at', [$from, $to])->where('status', '!=', 'cancelled')
            ->with('doctor:id,name')->get(['id', 'doctor_id', 'status'])->groupBy('doctor_id')
            ->map(fn ($visits) => [
                'doctor' => $visits->first()->doctor->name, 'total' => $visits->count(),
                'booked' => $visits->where('status', 'booked')->count(), 'arrived' => $visits->where('status', 'arrived')->count(),
                'ongoing' => $visits->where('status', 'ongoing')->count(), 'reviewed' => $visits->where('status', 'reviewed')->count(),
            ])->values();

        return response()->json([
            'date' => $day->toDateString(),
            'bills' => ['all' => $row(null), 'appointment' => $row('appointment'), 'lab' => $row('lab'), 'other' => $row('other')],
            'appointments' => $appointments,
        ]);
    }

    /** For the waiting-room screen: who each doctor is seeing now and who is next, by token. */
    public function queue(Request $request): JsonResponse
    {
        $day = $request->validate(['date' => ['nullable', 'date']])['date'] ?? today();

        $visits = Appointment::whereDate('scheduled_at', $day)->whereIn('status', ['arrived', 'ongoing'])
            ->with(['patient:id,name', 'doctor:id,name,department'])
            ->orderBy('arrived_at')->orderBy('token_no')->get(['id', 'patient_id', 'doctor_id', 'status', 'token_no', 'arrived_at']);

        // Only the token and a short name go on a public screen.
        $entry = fn (Appointment $a) => ['token' => $a->token_no, 'name' => $a->patient->name];

        return response()->json($visits->groupBy('doctor_id')->map(fn ($list) => [
            'doctor' => $list->first()->doctor->name,
            'department' => $list->first()->doctor->department,
            'now' => $list->where('status', 'ongoing')->map($entry)->values(),
            'waiting' => $list->where('status', 'arrived')->map($entry)->values(),
        ])->values());
    }

    /**
     * Patients a doctor asked to come back on this day, and whether each already has an appointment for it.
     */
    public function followUps(Request $request): JsonResponse
    {
        $day = \Illuminate\Support\Carbon::parse($request->validate(['date' => ['nullable', 'date']])['date'] ?? today())->toDateString();

        $due = \App\Models\Consultation::whereDate('follow_up_on', $day)->whereNotNull('completed_at')
            ->with(['patient:id,uhid,name,gender,date_of_birth,mobile', 'doctor:id,name'])
            ->latest('completed_at')->get(['id', 'appointment_id', 'patient_id', 'doctor_id', 'diagnosis', 'follow_up_on', 'completed_at']);

        $booked = Appointment::whereDate('scheduled_at', $day)->where('status', '!=', 'cancelled')
            ->whereIn('patient_id', $due->pluck('patient_id'))->pluck('patient_id')->flip();

        // One line per patient, even if two doctors gave the same date.
        return response()->json($due->unique('patient_id')->values()->map(fn ($c) => $c->toArray() + ['booked' => $booked->has($c->patient_id)]));
    }

    /** Lets the front desk or a nurse record vitals before the doctor opens the visit. Nothing else on the visit changes. */
    public function saveVitals(Request $request, Appointment $appointment): JsonResponse
    {
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
        ], ['bp_systolic.required_with' => 'Enter both blood pressure values.', 'bp_diastolic.required_with' => 'Enter both blood pressure values.'], [
            'weight_kg' => 'weight', 'height_cm' => 'height', 'temperature_f' => 'temperature', 'spo2' => 'SpO₂', 'resp_rate' => 'respiratory rate',
        ]);

        $consultation = Consultation::firstOrNew(['appointment_id' => $appointment->id]);
        $consultation->fill($data + ['patient_id' => $appointment->patient_id, 'doctor_id' => $appointment->doctor_id, 'branch_id' => $appointment->branch_id])->save();

        return response()->json($consultation->only(['id', 'weight_kg', 'height_cm', 'bp_systolic', 'bp_diastolic', 'pulse', 'temperature_f', 'spo2', 'resp_rate']));
    }

    /** Everything the hospital holds on one patient, newest first. */
    public function overview(Patient $patient): JsonResponse
    {
        $bills = Bill::where('patient_id', $patient->id)->with('labOrder:id,bill_id,order_no')->withIpdTransfers()->latest()->limit(50)->get();

        return response()->json([
            'patient' => $patient,
            'visits' => Appointment::where('patient_id', $patient->id)->with(['doctor:id,name', 'service:id,name', 'consultation:id,appointment_id,diagnosis,completed_at'])
                ->latest('scheduled_at')->limit(50)->get(),
            'bills' => $bills,
            'lab_orders' => LabOrder::where('patient_id', $patient->id)->with('items:id,lab_order_id,test_name,reported_at')->latest('ordered_at')->limit(30)->get(),
            'pharmacy_bills' => PharmacySale::where('patient_id', $patient->id)->whereNull('voided_at')->latest('sold_at')->limit(30)->get(),
            'admissions' => Admission::where('patient_id', $patient->id)->with(['doctor:id,name', 'bed:id,ward,bed_no'])->latest('admitted_at')->limit(20)->get(),
            'attachments' => Attachment::where('patient_id', $patient->id)->with('uploader:id,name')->latest('document_date')->get(),
            'outstanding' => round($bills->sum('due'), 2),
        ]);
    }

    // ---- Attachments (reports and papers the patient brings) ----

    public function attachments(Patient $patient): JsonResponse
    {
        return response()->json(Attachment::where('patient_id', $patient->id)->with('uploader:id,name')->latest('document_date')->latest('id')->get());
    }

    public function storeAttachment(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'document_date' => ['required', 'date', 'before_or_equal:today'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ], ['file.mimes' => 'Attach a PDF or an image (JPG, PNG or WebP).', 'file.max' => 'The file must be 10 MB or smaller.']);

        $file = $request->file('file');
        // Kept on the private disk: these are medical records and are only ever served to signed-in staff.
        $attachment = Attachment::create([
            'patient_id' => $patient->id, 'appointment_id' => $data['appointment_id'] ?? null, 'title' => $data['title'],
            'document_date' => $data['document_date'], 'path' => $file->store("attachments/{$patient->id}", 'local'),
            'original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($attachment->load('uploader:id,name'), 201);
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($attachment->path), 404, 'The file is missing.');

        return Storage::disk('local')->response($attachment->path, $attachment->original_name, ['Content-Type' => $attachment->mime]);
    }

    public function destroyAttachment(Attachment $attachment): JsonResponse
    {
        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();

        return response()->json(['message' => 'Attachment removed.']);
    }
}
