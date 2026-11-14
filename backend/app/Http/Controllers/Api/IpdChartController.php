<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\AdmissionDose;
use App\Models\AdmissionFluid;
use App\Models\AdmissionOrder;
use App\Models\AdmissionVital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The bedside chart of an inpatient: observations, the doctor's orders, the record of medicines
 * given, and fluids in and out. Doctors order; doctors and nurses chart. Nothing here is edited
 * afterwards: an entry made by mistake can be removed the same day by the person who made it.
 */
class IpdChartController extends Controller
{
    public function show(Request $request, Admission $admission): JsonResponse
    {
        $this->mustChart($request);

        return response()->json($this->present($admission));
    }

    // ---- Observations ----

    public function storeVitals(Request $request, Admission $admission): JsonResponse
    {
        $this->mustChart($request);
        $this->mustBeAdmitted($admission);
        $data = $request->validate([
            'recorded_at' => $this->when($admission),
            'temperature_f' => ['nullable', 'numeric', 'between:85,112'],
            'pulse' => ['nullable', 'integer', 'between:20,250'],
            'bp_systolic' => ['nullable', 'integer', 'between:40,300', 'required_with:bp_diastolic'],
            'bp_diastolic' => ['nullable', 'integer', 'between:20,200', 'required_with:bp_systolic'],
            'resp_rate' => ['nullable', 'integer', 'between:4,80'],
            'spo2' => ['nullable', 'integer', 'between:40,100'],
            'blood_sugar' => ['nullable', 'integer', 'between:10,1500'],
            'pain_score' => ['nullable', 'integer', 'between:0,10'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['bp_systolic.required_with' => 'Enter both blood pressure values.', 'bp_diastolic.required_with' => 'Enter both blood pressure values.'] + $this->whenMessages(), [
            'temperature_f' => 'temperature', 'spo2' => 'SpO₂', 'resp_rate' => 'respiratory rate', 'blood_sugar' => 'blood sugar', 'pain_score' => 'pain score',
        ]);
        if (! collect($data)->only(AdmissionVital::READINGS)->filter(fn ($v) => $v !== null && $v !== '')->count()) {
            throw ValidationException::withMessages(['recorded_at' => 'Enter at least one reading.']);
        }

        AdmissionVital::create($data + ['admission_id' => $admission->id, 'recorded_by' => $request->user()->id]);

        return response()->json($this->present($admission), 201);
    }

    public function destroyVitals(Request $request, Admission $admission, AdmissionVital $vital): JsonResponse
    {
        $this->mustChart($request);
        abort_unless($vital->admission_id === $admission->id, 404);
        $this->mustBeOwnAndRecent($request, $vital, $vital->recorded_by);
        $vital->delete();

        return response()->json($this->present($admission));
    }

    // ---- Doctor's orders and the medicine chart ----

    public function storeOrder(Request $request, Admission $admission): JsonResponse
    {
        $this->mustOrder($request);
        $this->mustBeAdmitted($admission);
        $data = $request->validate([
            'kind' => ['required', Rule::in(AdmissionOrder::KINDS)],
            'title' => ['required', 'string', 'max:255'],
            'dose' => ['nullable', 'string', 'max:60'],
            'route' => ['nullable', 'string', 'max:30'],
            'frequency' => ['nullable', 'string', 'max:60'],
            'instructions' => ['nullable', 'string', 'max:500'],
        ], ['title.required' => 'Write the medicine or the instruction.']);

        AdmissionOrder::create($data + ['admission_id' => $admission->id, 'started_at' => now(), 'ordered_by' => $request->user()->id]);

        return response()->json($this->present($admission), 201);
    }

    /** Stops an order. It stays on the chart, with who stopped it and when. */
    public function stopOrder(Request $request, Admission $admission, AdmissionOrder $order): JsonResponse
    {
        $this->mustOrder($request);
        abort_unless($order->admission_id === $admission->id, 404);
        if ($order->stopped_at) {
            throw ValidationException::withMessages(['order' => 'This order was already stopped.']);
        }
        $order->update(['stopped_at' => now(), 'stopped_by' => $request->user()->id]);

        return response()->json($this->present($admission));
    }

    public function storeDose(Request $request, Admission $admission, AdmissionOrder $order): JsonResponse
    {
        $this->mustChart($request);
        $this->mustBeAdmitted($admission);
        abort_unless($order->admission_id === $admission->id, 404);
        if ($order->kind !== 'medicine') {
            throw ValidationException::withMessages(['order' => 'Only a medicine order has doses to record.']);
        }
        if ($order->stopped_at) {
            throw ValidationException::withMessages(['order' => 'This medicine was stopped. It cannot be given.']);
        }
        $data = $request->validate([
            'given_at' => $this->when($admission),
            'status' => ['required', Rule::in(AdmissionDose::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => in_array($request->input('status'), ['held', 'refused'], true))],
        ], ['remarks.required' => 'Say why the dose was not given.'] + $this->whenMessages('given_at'));

        // The same dose recorded twice in a row is the commonest slip on a medicine chart.
        $last = $order->doses()->where('status', 'given')->first();
        if ($data['status'] === 'given' && $last && abs($last->given_at->diffInMinutes($data['given_at'])) < 30 && ! $request->boolean('confirm_repeat')) {
            throw ValidationException::withMessages(['given_at' => 'This medicine was recorded as given at '.$last->given_at->format('g:i A').', less than 30 minutes from this time. Record it again only if a second dose really was given.']);
        }

        $order->doses()->create($data + ['given_by' => $request->user()->id]);

        return response()->json($this->present($admission), 201);
    }

    public function destroyDose(Request $request, Admission $admission, AdmissionDose $dose): JsonResponse
    {
        $this->mustChart($request);
        abort_unless($dose->order->admission_id === $admission->id, 404);
        $this->mustBeOwnAndRecent($request, $dose, $dose->given_by);
        $dose->delete();

        return response()->json($this->present($admission));
    }

    // ---- Fluids in and out ----

    public function storeFluid(Request $request, Admission $admission): JsonResponse
    {
        $this->mustChart($request);
        $this->mustBeAdmitted($admission);
        $data = $request->validate([
            'recorded_at' => $this->when($admission),
            'direction' => ['required', Rule::in(['in', 'out'])],
            'kind' => ['required', 'string', 'max:30'],
            'amount_ml' => ['required', 'integer', 'between:1,10000'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['amount_ml.between' => 'Enter the amount in millilitres, from 1 to 10,000.'] + $this->whenMessages());

        AdmissionFluid::create($data + ['admission_id' => $admission->id, 'recorded_by' => $request->user()->id]);

        return response()->json($this->present($admission), 201);
    }

    public function destroyFluid(Request $request, Admission $admission, AdmissionFluid $fluid): JsonResponse
    {
        $this->mustChart($request);
        abort_unless($fluid->admission_id === $admission->id, 404);
        $this->mustBeOwnAndRecent($request, $fluid, $fluid->recorded_by);
        $fluid->delete();

        return response()->json($this->present($admission));
    }

    // ---- Helpers ----

    /**
     * @return array<string, mixed>
     */
    private function present(Admission $admission): array
    {
        $fluids = AdmissionFluid::where('admission_id', $admission->id)->with('recorder:id,name')->orderByDesc('recorded_at')->orderByDesc('id')->limit(500)->get();

        return [
            'open' => $admission->status === 'admitted',
            'vitals' => AdmissionVital::where('admission_id', $admission->id)->with('recorder:id,name')->orderByDesc('recorded_at')->orderByDesc('id')->limit(300)->get(),
            // Running orders first, then stopped ones.
            'orders' => AdmissionOrder::where('admission_id', $admission->id)->with(['orderer:id,name', 'stopper:id,name', 'doses.giver:id,name'])
                ->orderByRaw('stopped_at is not null')->orderBy('kind', 'desc')->orderBy('id')->get(),
            'fluids' => $fluids,
            // One line a day: taken in, passed out, and the difference.
            'fluid_days' => $fluids->groupBy(fn (AdmissionFluid $f) => $f->recorded_at->toDateString())->map(function ($day, $date) {
                $in = (int) $day->where('direction', 'in')->sum('amount_ml');
                $out = (int) $day->where('direction', 'out')->sum('amount_ml');

                return ['date' => $date, 'in' => $in, 'out' => $out, 'balance' => $in - $out];
            })->values(),
            'fluid_kinds' => AdmissionFluid::KINDS,
        ];
    }

    /**
     * The time something happened at the bedside: not in the future, and not before the patient came in.
     *
     * @return list<string>
     */
    private function when(Admission $admission): array
    {
        return ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->format('Y-m-d H:i:s'), 'after_or_equal:'.$admission->admitted_at->format('Y-m-d H:i:s')];
    }

    /**
     * @return array<string, string>
     */
    private function whenMessages(string $field = 'recorded_at'): array
    {
        return ["{$field}.before_or_equal" => 'The time cannot be in the future.', "{$field}.after_or_equal" => 'The time cannot be before the patient was admitted.'];
    }

    private function mustChart(Request $request): void
    {
        abort_unless($request->user()->allows('canConsult'), 403, 'Only doctors and nurses can open or write the bedside chart.');
    }

    private function mustOrder(Request $request): void
    {
        abort_unless($request->user()->allows('canApproveIpd'), 403, 'Only a doctor can write or stop an order.');
    }

    private function mustBeAdmitted(Admission $admission): void
    {
        if ($admission->status !== 'admitted') {
            throw ValidationException::withMessages(['admission' => 'This patient is no longer admitted, so the chart is closed.']);
        }
    }

    private function mustBeOwnAndRecent(Request $request, Model $entry, ?int $madeBy): void
    {
        if ($madeBy !== $request->user()->id || $entry->created_at->lt(today())) {
            throw ValidationException::withMessages(['entry' => 'An entry can be removed only on the day it was made, by the person who made it.']);
        }
    }
}
