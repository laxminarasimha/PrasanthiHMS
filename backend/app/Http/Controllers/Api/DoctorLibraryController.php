<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\MedicineDefault;
use App\Models\PrescriptionTemplate;
use App\Models\RememberedPhrase;
use App\Models\RxGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * A doctor's own shortcuts: phrases they have typed before, sets of medicines, whole-visit
 * templates and how they usually dose each medicine. Each doctor has a separate library.
 */
class DoctorLibraryController extends Controller
{
    public const PHRASE_KINDS = ['complaint', 'diagnosis', 'advice'];

    /** The parts of the visit pad a doctor may switch off. */
    public const PAD_SECTIONS = ['quick_notes', 'history', 'past_medication', 'general_exam', 'systemic_exam', 'assessment_plan', 'tests', 'results', 'refer_to'];

    /** Every part of the visit pad that can be moved, in the standard order. */
    public const PAD_ORDER = ['quick_notes', 'vitals', 'complaints', 'history', 'past_medication', 'general_exam', 'systemic_exam', 'diagnosis', 'assessment_plan', 'tests', 'results', 'prescription', 'advice', 'follow_up'];

    /**
     * A doctor's saved order, with any part it does not mention put back where it normally sits.
     *
     * @param  array<int, string>|null  $saved
     * @return array<int, string>
     */
    public static function padOrder(?array $saved): array
    {
        $order = array_values(array_intersect($saved ?? [], self::PAD_ORDER));
        foreach (self::PAD_ORDER as $index => $key) {
            if (! in_array($key, $order, true)) {
                // After the nearest earlier part that is present, or first.
                $before = collect(array_slice(self::PAD_ORDER, 0, $index))->reverse()->first(fn ($k) => in_array($k, $order, true));
                array_splice($order, $before === null ? 0 : array_search($before, $order, true) + 1, 0, [$key]);
            }
        }

        return $order;
    }

    /** Completed consultations in a date range: the doctor's own, or everyone's for other roles. */
    public function consults(Request $request): JsonResponse
    {
        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'q' => ['nullable', 'string', 'max:100']]);
        $to = Carbon::parse($range['to'] ?? today())->endOfDay();
        $from = Carbon::parse($range['from'] ?? $to->copy()->subMonth())->startOfDay();
        $term = trim((string) ($range['q'] ?? ''));
        $user = $request->user();

        return response()->json(
            Consultation::whereBetween('completed_at', [$from, $to])
                ->when($user->role === Role::Doctor, fn ($q) => $q->where('doctor_id', $user->id))
                ->when($term !== '', fn ($q) => $q->whereHas('patient', fn ($p) => $p->where('name', 'like', "%{$term}%")
                    ->orWhere('mobile', 'like', "{$term}%")->orWhere('uhid', 'like', "{$term}%")))
                ->with(['patient:id,uhid,title,name,gender,date_of_birth,mobile', 'doctor:id,name'])
                ->latest('completed_at')->limit(500)
                ->get(['id', 'appointment_id', 'patient_id', 'doctor_id', 'diagnosis', 'follow_up_on', 'completed_at'])
        );
    }

    public function show(Request $request): JsonResponse
    {
        $doctorId = $this->owner($request);

        return response()->json([
            'doctor_id' => $doctorId,
            'phrases' => RememberedPhrase::where('doctor_id', $doctorId)->orderByDesc('uses')->orderBy('text')->limit(1500)->get()->groupBy('kind'),
            'rx_groups' => RxGroup::where('doctor_id', $doctorId)->orderBy('name')->get(),
            'templates' => PrescriptionTemplate::where('doctor_id', $doctorId)->orderBy('name')->get(),
            'medicine_defaults' => MedicineDefault::where('doctor_id', $doctorId)->get(['medicine', 'dose', 'frequency', 'timing', 'duration', 'instructions']),
            'pad_hidden_sections' => User::find($doctorId)?->pad_hidden_sections ?? [],
            'pad_sections' => self::PAD_SECTIONS,
            'pad_section_order' => self::padOrder(User::find($doctorId)?->pad_section_order),
        ]);
    }

    // ---- Remembered phrases ----

    public function storePhrase(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(self::PHRASE_KINDS)], 'text' => ['required', 'string', 'max:255']]);

        return response()->json(RememberedPhrase::firstOrCreate(['doctor_id' => $this->owner($request), 'kind' => $data['kind'], 'text' => trim($data['text'])]), 201);
    }

    public function updatePhrase(Request $request, RememberedPhrase $phrase): JsonResponse
    {
        $this->mine($request, $phrase->doctor_id);
        $phrase->update($request->validate(['text' => ['required', 'string', 'max:255']]));

        return response()->json($phrase);
    }

    public function destroyPhrases(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];

        return response()->json(['deleted' => RememberedPhrase::where('doctor_id', $this->owner($request))->whereIn('id', $ids)->delete()]);
    }

    // ---- Rx groups ----

    public function storeRxGroup(Request $request): JsonResponse
    {
        return response()->json(RxGroup::create($this->rxGroupData($request) + ['doctor_id' => $this->owner($request)]), 201);
    }

    public function updateRxGroup(Request $request, RxGroup $group): JsonResponse
    {
        $this->mine($request, $group->doctor_id);
        $group->update($this->rxGroupData($request));

        return response()->json($group);
    }

    public function destroyRxGroup(Request $request, RxGroup $group): JsonResponse
    {
        $this->mine($request, $group->doctor_id);
        $group->delete();

        return response()->json(['message' => 'Rx group deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rxGroupData(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:255']] + $this->medicineRules('medicines', 'required'), [
            'medicines.required' => 'Add at least one medicine to the group.',
            'medicines.*.medicine.required' => 'Name every medicine or remove the empty row.',
        ]);
    }

    // ---- Prescription templates ----

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'array'],
            'content.complaints' => ['nullable', 'string', 'max:5000'],
            'content.findings' => ['nullable', 'string', 'max:5000'],
            'content.diagnosis' => ['nullable', 'string', 'max:5000'],
            'content.assessment_plan' => ['nullable', 'string', 'max:5000'],
            'content.tests_advised' => ['nullable', 'string', 'max:5000'],
            'content.advice' => ['nullable', 'string', 'max:5000'],
        ] + $this->medicineRules('content.medicines', 'nullable'));

        return response()->json(PrescriptionTemplate::create($data + ['doctor_id' => $this->owner($request)]), 201);
    }

    public function destroyTemplate(Request $request, PrescriptionTemplate $template): JsonResponse
    {
        $this->mine($request, $template->doctor_id);
        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    // ---- Visit pad sections ----

    public function savePadSections(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hidden' => ['present', 'array'], 'hidden.*' => [Rule::in(self::PAD_SECTIONS)],
            'order' => ['sometimes', 'array'], 'order.*' => ['distinct', Rule::in(self::PAD_ORDER)],
        ]);
        $hidden = array_values(array_unique($data['hidden']));
        $ownerId = $this->owner($request);
        // Update the signed-in user's own record in place so the rest of this request sees the change.
        $owner = $ownerId === $request->user()->id ? $request->user() : User::findOrFail($ownerId);
        $owner->update(['pad_hidden_sections' => $hidden] + (isset($data['order']) ? ['pad_section_order' => self::padOrder($data['order'])] : []));

        return response()->json(['pad_hidden_sections' => $hidden, 'pad_section_order' => self::padOrder($owner->pad_section_order)]);
    }

    /**
     * Called when a visit is completed: remembers each line the doctor typed, and how each medicine was given.
     *
     * @param  array<int, array<string, mixed>>  $medicines
     */
    public static function learn(int $doctorId, Consultation $consultation, array $medicines): void
    {
        foreach (['complaint' => $consultation->complaints, 'diagnosis' => $consultation->diagnosis, 'advice' => $consultation->advice] as $kind => $text) {
            foreach (preg_split('/\R+/', (string) $text) as $line) {
                $line = trim($line);
                if ($line === '' || mb_strlen($line) > 255) {
                    continue;
                }
                $phrase = RememberedPhrase::firstOrCreate(['doctor_id' => $doctorId, 'kind' => $kind, 'text' => $line]);
                $phrase->update(['uses' => $phrase->uses + 1, 'last_used_at' => now()]);
            }
        }

        foreach ($medicines as $row) {
            $name = trim((string) ($row['medicine'] ?? ''));
            if ($name === '') {
                continue;
            }
            $default = MedicineDefault::firstOrNew(['doctor_id' => $doctorId, 'medicine' => $name]);
            $default->fill(array_intersect_key($row, array_flip(['dose', 'frequency', 'timing', 'duration', 'instructions'])) + ['uses' => $default->uses + 1])->save();
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function medicineRules(string $key, string $presence): array
    {
        return [
            $key => [$presence, 'array', 'max:40'],
            "{$key}.*.medicine" => ['required', 'string', 'max:255'],
            "{$key}.*.dose" => ['nullable', 'string', 'max:50'],
            "{$key}.*.frequency" => ['nullable', 'string', 'max:50'],
            "{$key}.*.timing" => ['nullable', 'string', 'max:30'],
            "{$key}.*.duration" => ['nullable', 'string', 'max:50'],
            "{$key}.*.instructions" => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Whose library this is: a doctor's own, or the doctor named by staff working on that doctor's visit. */
    private function owner(Request $request): int
    {
        $user = $request->user();
        abort_unless($user->allows('canConsult'), 403, 'You are not allowed to use the doctor library.');

        if ($user->role === Role::Doctor) {
            return $user->id;
        }

        $doctorId = (int) $request->input('doctor_id', $request->query('doctor_id', $user->id));
        abort_unless($doctorId === $user->id || User::whereKey($doctorId)->where('role', Role::Doctor->value)->exists(), 422, 'Choose a doctor.');

        return $doctorId;
    }

    private function mine(Request $request, int $doctorId): void
    {
        $user = $request->user();
        abort_unless($user->allows('canConsult') && ($user->role !== Role::Doctor || $user->id === $doctorId), 403, 'This belongs to another doctor.');
    }
}
