<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\AdmissionNote;
use App\Models\AdmissionPayment;
use App\Models\Bed;
use App\Models\DischargeSummary;
use App\Models\IpdBillTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The written record of a stay (notes and the discharge summary), bill templates, and the IPD reports. */
class IpdRecordsController extends Controller
{
    // ---- Clinical, nursing and operation notes ----

    public function notes(Admission $admission): JsonResponse
    {
        return response()->json($admission->notes()->with('author:id,name')->get());
    }

    public function storeNote(Request $request, Admission $admission): JsonResponse
    {
        $data = $this->noteData($request);
        $admission->notes()->create($data + ['created_by' => $request->user()->id]);

        return response()->json($admission->notes()->with('author:id,name')->get(), 201);
    }

    public function updateNote(Request $request, Admission $admission, AdmissionNote $note): JsonResponse
    {
        abort_unless($note->admission_id === $admission->id, 404);
        $note->update($this->noteData($request, $note->kind) + ['updated_by' => $request->user()->id]);

        return response()->json($admission->notes()->with('author:id,name')->get());
    }

    public function destroyNote(Admission $admission, AdmissionNote $note): JsonResponse
    {
        abort_unless($note->admission_id === $admission->id, 404);
        $note->delete();

        return response()->json($admission->notes()->with('author:id,name')->get());
    }

    /**
     * @return array<string, mixed>
     */
    private function noteData(Request $request, ?string $kind = null): array
    {
        $kind ??= (string) $request->input('kind');
        $data = $request->validate([
            'kind' => [$kind && in_array($kind, AdmissionNote::KINDS, true) ? 'sometimes' : 'required', Rule::in(AdmissionNote::KINDS)],
            'noted_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->format('Y-m-d H:i:s')],
            'body' => ['nullable', 'string', 'max:20000'],
            'details' => ['nullable', 'array'],
            'details.*' => ['nullable', 'string', 'max:2000'],
        ], ['noted_at.before_or_equal' => 'A note cannot be dated in the future.']);

        $details = array_filter(array_intersect_key($data['details'] ?? [], array_flip(AdmissionNote::DETAILS[$kind] ?? [])), 'filled');
        if (blank($data['body'] ?? null) && ! $details) {
            throw ValidationException::withMessages(['body' => 'Write something in the note.']);
        }

        return ['kind' => $kind, 'noted_at' => $data['noted_at'], 'body' => $data['body'] ?? null, 'details' => $details ?: null];
    }

    // ---- Discharge summary ----

    public function summary(Admission $admission): JsonResponse
    {
        return response()->json($this->presentSummary($admission));
    }

    public function saveSummary(Request $request, Admission $admission): JsonResponse
    {
        $rules = array_fill_keys(DischargeSummary::TEXTS, ['nullable', 'string', 'max:20000']);
        $data = $request->validate($rules + [
            'follow_up_on' => ['nullable', 'date'],
            'medicines' => ['nullable', 'array', 'max:40'],
            'medicines.*.medicine' => ['required', 'string', 'max:255'],
            'medicines.*.dose' => ['nullable', 'string', 'max:50'],
            'medicines.*.frequency' => ['nullable', 'string', 'max:50'],
            'medicines.*.timing' => ['nullable', 'string', 'max:30'],
            'medicines.*.duration' => ['nullable', 'string', 'max:50'],
            'medicines.*.instructions' => ['nullable', 'string', 'max:255'],
        ], ['medicines.*.medicine.required' => 'Enter the medicine name or remove the empty row.']);

        $summary = $admission->summary()->firstOrNew();
        if ($summary->approved_at) {
            throw ValidationException::withMessages(['summary' => 'This summary has been approved. Withdraw the approval to change it.']);
        }
        $summary->fill($data + ['medicines' => null]);
        $summary->prepared_by ??= $request->user()->id;
        $summary->save();

        return response()->json($this->presentSummary($admission));
    }

    /** Approves the summary (locking it) or withdraws the approval. */
    public function approveSummary(Request $request, Admission $admission): JsonResponse
    {
        abort_unless($request->user()->allows('canApproveIpd'), 403, 'You are not allowed to approve discharge summaries.');
        $data = $request->validate(['approved' => ['required', 'boolean']]);
        $summary = $admission->summary;
        if (! $summary) {
            throw ValidationException::withMessages(['summary' => 'Write and save the summary first.']);
        }
        $summary->update($data['approved'] ? ['approved_at' => now(), 'approved_by' => $request->user()->id] : ['approved_at' => null, 'approved_by' => null]);

        return response()->json($this->presentSummary($admission));
    }

    /**
     * The summary with the people who wrote and approved it. Before one is written, the diagnosis and
     * course are suggested from what the case already records, so the doctor is not starting blank.
     *
     * @return array<string, mixed>
     */
    private function presentSummary(Admission $admission): array
    {
        $summary = $admission->summary()->with(['preparer:id,name', 'approver:id,name,signature_text'])->first();

        return $summary?->toArray() ?? [
            'id' => null, 'diagnosis' => $admission->reason, 'course' => $admission->discharge_summary, 'medicines' => null,
            'approved_at' => null, 'follow_up_on' => null,
        ];
    }

    // ---- Bill templates ----

    public function templates(): JsonResponse
    {
        return response()->json(IpdBillTemplate::orderBy('name')->get());
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        return response()->json(IpdBillTemplate::create($this->templateData($request)), 201);
    }

    public function updateTemplate(Request $request, IpdBillTemplate $template): JsonResponse
    {
        $template->update($this->templateData($request, $template));

        return response()->json($template);
    }

    public function destroyTemplate(IpdBillTemplate $template): JsonResponse
    {
        $template->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function templateData(Request $request, ?IpdBillTemplate $template = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('ipd_bill_templates')->ignore($template)],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.ipd_service_id' => ['required', 'distinct', 'exists:ipd_services,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999'],
        ], ['items.required' => 'Add at least one service to the template.', 'name.unique' => 'There is already a template with this name.']);
    }

    // ---- Reports ----

    public const REPORTS = ['cases' => 'Case report', 'beds' => 'Bed report', 'payments' => 'Payment report'];

    /** Same shape as the pharmacy reports, so one screen shows them all. */
    public function report(Request $request, string $type): JsonResponse
    {
        abort_unless(isset(self::REPORTS[$type]), 404, 'There is no such report.');
        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($range['from'] ?? today())->startOfDay();
        $to = Carbon::parse($range['to'] ?? $from)->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose a range of one year or less.');

        [$columns, $rows] = $this->{$type}($from, $to);
        $rows = collect($rows)->values();
        $totals = [];
        foreach ($columns as $column) {
            if (in_array($column['type'], ['money', 'number'], true) && $column['total']) {
                $totals[$column['key']] = round((float) $rows->sum($column['key']), 2);
            }
        }

        return response()->json([
            'type' => $type, 'title' => self::REPORTS[$type], 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'reports' => self::REPORTS,
        ]);
    }

    private static function col(string $key, string $label, string $type = 'text', bool $total = true): array
    {
        return compact('key', 'label', 'type', 'total');
    }

    /** Stays that began in the period. */
    private function cases(Carbon $from, Carbon $to): array
    {
        $rows = Admission::whereBetween('admitted_at', [$from, $to])->with(['patient:id,name,uhid', 'doctor:id,name', 'bed:id,ward,bed_no'])->orderBy('admitted_at')->get()
            ->map(fn (Admission $a) => [
                'ipd_no' => $a->ipd_no, 'patient' => $a->patient->name, 'uhid' => $a->patient->uhid, 'doctor' => $a->doctor?->name,
                'admitted' => $a->admitted_at->format('Y-m-d H:i'), 'discharged' => $a->discharged_at?->format('Y-m-d H:i'),
                'bed' => $a->bed ? "{$a->bed->ward} {$a->bed->bed_no}" : null,
                'total' => $a->net, 'paid' => $a->paid, 'due' => max(0, $a->due), 'advance' => max(0, -$a->due), 'status' => ucfirst($a->status),
            ]);

        return [[
            self::col('ipd_no', 'IPD no.'), self::col('patient', 'Patient'), self::col('uhid', 'Patient ID'), self::col('doctor', 'Doctor'),
            self::col('admitted', 'Admitted'), self::col('discharged', 'Discharged'), self::col('bed', 'Bed'),
            self::col('total', 'Net bill', 'money'), self::col('paid', 'Paid', 'money'), self::col('due', 'Due', 'money'),
            self::col('advance', 'Advance left', 'money'), self::col('status', 'Status'),
        ], $rows];
    }

    /**
     * Each bed, who is in it now, and how much of the period it was in use.
     * A stay is counted against the bed the patient is in (or left from); earlier beds in the same stay are not known.
     */
    private function beds(Carbon $from, Carbon $to): array
    {
        $end = $to->copy()->min(now());
        $days = max(1, (int) ceil($from->diffInHours($end) / 24));
        $stays = Admission::whereNotNull('bed_id')->where('admitted_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('discharged_at')->orWhere('discharged_at', '>=', $from))
            ->with('patient:id,name')->get()->groupBy('bed_id');

        $rows = Bed::orderBy('ward')->orderBy('bed_no')->get()->map(function (Bed $bed) use ($stays, $from, $end, $days) {
            $mine = $stays->get($bed->id, collect());
            $used = $mine->sum(fn (Admission $a) => max(0, $a->admitted_at->max($from)->diffInHours(($a->discharged_at ?? $end)->min($end)) / 24));
            $current = $mine->firstWhere('status', 'admitted');

            return [
                'ward' => $bed->ward, 'bed' => $bed->bed_no,
                'status' => ! $bed->is_active ? 'Not in use' : ($current ? 'Occupied' : 'Free'),
                'patient' => $current?->patient->name, 'ipd_no' => $current?->ipd_no, 'since' => $current?->admitted_at->format('Y-m-d H:i'),
                'stays' => $mine->count(), 'days_used' => round($used, 1), 'usage' => min(100, round($used / $days * 100, 1)),
            ];
        });

        return [[
            self::col('ward', 'Ward'), self::col('bed', 'Bed'), self::col('status', 'Status'), self::col('patient', 'Allotted to'),
            self::col('ipd_no', 'IPD no.'), self::col('since', 'Allotted on'), self::col('stays', 'Stays', 'number'),
            self::col('days_used', 'Days used', 'number'), self::col('usage', 'Usage %', 'number', false),
        ], $rows];
    }

    /** Money taken or refunded on IPD cases in the period. Refunds show as negative amounts. */
    private function payments(Carbon $from, Carbon $to): array
    {
        $rows = AdmissionPayment::whereBetween('created_at', [$from, $to])->with(['receiver:id,name'])->orderBy('created_at')->get()
            ->map(function (AdmissionPayment $p) {
                $admission = Admission::with('patient:id,name')->find($p->admission_id);

                return [
                    'date' => $p->created_at->format('Y-m-d H:i'), 'ipd_no' => $admission?->ipd_no, 'patient' => $admission?->patient->name,
                    'type' => ucfirst($p->type), 'amount' => $p->type === 'refund' ? -1 * (float) $p->amount : (float) $p->amount,
                    'mode' => $p->mode, 'reference' => $p->reference, 'person' => $p->receiver?->name,
                ];
            });

        return [[
            self::col('date', 'Date'), self::col('ipd_no', 'IPD no.'), self::col('patient', 'Patient'), self::col('type', 'Type'),
            self::col('amount', 'Amount', 'money'), self::col('mode', 'Mode'), self::col('reference', 'Reference'), self::col('person', 'Collected by'),
        ], $rows];
    }
}
