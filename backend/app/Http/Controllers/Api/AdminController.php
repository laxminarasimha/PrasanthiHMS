<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminEntry;
use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientMerge;
use App\Models\Payment;
use App\Models\ReferralDoctor;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Hospital-wide setup: the clinic's own details for prints, outside doctors, and merging duplicate patients. */
class AdminController extends Controller
{
    public const CLINIC_DEFAULTS = ['name' => '', 'tagline' => '', 'address' => '', 'phone' => '', 'phone_alt' => '', 'email' => '', 'footer' => ''];

    /** Every patient-linked table, with the word used for its rows in the merge record. */
    private const PATIENT_TABLES = [
        'appointments' => 'appointments', 'bills' => 'bills', 'consultations' => 'consultations', 'lab_orders' => 'lab orders',
        'pharmacy_sales' => 'pharmacy bills', 'admissions' => 'admissions', 'attachments' => 'attachments',
        'past_records' => 'old paper records', 'past_record_results' => 'test values from old papers',
        'patient_deposits' => 'deposits', 'form_records' => 'forms', 'patient_health_items' => 'health summary items',
        'patient_accounts' => 'mobile app account', 'patient_account_members' => 'family links in the mobile app', 'medicine_subscriptions' => 'monthly medicine lists', 'patient_documents' => 'certificates and letters',
    ];

    // ---- Clinic details ----

    public function clinic(Request $request): JsonResponse
    {
        return response()->json($this->clinicDetails($request));
    }

    /**
     * The hospital's name and logo for the sign-in page, which is seen before anyone has signed in.
     * Nothing here is private: it is what is printed at the top of every bill.
     */
    public function brand(): JsonResponse
    {
        $settings = Setting::group('clinic', self::CLINIC_DEFAULTS);
        $path = $settings['logo_path'] ?? null;

        return response()->json([
            'name' => $settings['name'] ?: config('app.name'),
            'logo' => $path && Storage::disk('local')->exists($path)
                ? 'data:'.Storage::disk('local')->mimeType($path).';base64,'.base64_encode(Storage::disk('local')->get($path))
                : null,
            // For the "Sign in with Google" button; null while Google sign-in is not set up.
            'google_client_id' => config('services.google.web_client_id') ?: null,
        ]);
    }

    public function saveClinic(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'phone_alt' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'footer' => ['nullable', 'string', 'max:500'],
        ]);
        // Keep what this form does not send, such as where the logo is stored.
        Setting::updateOrCreate(['key' => 'clinic'], ['value' => array_merge(Setting::group('clinic', self::CLINIC_DEFAULTS), $data)]);

        return response()->json($this->clinicDetails($request));
    }

    public function saveLogo(Request $request): JsonResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'max:500', 'mimes:png,jpg,jpeg']],
            ['file.max' => 'The logo must be 500 KB or smaller.', 'file.mimes' => 'Use a PNG or JPG picture for the logo.'],
        );
        $this->replaceLogo($request->file('file')->store('clinic', 'local'));

        return response()->json($this->clinicDetails($request));
    }

    public function destroyLogo(Request $request): JsonResponse
    {
        $this->replaceLogo(null);

        return response()->json($this->clinicDetails($request));
    }

    private function replaceLogo(?string $path): void
    {
        $settings = Setting::group('clinic', self::CLINIC_DEFAULTS);
        if (! empty($settings['logo_path'])) {
            Storage::disk('local')->delete($settings['logo_path']);
        }
        $settings['logo_path'] = $path;
        Setting::updateOrCreate(['key' => 'clinic'], ['value' => $settings]);
    }

    /**
     * The details as the prints need them: the logo is sent as an embedded picture, and until a name
     * is saved the branch's own name and city stand in.
     *
     * @return array<string, mixed>
     */
    private function clinicDetails(Request $request): array
    {
        $settings = Setting::group('clinic', self::CLINIC_DEFAULTS);
        $path = $settings['logo_path'] ?? null;
        unset($settings['logo_path']);
        $branch = $request->user()->branch;

        return array_merge($settings, [
            'name' => $settings['name'] ?: ($branch?->name ?? config('app.name')),
            'address' => $settings['address'] ?: ($branch?->city ?? ''),
            'logo' => $path && Storage::disk('local')->exists($path)
                ? 'data:'.Storage::disk('local')->mimeType($path).';base64,'.base64_encode(Storage::disk('local')->get($path))
                : null,
        ]);
    }

    // ---- Billing preferences ----

    public const BILLING_DEFAULTS = [
        'default_payment_mode' => 'cash', 'pay_timing' => 'before', 'ipd_prefix' => 'IP', 'ipd_bill_prefix' => 'IPB',
        // Bills printed on the hospital's own letterhead paper leave out the hospital details and logo, and leave
        // this much room at the top and bottom of the page for what is already printed on the paper.
        'print_on_letterhead' => false, 'letterhead_top_mm' => 40, 'letterhead_bottom_mm' => 20,
        // How the hospital's heading sits at the top of a bill on plain paper: the logo on the left with the name and
        // address beside it, or everything centred with the logo on top; and how large the words and the logo are.
        'bill_heading' => 'side', 'bill_heading_size' => 'small', 'bill_logo_size' => 'medium',
        // On the printed IPD bill, list each medicine under the pharmacy bill it came on.
        'ipd_print_medicines' => true,
    ];

    public function billingPreferences(): JsonResponse
    {
        return response()->json(Setting::group('billing', self::BILLING_DEFAULTS));
    }

    public function saveBillingPreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'default_payment_mode' => ['required', Rule::in(Payment::MODES)],
            // before: the consultation is paid for when the visit is booked; after: it is billed then and paid later.
            'pay_timing' => ['required', Rule::in(['before', 'after'])],
            'print_on_letterhead' => ['sometimes', 'boolean'],
            'letterhead_top_mm' => ['sometimes', 'integer', 'between:0,150'],
            'letterhead_bottom_mm' => ['sometimes', 'integer', 'between:0,100'],
            'bill_heading' => ['sometimes', Rule::in(['side', 'centre'])],
            'bill_heading_size' => ['sometimes', Rule::in(['small', 'medium', 'large'])],
            'bill_logo_size' => ['sometimes', Rule::in(['small', 'medium', 'large'])],
            'ipd_print_medicines' => ['sometimes', 'boolean'],
            // Put before the running number of each new IPD case, e.g. IP00012.
            'ipd_prefix' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9\/-]{1,8}$/'],
            'ipd_bill_prefix' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9\/-]{1,8}$/'],
        ], ['ipd_prefix.regex' => 'Use up to 8 letters, numbers, dashes or slashes.', 'ipd_bill_prefix.regex' => 'Use up to 8 letters, numbers, dashes or slashes.']);
        Setting::updateOrCreate(['key' => 'billing'], ['value' => array_merge(Setting::group('billing', self::BILLING_DEFAULTS), $data)]);

        return response()->json(Setting::group('billing', self::BILLING_DEFAULTS));
    }

    // ---- Departments, vendors and payers ----

    public function entries(Request $request, string $kind): JsonResponse
    {
        abort_unless(isset(AdminEntry::KINDS[$kind]), 404);

        return response()->json(AdminEntry::where('kind', $kind)->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))->orderBy('name')->get());
    }

    public function storeEntry(Request $request, string $kind): JsonResponse
    {
        abort_unless(isset(AdminEntry::KINDS[$kind]), 404);

        return response()->json(AdminEntry::create($this->entryData($request, $kind) + ['kind' => $kind]), 201);
    }

    public function updateEntry(Request $request, string $kind, AdminEntry $entry): JsonResponse
    {
        abort_unless($entry->kind === $kind, 404);
        $entry->update($this->entryData($request, $kind, $entry));

        return response()->json($entry);
    }

    /**
     * @return array<string, mixed>
     */
    private function entryData(Request $request, string $kind, ?AdminEntry $entry = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('admin_entries')->where('kind', $kind)->ignore($entry)],
            'details' => ['nullable', 'array'],
            'details.*' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['name.unique' => 'This name is already in the list.']);
        // Only the boxes this kind of entry has are kept.
        $data['details'] = array_filter(array_intersect_key($data['details'] ?? [], array_flip(AdminEntry::KINDS[$kind])), 'filled') ?: null;

        return $data;
    }

    // ---- Referral doctors ----

    public function referralDoctors(): JsonResponse
    {
        return response()->json(ReferralDoctor::orderBy('name')->get());
    }

    public function storeReferralDoctor(Request $request): JsonResponse
    {
        return response()->json(ReferralDoctor::create($this->referralData($request)), 201);
    }

    public function updateReferralDoctor(Request $request, ReferralDoctor $doctor): JsonResponse
    {
        $doctor->update($this->referralData($request, $doctor));

        return response()->json($doctor);
    }

    public function destroyReferralDoctor(ReferralDoctor $doctor): JsonResponse
    {
        $doctor->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function referralData(Request $request, ?ReferralDoctor $doctor = null): array
    {
        return $request->validate([
            'direction' => ['required', Rule::in(['by', 'to'])],
            'name' => ['required', 'string', 'max:255', Rule::unique('referral_doctors')->where('direction', $request->input('direction'))->ignore($doctor)],
            'specialization' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'place' => ['nullable', 'string', 'max:255'],
        ], ['name.unique' => 'This doctor is already in the list.']);
    }

    // ---- Merging duplicate patients ----

    public function merges(): JsonResponse
    {
        return response()->json(PatientMerge::with('staff:id,name')->latest('id')->limit(200)->get());
    }

    /**
     * Folds a duplicate registration into the right one: every visit, bill, lab order, pharmacy bill,
     * admission and attachment moves across, and the duplicate is hidden from searches.
     */
    public function merge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:20', 'different:to'],
            'to' => ['required', 'string', 'max:20'],
        ], ['from.different' => 'Choose two different patients.']);

        $from = Patient::where('uhid', trim($data['from']))->first();
        $to = Patient::where('uhid', trim($data['to']))->first();
        if (! $from) {
            throw ValidationException::withMessages(['from' => 'No patient has this ID.']);
        }
        if (! $to) {
            throw ValidationException::withMessages(['to' => 'No patient has this ID.']);
        }
        if ($from->merged_into_id) {
            throw ValidationException::withMessages(['from' => 'This patient has already been merged into another.']);
        }
        if ($to->merged_into_id) {
            throw ValidationException::withMessages(['to' => 'This patient was merged into another. Use that patient instead.']);
        }
        if (Admission::whereIn('patient_id', [$from->id, $to->id])->where('status', 'admitted')->count() > 1) {
            throw ValidationException::withMessages(['from' => 'Both records have an open admission. Close one of them first.']);
        }

        $merge = DB::transaction(function () use ($from, $to, $request) {
            $moved = [];
            foreach (self::PATIENT_TABLES as $table => $label) {
                $count = DB::table($table)->where('patient_id', $from->id)->update(['patient_id' => $to->id]);
                if ($count) {
                    $moved[$label] = $count;
                }
            }

            // Details the kept record lacks are taken from the duplicate; allergies from both are kept.
            $fill = [];
            foreach (['mobile_alt', 'email', 'blood_group', 'preferred_language', 'address', 'city', 'pincode', 'care_of', 'occupation', 'referred_by', 'existing_id'] as $field) {
                if (blank($to->{$field}) && filled($from->{$field})) {
                    $fill[$field] = $from->{$field};
                }
            }
            if (filled($from->allergies) && ! str_contains(mb_strtolower((string) $to->allergies), mb_strtolower($from->allergies))) {
                $fill['allergies'] = trim($to->allergies.(filled($to->allergies) ? ', ' : '').$from->allergies);
            }
            if (blank($to->mobile_alt) && ! isset($fill['mobile_alt']) && $from->mobile !== $to->mobile) {
                $fill['mobile_alt'] = $from->mobile;
            }
            $to->update($fill);

            $from->merged_into_id = $to->id;
            $from->save();

            return PatientMerge::create([
                'from_id' => $from->id, 'from_uhid' => $from->uhid, 'from_name' => $from->name,
                'to_id' => $to->id, 'to_uhid' => $to->uhid, 'to_name' => $to->name, 'moved' => $moved, 'merged_by' => $request->user()->id,
            ]);
        });

        AuditLog::record('patient.merged', $merge->to_id, null, "{$merge->from_uhid} {$merge->from_name} into {$merge->to_uhid} {$merge->to_name}");

        return response()->json($merge->load('staff:id,name'), 201);
    }
}
