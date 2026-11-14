<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientHealthItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A patient's health summary: what stays true from visit to visit. It is read by anyone who
 * treats or tests the patient and changed by those who consult.
 */
class HealthSummaryController extends Controller
{
    public function show(Request $request, Patient $patient): JsonResponse
    {
        $this->guardRead($request);

        return response()->json($this->present($patient));
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        $this->guardWrite($request);
        $data = $this->data($request, true);

        // The same thing typed twice is one fact, not two.
        $twin = PatientHealthItem::where('patient_id', $patient->id)->where('kind', $data['kind'])->where('is_active', true)->get()
            ->first(fn (PatientHealthItem $i) => mb_strtolower(trim($i->title)) === mb_strtolower($data['title']));
        if ($twin) {
            throw ValidationException::withMessages(['title' => "“{$twin->title}” is already on the summary."]);
        }

        $patient->healthItems()->create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return response()->json($this->present($patient), 201);
    }

    public function update(Request $request, PatientHealthItem $item): JsonResponse
    {
        $this->guardWrite($request);
        $data = $this->data($request, false);
        // Marking something as over stamps the day, unless a day was given; bringing it back clears it.
        if (array_key_exists('is_active', $data)) {
            $data['ended_on'] = $data['is_active'] ? null : ($data['ended_on'] ?? $item->ended_on?->toDateString() ?? today()->toDateString());
        }
        $item->update($data + ['updated_by' => $request->user()->id]);

        return response()->json($this->present($item->patient));
    }

    public function destroy(Request $request, PatientHealthItem $item): JsonResponse
    {
        $this->guardWrite($request);
        $patient = $item->patient;
        $item->delete();

        return response()->json($this->present($patient));
    }

    /** Allergies live on the patient's own record, where bills and prints already read them. */
    public function allergies(Request $request, Patient $patient): JsonResponse
    {
        $this->guardWrite($request);
        $data = $request->validate(['allergies' => ['present', 'nullable', 'string', 'max:1000']]);
        $patient->update(['allergies' => filled($data['allergies']) ? trim($data['allergies']) : null]);

        return response()->json($this->present($patient));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Request $request, bool $creating): array
    {
        $need = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'kind' => [$creating ? 'required' : 'prohibited', Rule::in(array_keys(PatientHealthItem::KINDS))],
            'title' => [$need, 'string', 'max:255'],
            'detail' => ['sometimes', 'nullable', 'string', 'max:500'],
            'since' => ['sometimes', 'nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
            'ended_on' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
        ], ['title.required' => 'Type what to add.']);
        if (isset($data['title'])) {
            $data['title'] = trim($data['title']);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Patient $patient): array
    {
        $items = PatientHealthItem::where('patient_id', $patient->id)->with(['creator:id,name', 'editor:id,name'])
            ->orderByDesc('is_active')->orderBy('id')->get()->groupBy('kind');

        return [
            'patient_id' => $patient->id,
            'allergies' => $patient->fresh()->allergies,
            'sections' => collect(PatientHealthItem::KINDS)->map(fn ($label, $kind) => ['kind' => $kind, 'label' => $label, 'items' => ($items[$kind] ?? collect())->values()])->values(),
        ];
    }

    private function guardRead(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->allows('canConsult') || $user->allows('canRunLab'), 403, 'You are not allowed to see clinical notes.');
    }

    private function guardWrite(Request $request): void
    {
        abort_unless($request->user()->allows('canConsult'), 403, 'You are not allowed to change the health summary.');
    }
}
