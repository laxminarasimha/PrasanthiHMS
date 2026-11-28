<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\RepProduct;
use App\Models\RepSample;
use App\Models\RepSampleIssue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The register of samples left by medical representatives: what came in, what was given out or thrown away, and
 * what is still on the shelf. Samples are not for sale, so nothing here touches the pharmacy's stock or any bill.
 *
 * Kept by the front desk and the doctors. A doctor sees the samples left for them and those left for nobody in
 * particular; the desk and an admin see all.
 */
class RepSampleController extends Controller
{
    private const WITH = ['company:id,name', 'rep:id,name,mobile', 'doctor:id,name', 'issues.patient:id,uhid,title,name', 'issues.giver:id,name'];

    public function index(Request $request): JsonResponse
    {
        $user = $this->staff($request);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'show' => ['nullable', Rule::in(['in_hand', 'all'])]]);
        $term = trim((string) ($data['q'] ?? ''));

        $samples = $this->mine(RepSample::query(), $user)
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', 'like', "%{$term}%")->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', "%{$term}%"))))
            ->with(self::WITH)->orderByDesc('received_on')->orderByDesc('id')->limit(500)->get();
        if (($data['show'] ?? 'in_hand') === 'in_hand') {
            $samples = $samples->filter(fn (RepSample $s) => $s->in_hand > 0)->values();
        }

        return response()->json(['today' => today()->toDateString(), 'samples' => $samples]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->staff($request);
        $sample = RepSample::create($this->sampleData($request) + ['branch_id' => $user->branch_id, 'created_by' => $user->id]);

        return response()->json($sample->load(self::WITH), 201);
    }

    public function update(Request $request, RepSample $sample): JsonResponse
    {
        $this->own($request, $sample);
        $data = $this->sampleData($request);
        $out = $sample->given + $sample->discarded;
        if ($data['quantity'] < $out) {
            throw ValidationException::withMessages(['quantity' => "{$out} of these have already left the shelf, so the number received cannot be less."]);
        }
        $sample->update($data);

        return response()->json($sample->fresh()->load(self::WITH));
    }

    /** A lot entered by mistake is taken off, while none of it has been given out. */
    public function destroy(Request $request, RepSample $sample): JsonResponse
    {
        $this->own($request, $sample);
        if ($sample->issues()->exists()) {
            throw ValidationException::withMessages(['sample' => 'Some of these have been given out or thrown away, so the entry stays on record.']);
        }
        $sample->delete();

        return response()->json(null, 204);
    }

    /** Some of a lot leaves the shelf: given to a patient or someone else, or thrown away. */
    public function issue(Request $request, RepSample $sample): JsonResponse
    {
        $user = $this->own($request, $sample);
        $data = $request->validate([
            'kind' => ['required', Rule::in(RepSampleIssue::KINDS)],
            'quantity' => ['required', 'integer', 'min:1'],
            'given_on' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:'.$sample->received_on->toDateString()],
            'patient_id' => ['nullable', Rule::exists('patients', 'id')],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['given_on.after_or_equal' => 'That is before the samples came in.', 'given_on.before_or_equal' => 'The date cannot be after today.']);
        $on = Carbon::parse($data['given_on'] ?? today());

        DB::transaction(function () use ($sample, $data, $on, $user) {
            // Held until saved, so two people cannot give out the same last strip.
            $sample = RepSample::whereKey($sample->id)->lockForUpdate()->firstOrFail();
            if ($data['quantity'] > $sample->in_hand) {
                throw ValidationException::withMessages(['quantity' => $sample->in_hand > 0 ? "Only {$sample->in_hand} are left." : 'None of these are left.']);
            }
            if ($data['kind'] === 'given' && $sample->expiry && $sample->expiry->lt($on->copy()->startOfDay())) {
                throw ValidationException::withMessages(['kind' => 'These expired on '.$sample->expiry->format('j M Y').'. They can only be thrown away.']);
            }
            $sample->issues()->create(['kind' => $data['kind'], 'quantity' => $data['quantity'], 'given_on' => $on->toDateString(), 'patient_id' => $data['patient_id'] ?? null, 'note' => $data['note'] ?? null, 'given_by' => $user->id]);
        });

        return response()->json($sample->fresh()->load(self::WITH), 201);
    }

    /** An entry of giving out made by mistake is taken back, and the samples are on the shelf again. */
    public function removeIssue(Request $request, RepSampleIssue $issue): JsonResponse
    {
        $this->own($request, $issue->sample);
        $sample = $issue->sample;
        $issue->delete();

        return response()->json($sample->fresh()->load(self::WITH));
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleData(Request $request): array
    {
        $data = $request->validate([
            'rep_company_id' => ['required', Rule::exists('rep_companies', 'id')],
            'medical_rep_id' => ['nullable', Rule::exists('medical_reps', 'id')->where('rep_company_id', $request->input('rep_company_id'))],
            'rep_visit_id' => ['nullable', Rule::exists('rep_visits', 'id')],
            'doctor_id' => ['nullable', Rule::exists('users', 'id')->where('role', Role::Doctor->value)],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'unit' => ['nullable', 'string', 'max:40'],
            'expiry' => ['nullable', 'date'],
            'received_on' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.today()->subYears(2)->toDateString()],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['medical_rep_id.exists' => 'That representative is not from this company.', 'received_on.before_or_equal' => 'The date cannot be after today.']);
        $data['name'] = trim($data['name']);
        // A brand that is in the company's catalogue is tied to it, so the two can be read together.
        $data['rep_product_id'] = RepProduct::where('rep_company_id', $data['rep_company_id'])->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])->value('id');

        return $data;
    }

    private function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->allows('canWorkFrontDesk') || $user->allows('canConsult'), 403, 'The samples register is kept by the front desk and the doctors.');

        return $user;
    }

    /** As `staff`, and for a doctor only the samples that are theirs to handle. */
    private function own(Request $request, RepSample $sample): User
    {
        $user = $this->staff($request);
        abort_unless($this->mine(RepSample::whereKey($sample->id), $user)->exists(), 404);

        return $user;
    }

    /** A doctor is shown the samples left for them, and those left for nobody in particular. */
    private function mine(Builder $query, User $user): Builder
    {
        return $user->role === Role::Doctor ? $query->where(fn (Builder $q) => $q->whereNull('doctor_id')->orWhere('doctor_id', $user->id)) : $query;
    }
}
