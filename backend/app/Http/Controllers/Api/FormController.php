<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\FormRecord;
use App\Models\FormTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Forms the hospital designs itself, and the copies filled in for patients. */
class FormController extends Controller
{
    private const RECORD_WITH = ['author:id,name', 'patient:id,uhid,title,name,gender,date_of_birth,mobile', 'admission:id,ipd_no,admitted_at,doctor_id', 'admission.doctor:id,name'];

    // ---- Templates ----

    public function templates(Request $request): JsonResponse
    {
        return response()->json(FormTemplate::when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))->orderBy('name')->get());
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        return response()->json(FormTemplate::create($this->templateData($request)), 201);
    }

    public function updateTemplate(Request $request, FormTemplate $template): JsonResponse
    {
        $template->update($this->templateData($request, $template));

        return response()->json($template);
    }

    /**
     * @return array<string, mixed>
     */
    private function templateData(Request $request, ?FormTemplate $template = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('form_templates')->ignore($template)],
            'access' => ['required', Rule::in(['doctor', 'ipd', 'both'])],
            'is_active' => ['sometimes', 'boolean'],
            'fields' => ['required', 'array', 'min:1', 'max:120'],
            'fields.*.id' => ['required', 'string', 'max:40', 'distinct', 'regex:/^[a-z0-9_]+$/'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.type' => ['required', Rule::in(FormTemplate::TYPES)],
            'fields.*.options' => ['nullable', 'array', 'max:30'],
            'fields.*.options.*' => ['string', 'max:100'],
        ], [
            'name.unique' => 'There is already a form with this name.',
            'fields.required' => 'Add at least one line to the form.',
            'fields.*.label.required' => 'Give every line a label or remove the empty one.',
        ]);

        foreach ($data['fields'] as $index => $field) {
            if ($field['type'] === 'choice' && count(array_filter($field['options'] ?? [], 'filled')) < 2) {
                throw ValidationException::withMessages(["fields.{$index}.options" => "\"{$field['label']}\" needs at least two choices."]);
            }
        }
        $data['fields'] = array_map(fn ($f) => ['id' => $f['id'], 'label' => trim($f['label']), 'type' => $f['type'], 'options' => $f['type'] === 'choice' ? array_values(array_filter($f['options'], 'filled')) : null], $data['fields']);

        return $data;
    }

    // ---- Filled-in forms ----

    public function forAdmission(Admission $admission): JsonResponse
    {
        return response()->json(FormRecord::where('admission_id', $admission->id)->with('author:id,name')->latest('id')->get());
    }

    public function show(FormRecord $record): JsonResponse
    {
        return response()->json($record->load(self::RECORD_WITH));
    }

    public function store(Request $request, Admission $admission): JsonResponse
    {
        $data = $request->validate(['form_template_id' => ['required', Rule::exists('form_templates', 'id')->where('is_active', true)], 'values' => ['nullable', 'array']]);
        $template = FormTemplate::findOrFail($data['form_template_id']);
        if ($template->access === 'doctor') {
            throw ValidationException::withMessages(['form_template_id' => 'This form is for the doctor\'s room, not for IPD cases.']);
        }

        $record = FormRecord::create([
            'form_template_id' => $template->id, 'patient_id' => $admission->patient_id, 'admission_id' => $admission->id,
            'name' => $template->name, 'fields' => $template->fields, 'values' => $this->values($template->fields, $data['values'] ?? []),
            'created_by' => $request->user()->id,
        ]);

        return response()->json($record->load(self::RECORD_WITH), 201);
    }

    public function update(Request $request, FormRecord $record): JsonResponse
    {
        $data = $request->validate(['values' => ['present', 'array']]);
        $record->update(['values' => $this->values($record->fields, $data['values']), 'updated_by' => $request->user()->id]);

        return response()->json($record->load(self::RECORD_WITH));
    }

    public function destroy(FormRecord $record): JsonResponse
    {
        $record->delete();

        return response()->json(null, 204);
    }

    /**
     * Keeps only answers to the form's own questions, in the right shape for each kind of box.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $given
     * @return array<string, mixed>
     */
    private function values(array $fields, array $given): array
    {
        $values = [];
        foreach ($fields as $field) {
            $answer = $given[$field['id']] ?? null;
            if (in_array($field['type'], ['heading', 'signature'], true) || $answer === null || $answer === '') {
                continue;
            }
            if ($field['type'] === 'checkbox') {
                $values[$field['id']] = (bool) $answer;

                continue;
            }
            if (! is_scalar($answer) || mb_strlen((string) $answer) > 10000) {
                throw ValidationException::withMessages(["values.{$field['id']}" => "\"{$field['label']}\" is too long."]);
            }
            if ($field['type'] === 'choice' && ! in_array((string) $answer, $field['options'] ?? [], true)) {
                throw ValidationException::withMessages(["values.{$field['id']}" => "Choose one of the listed answers for \"{$field['label']}\"."]);
            }
            if ($field['type'] === 'number' && ! is_numeric($answer)) {
                throw ValidationException::withMessages(["values.{$field['id']}" => "\"{$field['label']}\" must be a number."]);
            }
            $values[$field['id']] = (string) $answer;
        }

        return $values;
    }
}
