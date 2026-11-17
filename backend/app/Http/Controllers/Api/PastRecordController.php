<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\LabTestParameter;
use App\Models\PastRecord;
use App\Models\Patient;
use App\Models\PatientHealthItem;
use App\Models\Setting;
use App\Support\AiReader;
use App\Support\AiReadingFailed;
use App\Support\PastRecordDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Old papers a patient brings: scanned at the desk, read by a machine into a draft, checked by a
 * person against the scan, and only then filed in the patient's record with the scan kept beside it.
 */
class PastRecordController extends Controller
{
    /** The settings row that holds the reading computer's key and when it last called in. */
    public const READER = 'record_reader';

    /** The reading computer counts as switched on if it called in this recently. */
    private const ONLINE_MINUTES = 3;

    /** Papers across all patients: those waiting to be checked, oldest first, or those filed lately. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['to_check', 'filed'])]]);
        $status = $data['status'] ?? 'to_check';

        $records = PastRecord::where('status', $status)
            ->with(['patient:id,uhid,title,name,gender,date_of_birth', 'pages:id,past_record_id,original_name,mime,size', 'creator:id,name', 'checker:id,name'])
            ->when($status === 'to_check', fn ($q) => $q->oldest('id'), fn ($q) => $q->latest('checked_at'))
            ->limit(200)->get();

        return response()->json([
            'records' => $records->map(fn (PastRecord $r) => $this->present($r)),
            'counts' => ['to_check' => PastRecord::where('status', 'to_check')->count(), 'filed' => PastRecord::where('status', 'filed')->count()],
            'reading' => self::reading(),
        ]);
    }

    public function forPatient(Patient $patient): JsonResponse
    {
        $records = PastRecord::where('patient_id', $patient->id)
            ->with(['pages:id,past_record_id,original_name,mime,size', 'creator:id,name', 'checker:id,name'])
            ->orderByRaw("status = 'to_check' desc")->orderByDesc('record_date')->orderByDesc('id')->get();

        return response()->json(['records' => $records->map(fn (PastRecord $r) => $this->present($r)), 'reading' => self::reading()]);
    }

    /**
     * Takes the scanned files of one or more papers. Each file is a paper of its own, unless the
     * person scanning says the files are the pages of one paper.
     */
    public function store(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:12'],
            'files.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
            'kind' => ['nullable', Rule::in(array_keys(PastRecord::KINDS))],
            'record_date' => ['nullable', 'date', 'after:1950-01-01', 'before_or_equal:today'],
            'one_paper' => ['sometimes', 'boolean'],
        ], [
            'files.required' => 'Choose the scanned files.', 'files.max' => 'Add up to 12 files at a time.',
            'files.*.mimes' => 'Each file must be a PDF or a picture (JPG, PNG or WebP).', 'files.*.max' => 'Each file must be 10 MB or smaller.',
        ]);

        $files = $request->file('files');
        $groups = ($data['one_paper'] ?? false) ? [$files] : array_map(fn ($f) => [$f], $files);
        $state = self::reading()['marker']['set_up'] ? 'queued' : 'none';

        $made = DB::transaction(fn () => collect($groups)->map(function (array $pages) use ($patient, $data, $request, $state) {
            $record = PastRecord::create([
                'patient_id' => $patient->id, 'kind' => $data['kind'] ?? 'other', 'record_date' => $data['record_date'] ?? null,
                'status' => 'to_check', 'read_state' => $state, 'created_by' => $request->user()->id,
            ]);
            foreach (array_values($pages) as $i => $file) {
                // Kept on the private disk: these are medical records and are only ever served to signed-in staff.
                Attachment::create([
                    'patient_id' => $patient->id, 'past_record_id' => $record->id, 'page_no' => $i + 1,
                    'title' => 'Old paper: '.PastRecord::KINDS[$record->kind], 'document_date' => $data['record_date'] ?? today()->toDateString(),
                    'path' => $file->store("attachments/{$patient->id}", 'local'), 'original_name' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => $request->user()->id,
                ]);
            }

            return $record;
        }));

        AuditLog::record('past.scanned', $patient->id, $made->first(), $made->count() === 1 ? 'One old paper scanned ('.count($files).' '.Str::plural('file', count($files)).')' : $made->count().' old papers scanned');

        return $this->forPatient($patient)->setStatusCode(201);
    }

    public function show(PastRecord $record): JsonResponse
    {
        return response()->json($this->full($record));
    }

    /** Saves the checking half-way, so a long paper need not be finished in one sitting. */
    public function update(Request $request, PastRecord $record): JsonResponse
    {
        abort_if($record->status === 'filed', 409, 'This paper is already filed. Correct it and file it again.');
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(PastRecord::KINDS))],
            'record_date' => ['nullable', 'date', 'after:1950-01-01', 'before_or_equal:today'],
            'doctor_name' => ['nullable', 'string', 'max:255'],
            'hospital_name' => ['nullable', 'string', 'max:255'],
            'draft' => ['required', 'array'],
        ]);
        $record->update([
            'kind' => $data['kind'], 'record_date' => $data['record_date'] ?? null, 'doctor_name' => $data['doctor_name'] ?? null, 'hospital_name' => $data['hospital_name'] ?? null,
            'draft' => array_merge(PastRecordDraft::tidy($data['draft']), ['unsure' => $record->draft['unsure'] ?? [], 'patient_name' => $record->draft['patient_name'] ?? '']),
        ]);

        return response()->json($this->full($record));
    }

    /**
     * Asks for the paper to be read: by the reading computer inside the hospital, which picks it up
     * in its own time, or by the outside AI service, which answers while the person waits.
     */
    public function read(Request $request, PastRecord $record): JsonResponse
    {
        $data = $request->validate(['engine' => ['required', Rule::in(['marker', 'ai'])]]);
        abort_if($record->status === 'filed', 409, 'This paper is already filed.');

        if ($data['engine'] === 'marker') {
            abort_unless(self::reading()['marker']['set_up'], 422, 'No reading computer is set up yet.');
            $record->update(['read_state' => 'queued', 'read_error' => null, 'read_claimed_at' => null]);

            return response()->json($this->full($record));
        }

        $record->load('pages');
        $pages = $record->pages->count();
        try {
            $read = app(AiReader::class)->read($record);
        } catch (AiReadingFailed $e) {
            if ($e->sent) {
                $record->update(['ai_sent_at' => now()]);
                AuditLog::record('past.ai_read', $record->patient_id, $record, "{$pages} ".Str::plural('page', $pages).' sent to the AI reading service; no reading came back');
            }
            throw ValidationException::withMessages(['engine' => $e->getMessage()]);
        }

        $draft = $read['draft'];
        $record->update([
            'draft' => $draft, 'read_text' => $read['text'], 'read_engine' => 'ai', 'read_state' => 'done', 'read_error' => null, 'read_at' => now(), 'ai_sent_at' => now(),
            // What the reading found fills only what nobody has chosen yet.
            'kind' => $record->kind === 'other' && $draft['kind'] ? $draft['kind'] : $record->kind,
            'record_date' => $record->record_date ?? $draft['record_date'],
            'doctor_name' => $record->doctor_name ?: ($draft['doctor_name'] ?: null),
            'hospital_name' => $record->hospital_name ?: ($draft['hospital_name'] ?: null),
        ]);
        AuditLog::record('past.ai_read', $record->patient_id, $record, "{$pages} ".Str::plural('page', $pages).' sent to the AI reading service and read ('.number_format($read['tokens']['in']).' in, '.number_format($read['tokens']['out']).' out)');

        return response()->json($this->full($record));
    }

    /**
     * Files a checked paper: the visit or report joins the case history, its test values join the
     * results over time, and lasting facts join the health summary. Filing again corrects it.
     */
    public function file(Request $request, PastRecord $record): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(PastRecord::KINDS))],
            'record_date' => ['required', 'date', 'after:1950-01-01', 'before_or_equal:today'],
            'doctor_name' => ['nullable', 'string', 'max:255'],
            'hospital_name' => ['nullable', 'string', 'max:255'],
            ...array_fill_keys(PastRecord::TEXTS, ['nullable', 'string', 'max:5000']),
            'allergies' => ['nullable', 'string', 'max:500'],
            'medicines' => ['sometimes', 'array', 'max:'.PastRecordDraft::MAX_MEDICINES],
            'medicines.*.name' => ['required', 'string', 'max:255'],
            'medicines.*.dose' => ['nullable', 'string', 'max:100'],
            'medicines.*.frequency' => ['nullable', 'string', 'max:100'],
            'medicines.*.duration' => ['nullable', 'string', 'max:100'],
            'medicines.*.instructions' => ['nullable', 'string', 'max:255'],
            'results' => ['sometimes', 'array', 'max:'.PastRecordDraft::MAX_RESULTS],
            'results.*.test' => ['nullable', 'string', 'max:255'],
            'results.*.parameter' => ['required', 'string', 'max:255'],
            'results.*.value' => ['required', 'string', 'max:255'],
            'results.*.unit' => ['nullable', 'string', 'max:30'],
            'results.*.reference_range' => ['nullable', 'string', 'max:255'],
            'results.*.flag' => ['nullable', Rule::in(['high', 'low'])],
            'facts' => ['sometimes', 'array', 'max:'.PastRecordDraft::MAX_FACTS],
            'facts.*.kind' => ['required', Rule::in(array_keys(PatientHealthItem::KINDS))],
            'facts.*.title' => ['required', 'string', 'max:255'],
            'facts.*.since' => ['nullable', 'string', 'max:30'],
        ], [
            'record_date.required' => 'Give the date on the paper. If only the month or year is known, use the first day of it.',
            'record_date.before_or_equal' => 'The date on the paper cannot be in the future.',
            'medicines.*.name.required' => 'Each medicine line needs a name. Remove the empty lines.',
            'results.*.parameter.required' => 'Each test value needs the name of what was measured. Remove the empty lines.',
            'results.*.value.required' => 'Each test line needs its value. Remove the empty lines.',
            'facts.*.title.required' => 'Each health summary line needs some words. Remove the empty lines.',
        ]);

        $user = $request->user();
        $patient = $record->patient;
        $date = Carbon::parse($data['record_date']);
        $wasFiled = $record->status === 'filed';

        $added = DB::transaction(function () use ($record, $data, $user, $patient, $date) {
            $record->update([
                'kind' => $data['kind'], 'record_date' => $date->toDateString(), 'doctor_name' => $data['doctor_name'] ?? null, 'hospital_name' => $data['hospital_name'] ?? null,
                'status' => 'filed', 'checked_by' => $user->id, 'checked_at' => now(),
                'details' => collect(PastRecord::TEXTS)->mapWithKeys(fn ($field) => [$field => filled($data[$field] ?? null) ? trim($data[$field]) : null])->all()
                    + ['medicines' => array_map(fn ($m) => array_map(fn ($v) => filled($v) ? trim($v) : null, $m + ['dose' => null, 'frequency' => null, 'duration' => null, 'instructions' => null]), $data['medicines'] ?? [])],
            ]);

            $record->results()->delete();
            $hospital = self::hospitalTests();
            foreach (array_values($data['results'] ?? []) as $i => $row) {
                $record->results()->create([
                    'patient_id' => $record->patient_id, 'test_name' => self::testName($row, $hospital), 'parameter' => trim($row['parameter']), 'value' => trim($row['value']),
                    'unit' => filled($row['unit'] ?? null) ? trim($row['unit']) : null, 'reference_range' => filled($row['reference_range'] ?? null) ? trim($row['reference_range']) : null,
                    'flag' => $row['flag'] ?? null, 'taken_on' => $date->toDateString(), 'position' => $i,
                ]);
            }

            // The scan carries the paper's own date and name from now on.
            $record->pages()->update(['title' => 'Old paper: '.PastRecord::KINDS[$record->kind].($record->hospital_name ? ' · '.$record->hospital_name : ''), 'document_date' => $date->toDateString()]);

            $added = [];
            $existing = PatientHealthItem::where('patient_id', $record->patient_id)->where('is_active', true)->get();
            foreach ($data['facts'] ?? [] as $fact) {
                $title = trim($fact['title']);
                // The same thing typed twice is one fact, not two.
                if ($existing->contains(fn (PatientHealthItem $i) => $i->kind === $fact['kind'] && mb_strtolower(trim($i->title)) === mb_strtolower($title))) {
                    continue;
                }
                $existing->push(PatientHealthItem::create([
                    'patient_id' => $record->patient_id, 'past_record_id' => $record->id, 'kind' => $fact['kind'], 'title' => $title,
                    'since' => filled($fact['since'] ?? null) ? trim($fact['since']) : null, 'detail' => 'From a paper dated '.$date->format('j M Y').($record->hospital_name ? ', '.$record->hospital_name : ''),
                    'created_by' => $user->id, 'updated_by' => $user->id,
                ]));
                $added[] = PatientHealthItem::KINDS[$fact['kind']].': '.$title;
            }

            $allergy = trim((string) ($data['allergies'] ?? ''));
            if ($allergy !== '' && ! str_contains(mb_strtolower((string) $patient->allergies), mb_strtolower($allergy))) {
                $was = $patient->allergies;
                $patient->update(['allergies' => mb_substr(filled($was) ? $was.', '.$allergy : $allergy, 0, 1000)]);
                AuditLog::record('allergies.updated', $patient->id, $patient, 'From “'.($was ?? 'none').'” to “'.$patient->allergies.'” (from an old paper)');
            }

            return $added;
        });

        foreach ($added as $line) {
            AuditLog::record('health.added', $record->patient_id, $record, $line.' (from an old paper)');
        }
        AuditLog::record($wasFiled ? 'past.corrected' : 'past.filed', $record->patient_id, $record, PastRecord::KINDS[$record->kind].' dated '.$date->format('j M Y')
            .(($n = count($data['results'] ?? [])) ? ", {$n} test ".Str::plural('value', $n) : '').(($n = count($data['medicines'] ?? [])) ? ", {$n} ".Str::plural('medicine', $n) : ''));

        return response()->json($this->full($record->fresh()));
    }

    /** Removes a paper scanned by mistake, with its files and anything filed from it except health summary lines. */
    public function destroy(PastRecord $record): JsonResponse
    {
        $record->load('pages');
        AuditLog::record('past.removed', $record->patient_id, $record, PastRecord::KINDS[$record->kind].($record->record_date ? ' dated '.$record->record_date->format('j M Y') : '').($record->status === 'filed' ? ' (it had been filed)' : ' (not yet filed)'));
        foreach ($record->pages as $page) {
            Storage::disk('local')->delete($page->path);
        }
        $record->delete();

        return response()->json(['message' => 'Paper removed.']);
    }

    // ---- Setup: the reading computer and AI reading ----

    public function setup(): JsonResponse
    {
        return response()->json(self::reading() + ['waiting' => PastRecord::whereIn('read_state', ['queued', 'reading'])->count()]);
    }

    /** Makes a new key for the reading computer. It is shown once; an older key stops working. */
    public function newReaderKey(): JsonResponse
    {
        $key = Str::random(48);
        Setting::updateOrCreate(['key' => self::READER], ['value' => ['key_hash' => hash('sha256', $key), 'made_at' => now()->format('Y-m-d H:i'), 'seen_at' => null]]);
        AuditLog::record('past.reader_key', null, null, 'A new key was made for the reading computer');

        return response()->json(self::reading() + ['key' => $key]);
    }

    public function removeReaderKey(): JsonResponse
    {
        Setting::whereKey(self::READER)->delete();
        // Nothing will come for the papers that were waiting; they go back to being typed by hand or read on request.
        PastRecord::whereIn('read_state', ['queued', 'reading'])->update(['read_state' => 'none', 'read_claimed_at' => null]);
        AuditLog::record('past.reader_key', null, null, 'The reading computer was switched off');

        return response()->json(self::reading());
    }

    /**
     * What can read a scan right now.
     *
     * @return array{marker: array{set_up: bool, online: bool, seen_at: ?string}, ai: array{set_up: bool}}
     */
    public static function reading(): array
    {
        $reader = Setting::find(self::READER)?->value ?? [];
        $seen = filled($reader['seen_at'] ?? null) ? Carbon::parse($reader['seen_at']) : null;

        return [
            'marker' => ['set_up' => filled($reader['key_hash'] ?? null), 'online' => $seen !== null && $seen->gte(now()->subMinutes(self::ONLINE_MINUTES)), 'seen_at' => $seen?->format('Y-m-d H:i')],
            'ai' => ['set_up' => AiReader::ready()],
        ];
    }

    // ---- Shaping ----

    /**
     * @return array<string, mixed>
     */
    private function present(PastRecord $r): array
    {
        return [
            'id' => $r->id, 'patient_id' => $r->patient_id, 'kind' => $r->kind, 'kind_label' => PastRecord::KINDS[$r->kind] ?? $r->kind,
            'record_date' => $r->record_date?->toDateString(), 'doctor_name' => $r->doctor_name, 'hospital_name' => $r->hospital_name,
            'status' => $r->status, 'read_state' => $r->read_state, 'read_engine' => $r->read_engine, 'read_error' => $r->read_error,
            'read_at' => $r->read_at?->format('Y-m-d H:i'), 'ai_sent_at' => $r->ai_sent_at?->format('Y-m-d H:i'),
            'checked_at' => $r->checked_at?->format('Y-m-d H:i'), 'created_at' => $r->created_at->format('Y-m-d H:i'),
            'creator' => $r->creator?->only(['id', 'name']), 'checker' => $r->checker?->only(['id', 'name']),
            'pages' => $r->pages->map(fn (Attachment $a) => $a->only(['id', 'original_name', 'mime', 'size']))->values(),
            'patient' => $r->relationLoaded('patient') ? $r->patient?->only(['id', 'uhid', 'title', 'name', 'gender', 'age_label']) : null,
        ];
    }

    /**
     * One paper with everything the checking screen needs.
     *
     * @return array<string, mixed>
     */
    private function full(PastRecord $record): array
    {
        $record->load(['patient', 'pages', 'results', 'creator:id,name', 'checker:id,name']);
        $draft = $record->draft ? PastRecordDraft::tidy($record->draft) : null;
        $onPaper = $draft['patient_name'] ?? '';

        return $this->present($record) + [
            'read_text' => $record->read_text,
            'draft' => $draft,
            'details' => $record->details,
            'results' => $record->results->map(fn ($r) => ['test' => $r->test_name, 'parameter' => $r->parameter, 'value' => $r->value, 'unit' => $r->unit, 'reference_range' => $r->reference_range, 'flag' => $r->flag])->values(),
            'facts' => PatientHealthItem::where('past_record_id', $record->id)->get(['id', 'kind', 'title', 'since', 'is_active']),
            'patient_allergies' => $record->patient->allergies,
            // A paper in someone else's name is the commonest filing mistake, so it is pointed out.
            'name_on_paper' => $onPaper !== '' && ! self::sameName($onPaper, $record->patient->name) ? $onPaper : null,
            'reading' => self::reading(),
            'known_tests' => self::hospitalTests()->map(fn ($t) => ['test' => $t['test'], 'parameter' => $t['parameter'], 'unit' => $t['unit']])->values(),
        ];
    }

    /** Two names are taken as the same person when they share a word of three letters or more. */
    private static function sameName(string $a, string $b): bool
    {
        $words = fn (string $name) => array_filter(preg_split('/[^a-z]+/', mb_strtolower($name)) ?: [], fn ($w) => strlen($w) >= 3 && ! in_array($w, ['mrs', 'miss', 'smt', 'sri', 'baby', 'master'], true));

        return (bool) array_intersect($words($a), $words($b));
    }

    /**
     * The values the hospital's own lab reports, so an old value can sit on the same line over time.
     *
     * @return Collection<int, array{test: string, parameter: string, unit: ?string}>
     */
    private static function hospitalTests(): Collection
    {
        return LabTestParameter::where('is_heading', false)->join('lab_tests', 'lab_tests.id', '=', 'lab_test_parameters.lab_test_id')
            ->orderBy('lab_tests.name')->orderBy('lab_test_parameters.position')
            ->get(['lab_tests.name as test', 'lab_test_parameters.name as parameter', 'lab_test_parameters.unit'])
            ->map(fn ($p) => ['test' => (string) $p->test, 'parameter' => (string) $p->parameter, 'unit' => $p->unit]);
    }

    /**
     * The test an old value is filed under. Where the hospital's lab measures the same thing under
     * one test only, and in the same unit, the hospital's name is used so the values line up.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<int, array{test: string, parameter: string, unit: ?string}>  $hospital
     */
    private static function testName(array $row, Collection $hospital): string
    {
        $same = fn (?string $a, ?string $b) => mb_strtolower(preg_replace('/\s+/', '', (string) $a)) === mb_strtolower(preg_replace('/\s+/', '', (string) $b));
        $typed = trim((string) ($row['test'] ?? ''));
        $matches = $hospital->filter(fn ($t) => $same($t['parameter'], $row['parameter']));

        if ($typed !== '' && ($exact = $matches->first(fn ($t) => $same($t['test'], $typed)))) {
            return $exact['test'];
        }
        $tests = $matches->pluck('test')->unique();
        if ($tests->count() === 1) {
            $unit = $matches->first()['unit'];
            if (blank($unit) || blank($row['unit'] ?? null) || $same($unit, $row['unit'])) {
                return $tests->first();
            }
        }

        return $typed !== '' ? $typed : 'Outside report';
    }
}
