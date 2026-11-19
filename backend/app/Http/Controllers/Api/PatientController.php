<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\LabOrder;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /**
     * The patients who are in the hospital or were here in the last few days: those with a visit, those admitted,
     * and those who had lab tests. The people a counter is most likely to be asked about, offered before anything
     * is typed. Those here now come first, then the most recent.
     */
    public function recent(): JsonResponse
    {
        $since = today()->subDays(2);
        $seen = [];
        // Each patient is listed once, by the latest thing that brought them in.
        $note = function (?int $patientId, string $at, string $why, bool $here = false) use (&$seen) {
            if ($patientId && (! isset($seen[$patientId]) || $here > $seen[$patientId]['here'] || ($here === $seen[$patientId]['here'] && $at > $seen[$patientId]['at']))) {
                $seen[$patientId] = ['at' => $at, 'why' => $why, 'here' => $here];
            }
        };

        Appointment::whereBetween('scheduled_at', [$since, now()->endOfDay()])->where('status', '!=', 'cancelled')->with('doctor:id,name')
            ->get(['id', 'patient_id', 'doctor_id', 'scheduled_at', 'status'])
            ->each(fn (Appointment $a) => $note(
                $a->patient_id, $a->scheduled_at->format('Y-m-d H:i'),
                ['booked' => 'Appointment', 'arrived' => 'Waiting', 'ongoing' => 'With the doctor', 'reviewed' => 'Seen'][$a->status].($a->doctor ? " · {$a->doctor->name}" : ''),
                $a->scheduled_at->isToday() && in_array($a->status, ['arrived', 'ongoing'], true),
            ));
        LabOrder::where('ordered_at', '>=', $since)->get(['id', 'patient_id', 'ordered_at', 'order_no'])
            ->each(fn (LabOrder $o) => $note($o->patient_id, $o->ordered_at->format('Y-m-d H:i'), "Lab tests · {$o->order_no}"));
        Admission::where('status', 'admitted')->with('bed:id,ward,bed_no')->get(['id', 'patient_id', 'bed_id', 'ipd_no', 'admitted_at'])
            ->each(fn (Admission $a) => $note($a->patient_id, $a->admitted_at->format('Y-m-d H:i'), "Admitted · {$a->ipd_no}".($a->bed ? " · {$a->bed->ward} bed {$a->bed->bed_no}" : ''), true));

        $order = collect($seen)->sortBy([['here', 'desc'], ['at', 'desc']])->take(20);
        $patients = Patient::whereIn('id', $order->keys())->whereNull('merged_into_id')->get(['id', 'uhid', 'title', 'name', 'gender', 'date_of_birth', 'mobile'])->keyBy('id');

        return response()->json($order->filter(fn ($_, $id) => $patients->has($id))->map(fn (array $why, int $id) => $patients[$id]->toArray() + ['recent' => $why])->values());
    }

    public function index(Request $request): JsonResponse
    {
        $patients = Patient::query()
            // A duplicate that was merged into another record is no longer offered anywhere.
            ->whereNull('merged_into_id')
            ->when($request->string('q')->trim()->value(), function ($query, $q) {
                // A name is found however it is typed: with or without "Mr" or "Dr", initials with
                // or without dots, the words in any order, extra spaces. Each word typed must appear
                // somewhere in the name.
                $words = collect(preg_split('/[\s.,]+/', $q))->map(fn ($word) => trim($word))->filter()
                    ->reject(fn ($word) => in_array(mb_strtolower($word), array_map('mb_strtolower', Patient::TITLES), true))->values();
                $like = fn (string $text) => str_replace(['%', '_'], ['\%', '\_'], $text);
                $digits = preg_replace('/\D/', '', $q);

                $query->where(function ($w) use ($q, $words, $like, $digits) {
                    if ($words->isNotEmpty()) {
                        $w->where(fn ($name) => $words->each(fn ($word) => $name->where('name', 'like', '%'.$like($word).'%')));
                    }
                    $w->orWhere('uhid', 'like', $like($q).'%')->orWhere('existing_id', $q);
                    // A phone number, even typed with spaces or +91 in front.
                    if (strlen($digits) >= 4) {
                        $tail = strlen($digits) > 10 ? substr($digits, -10) : $digits;
                        $w->orWhere('mobile', 'like', $tail.'%')->orWhere('mobile_alt', 'like', $tail.'%');
                    }
                });
            })
            // Exact match, for the "this number is already registered" check.
            ->when($request->string('mobile')->trim()->value(), fn ($query, $mobile) => $query
                ->where(fn ($w) => $w->where('mobile', $mobile)->orWhere('mobile_alt', $mobile)))
            // When each patient was last seen, for the patients list: a visit they came for, not one only booked.
            ->select('patients.*')
            ->addSelect(['last_visit_at' => Appointment::selectRaw('max(scheduled_at)')->whereColumn('patient_id', 'patients.id')
                ->whereIn('status', ['arrived', 'ongoing', 'reviewed'])])
            ->latest('id')
            ->paginate(20);

        return response()->json($patients);
    }

    /**
     * Books in someone with only a name and mobile number, for a phone booking or a rush at the desk.
     * The rest of the registration is filled in when they arrive.
     */
    public function storeTemporary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'regex:/^[0-9]{10}$/'],
        ], ['mobile.regex' => 'Enter a 10-digit mobile number.']);

        $patient = Patient::create($data + [
            'gender' => 'other', 'is_temporary' => true, 'dob_estimated' => false,
            'branch_id' => $request->user()->branch_id, 'created_by' => $request->user()->id,
        ]);

        return response()->json($patient->refresh(), 201);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['branch_id'] = $request->user()->branch_id;
        $data['created_by'] = $request->user()->id;

        $patient = Patient::create($data)->refresh();
        AuditLog::record('patient.registered', $patient->id, $patient);

        return response()->json($patient, 201);
    }

    public function show(Patient $patient): JsonResponse
    {
        return response()->json($patient);
    }

    public function update(Request $request, Patient $patient): JsonResponse
    {
        // Completing the registration makes a temporary patient a full one.
        $labels = ['title' => 'Title', 'name' => 'Name', 'gender' => 'Gender', 'date_of_birth' => 'Date of birth', 'mobile' => 'Mobile', 'mobile_alt' => 'Other mobile', 'email' => 'Email', 'blood_group' => 'Blood group', 'allergies' => 'Allergies', 'address' => 'Address', 'city' => 'City', 'pincode' => 'PIN code', 'care_of' => 'Care of', 'occupation' => 'Occupation', 'referred_by' => 'Referred by'];
        $before = $patient->only(array_keys($labels));
        $patient->update($this->validated($request) + ['is_temporary' => false]);
        if ($summary = AuditLog::changed($labels, array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $before), array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $patient->only(array_keys($labels))))) {
            AuditLog::record('patient.updated', $patient->id, $patient, $summary);
        }

        return response()->json($patient);
    }

    /**
     * Staff give either a date of birth or an age; an age is turned into an estimated birth date.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['nullable', Rule::in(Patient::TITLES)],
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'required_without:age', 'date', 'before_or_equal:today'],
            'age' => ['nullable', 'required_without:date_of_birth', 'integer', 'min:0', 'max:130'],
            // For babies the age is given in days, weeks or months.
            'age_unit' => ['nullable', Rule::in(['years', 'months', 'weeks', 'days'])],
            'mobile' => ['required', 'string', 'regex:/^[0-9]{10}$/'],
            'mobile_alt' => ['nullable', 'string', 'regex:/^[0-9]{10}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'blood_group' => ['nullable', Rule::in(self::BLOOD_GROUPS)],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'preferred_language' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'pincode' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
            'care_of' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'referred_by' => ['nullable', 'string', 'max:255'],
            'channel' => ['nullable', 'string', 'max:50'],
            'tag' => ['nullable', 'string', 'max:50'],
            'existing_id' => ['nullable', 'string', 'max:50'],
        ], [
            'mobile.regex' => 'Enter a 10-digit mobile number.',
            'mobile_alt.regex' => 'Enter a 10-digit mobile number.',
            'pincode.regex' => 'Enter a 6-digit PIN code.',
            'date_of_birth.required_without' => 'Enter an age or a date of birth.',
            'age.required_without' => 'Enter an age or a date of birth.',
        ]);

        if (empty($data['date_of_birth'])) {
            $age = (int) $data['age'];
            $born = match ($data['age_unit'] ?? 'years') {
                'days' => now()->subDays($age),
                'weeks' => now()->subWeeks($age),
                'months' => now()->subMonthsNoOverflow($age),
                default => now()->subYears($age),
            };
            $data['date_of_birth'] = $born->toDateString();
            $data['dob_estimated'] = true;
        } else {
            $data['dob_estimated'] = false;
        }
        unset($data['age'], $data['age_unit']);

        return $data;
    }
}
