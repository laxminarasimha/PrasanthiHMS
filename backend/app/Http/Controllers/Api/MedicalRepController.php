<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\MedicalRep;
use App\Models\RepCompany;
use App\Models\RepCompanyFile;
use App\Models\RepVisit;
use App\Models\Setting;
use App\Models\User;
use App\Support\PhoneNotice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Medical representatives calling on the doctors. The front desk keeps the list of companies and their people and
 * logs each one as they arrive; the doctor sees who is waiting for them and writes what was spoken about.
 *
 * None of it touches a patient: a representative has no token, no bill, and is in no patient report. The front desk
 * sees every visit; a doctor sees their own; the pharmacy may read the companies and their catalogues.
 */
class MedicalRepController extends Controller
{
    private const VISIT_WITH = ['rep:id,rep_company_id,name,mobile,division', 'rep.company:id,name', 'doctor:id,name', 'logger:id,name'];

    // ---- Visits ----

    /** The representatives who came on one day. A doctor is shown their own; the desk and an admin, everyone's. */
    public function visits(Request $request): JsonResponse
    {
        $user = $this->staff($request);
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $day = Carbon::parse($data['date'] ?? today());

        return response()->json([
            'date' => $day->toDateString(),
            'visits' => $this->mine(RepVisit::query(), $user)->whereDate('arrived_at', $day)->with(self::VISIT_WITH)->orderBy('arrived_at')->get(),
            'hours' => self::hours(),
            'can_log' => $user->allows('canWorkFrontDesk'),
        ]);
    }

    /** Who is waiting for the doctor signed in, today: the short list on their own screen. */
    public function waiting(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->allows('canConsult') || $user->allows('canWorkFrontDesk'), 403, 'You are not allowed to see medical representatives.');

        return response()->json(
            RepVisit::where('doctor_id', $user->id)->where('status', 'waiting')->whereDate('arrived_at', today())->with(self::VISIT_WITH)->orderBy('arrived_at')->get()
        );
    }

    /** The desk logs a representative as they arrive, for the doctor they came to meet. */
    public function logVisit(Request $request): JsonResponse
    {
        $user = $this->desk($request);
        $data = $request->validate([
            'medical_rep_id' => ['required', Rule::exists('medical_reps', 'id')->where('is_active', true)],
            'doctor_id' => ['required', Rule::exists('users', 'id')->where('role', Role::Doctor->value)->where('is_active', true)],
            'purpose' => ['nullable', 'string', 'max:255'],
            // Sent once the desk has read that the doctor does not see representatives at this hour, and logs it anyway.
            'anyway' => ['sometimes', 'boolean'],
        ]);

        $today = RepVisit::where('doctor_id', $data['doctor_id'])->whereDate('arrived_at', today())->get(['id', 'medical_rep_id', 'status']);
        if ($today->where('medical_rep_id', (int) $data['medical_rep_id'])->where('status', 'waiting')->isNotEmpty()) {
            throw ValidationException::withMessages(['medical_rep_id' => 'This representative is already on the waiting list for this doctor.']);
        }
        if (! $request->boolean('anyway') && ($why = self::outsideHours((int) $data['doctor_id'], $today->count()))) {
            throw ValidationException::withMessages(['outside_hours' => $why]);
        }

        $visit = RepVisit::create([
            'branch_id' => $user->branch_id, 'medical_rep_id' => $data['medical_rep_id'], 'doctor_id' => $data['doctor_id'],
            'arrived_at' => now(), 'status' => 'waiting', 'purpose' => $data['purpose'] ?? null, 'created_by' => $user->id,
        ])->load(self::VISIT_WITH);

        if ((int) $data['doctor_id'] !== $user->id) {
            PhoneNotice::later((int) $data['doctor_id'], [
                'title' => 'Medical representative waiting', 'body' => $visit->rep->name.' · '.$visit->rep->company->name,
                'url' => '/reps', 'tag' => "rep-visit-{$visit->id}", 'kind' => 'rep',
            ]);
        }

        return response()->json($visit, 201);
    }

    /**
     * What became of a visit, and what was spoken about. The desk, the doctor it was for, or an admin may write it;
     * a doctor never changes another doctor's.
     */
    public function updateVisit(Request $request, RepVisit $visit): JsonResponse
    {
        $user = $this->staff($request);
        abort_unless($this->mine(RepVisit::whereKey($visit->id), $user)->exists(), 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(RepVisit::STATUSES)],
            'purpose' => ['nullable', 'string', 'max:255'],
            'discussed' => ['nullable', 'string', 'max:5000'],
            'offers' => ['nullable', 'string', 'max:5000'],
            'next_on' => ['nullable', 'date', 'after_or_equal:'.$visit->arrived_at->toDateString(), 'required_if:status,come_back'],
        ], ['next_on.required_if' => 'Say which day the representative should come back.', 'next_on.after_or_equal' => 'The next day cannot be before this visit.']);

        $closing = $data['status'] !== 'waiting';
        $visit->update($data + [
            'closed_at' => $closing ? ($visit->status === 'waiting' ? now() : $visit->closed_at) : null,
            'closed_by' => $closing ? ($visit->status === 'waiting' ? $user->id : $visit->closed_by) : null,
        ]);

        return response()->json($visit->load(self::VISIT_WITH));
    }

    /** A visit logged by mistake is taken off, while nothing has been written on it. */
    public function removeVisit(Request $request, RepVisit $visit): JsonResponse
    {
        $this->desk($request);
        if ($visit->status !== 'waiting' || filled($visit->discussed) || filled($visit->offers)) {
            throw ValidationException::withMessages(['visit' => 'This visit has been written up, so it stays on record. Change its status instead.']);
        }
        $visit->delete();

        return response()->json(null, 204);
    }

    // ---- Representatives ----

    /** The representatives, found by their name, mobile or company, with when each last came. */
    public function reps(Request $request): JsonResponse
    {
        $this->reader($request);
        $term = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));

        return response()->json(
            MedicalRep::query()
                ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', 'like', "%{$term}%")->orWhere('mobile', 'like', "{$term}%")
                    ->orWhere('division', 'like', "%{$term}%")->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', "%{$term}%"))))
                ->with(['company:id,name', 'lastVisit:id,medical_rep_id,doctor_id,arrived_at,status', 'lastVisit.doctor:id,name'])
                ->orderByDesc('is_active')->orderBy('name')->limit(300)->get()
        );
    }

    /** One representative with everything they came for: the desk and an admin see all of it, a doctor their own meetings. */
    public function showRep(Request $request, MedicalRep $rep): JsonResponse
    {
        $user = $this->reader($request);
        $visits = $user->allows('canWorkFrontDesk') || $user->allows('canConsult')
            ? $this->mine($rep->visits(), $user)->with(['doctor:id,name', 'logger:id,name'])->limit(100)->get()
            : collect();

        return response()->json(['rep' => $rep->load('company:id,name'), 'visits' => $visits]);
    }

    public function storeRep(Request $request): JsonResponse
    {
        $user = $this->desk($request);
        $data = $this->repData($request);
        $rep = MedicalRep::create($data + ['created_by' => $user->id]);

        return response()->json($rep->load('company:id,name'), 201);
    }

    public function updateRep(Request $request, MedicalRep $rep): JsonResponse
    {
        $this->desk($request);
        $rep->update($this->repData($request, $rep));

        return response()->json($rep->load('company:id,name'));
    }

    /**
     * A representative's details. The company is chosen from the list, or typed: a name not on the list yet is added to it.
     *
     * @return array<string, mixed>
     */
    private function repData(Request $request, ?MedicalRep $rep = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'company' => ['required', 'string', 'min:2', 'max:255'],
            'mobile' => ['nullable', 'string', 'regex:/^[0-9+ -]{6,20}$/'],
            'division' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'manager_mobile' => ['nullable', 'string', 'regex:/^[0-9+ -]{6,20}$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['mobile.regex' => 'Enter the mobile number in digits.', 'manager_mobile.regex' => 'Enter the manager’s mobile number in digits.']);

        $name = trim(preg_replace('/\s+/', ' ', $data['company']));
        $company = RepCompany::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        // The same person is not put on the list twice: same mobile, or the same name with the same company.
        $twin = MedicalRep::where('id', '!=', $rep?->id ?? 0)->where(fn (Builder $q) => $q
            ->when(filled($data['mobile'] ?? null), fn (Builder $m) => $m->where('mobile', $data['mobile']))
            ->orWhere(fn (Builder $n) => $n->where('rep_company_id', $company?->id ?? 0)->whereRaw('lower(name) = ?', [mb_strtolower(trim($data['name']))])))->with('company:id,name')->first();
        if ($twin) {
            throw ValidationException::withMessages(['name' => "{$twin->name} of {$twin->company->name} is already on the list".($twin->mobile ? " ({$twin->mobile})" : '').'. Choose them from the list.']);
        }
        // A company not on the list yet is added only once everything else is in order.
        $company ??= RepCompany::create(['name' => $name, 'created_by' => $request->user()->id]);
        unset($data['company']);

        return ['rep_company_id' => $company->id, 'name' => trim($data['name'])] + $data;
    }

    // ---- Companies and their catalogues ----

    public function companies(Request $request): JsonResponse
    {
        $this->reader($request);

        return response()->json(
            RepCompany::withCount('reps')->with(['reps:id,rep_company_id,name,mobile,division,is_active', 'files.uploader:id,name'])
                ->withMax('visits as last_visit_at', 'arrived_at')->orderBy('name')->get()
        );
    }

    public function updateCompany(Request $request, RepCompany $company): JsonResponse
    {
        $this->desk($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255', Rule::unique('rep_companies', 'name')->ignore($company->id)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['name.unique' => 'Another company on the list has this name.']);
        $company->update($data);

        return response()->json($company);
    }

    /** A catalogue, price list or leaflet the representative handed over, kept with the company. */
    public function storeFile(Request $request, RepCompany $company): JsonResponse
    {
        $user = $this->desk($request);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,xls,xlsx,csv'],
        ], ['file.mimes' => 'Attach a PDF, a picture (JPG, PNG or WebP) or a sheet (Excel or CSV).', 'file.max' => 'The file must be 20 MB or smaller.']);

        $file = $request->file('file');
        $company->files()->create([
            'title' => filled($data['title'] ?? null) ? $data['title'] : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'path' => $file->store("rep-files/{$company->id}", 'local'),
            'original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => $user->id,
        ]);

        return response()->json($company->load('files.uploader:id,name'), 201);
    }

    public function downloadFile(Request $request, RepCompanyFile $file): StreamedResponse
    {
        $this->reader($request);
        abort_unless(Storage::disk('local')->exists($file->path), 404, 'The file is missing.');

        return Storage::disk('local')->response($file->path, $file->original_name, ['Content-Type' => $file->mime]);
    }

    public function removeFile(Request $request, RepCompanyFile $file): JsonResponse
    {
        $this->desk($request);
        Storage::disk('local')->delete($file->path);
        $file->delete();

        return response()->json(null, 204);
    }

    // ---- The hours a doctor sees representatives ----

    /** A doctor sets the hours they see representatives and how many in a day; an admin may set it for any doctor. */
    public function saveHours(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'doctor_id' => ['required', Rule::exists('users', 'id')->where('role', Role::Doctor->value)],
            'from' => ['nullable', 'date_format:H:i', 'required_with:to'],
            'to' => ['nullable', 'date_format:H:i', 'required_with:from', 'after:from'],
            'most' => ['nullable', 'integer', 'min:1', 'max:50'],
        ], ['to.after' => 'The closing time must be after the opening time.']);
        abort_unless($user->role === Role::Admin || ((int) $data['doctor_id'] === $user->id && $user->allows('canConsult')), 403, 'Only the doctor, or an admin, sets these hours.');

        $all = self::hours();
        $own = array_filter(['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null, 'most' => isset($data['most']) ? (int) $data['most'] : null], fn ($v) => $v !== null);
        if ($own) {
            $all[(string) $data['doctor_id']] = $own;
        } else {
            unset($all[(string) $data['doctor_id']]);
        }
        Setting::updateOrCreate(['key' => 'rep_hours'], ['value' => $all]);

        return response()->json(['hours' => $all ?: (object) []]);
    }

    /**
     * Each doctor's own hours for representatives, by the doctor's id. A doctor with none set sees them at any time.
     *
     * @return array<string, array{from?: string, to?: string, most?: int}>
     */
    public static function hours(): array
    {
        return Setting::find('rep_hours')?->value ?? [];
    }

    /** Why a representative arriving now is outside what the doctor has set, or nothing when it is within it. */
    private static function outsideHours(int $doctorId, int $comeToday): ?string
    {
        $rule = self::hours()[(string) $doctorId] ?? null;
        if (! $rule) {
            return null;
        }
        $doctor = User::find($doctorId)?->name ?? 'This doctor';
        $now = now()->format('H:i');
        if (isset($rule['from'], $rule['to']) && ($now < $rule['from'] || $now > $rule['to'])) {
            $clock = fn (string $t) => Carbon::createFromFormat('H:i', $t)->format('g:i A');

            return "{$doctor} sees medical representatives from {$clock($rule['from'])} to {$clock($rule['to'])}. It is ".now()->format('g:i A').' now.';
        }
        if (isset($rule['most']) && $comeToday >= $rule['most']) {
            return "{$doctor} sees at most {$rule['most']} medical representatives in a day, and {$comeToday} have come today.";
        }

        return null;
    }

    // ---- Who may do what ----

    /** Visits are for the front desk and the doctors. */
    private function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->allows('canWorkFrontDesk') || $user->allows('canConsult'), 403, 'You are not allowed to see medical representatives’ visits.');

        return $user;
    }

    /** The list of companies and people is kept by the front desk. */
    private function desk(Request $request): User
    {
        abort_unless($request->user()->allows('canWorkFrontDesk'), 403, 'The front desk keeps the list of medical representatives and logs their visits.');

        return $request->user();
    }

    /** Companies, their people and their catalogues may also be read by the pharmacy. */
    private function reader(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->allows('canWorkFrontDesk') || $user->allows('canConsult') || $user->allows('canRunPharmacy') || $user->allows('canViewPharmacy'), 403, 'You are not allowed to see medical representatives.');

        return $user;
    }

    /** A doctor is shown only the visits made to them; the desk and an admin see every doctor's. */
    private function mine($query, User $user)
    {
        return $user->role === Role::Doctor ? $query->where('doctor_id', $user->id) : $query;
    }
}
