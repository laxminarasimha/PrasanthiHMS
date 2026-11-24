<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\DoctorBlock;
use App\Models\LabOrder;
use App\Models\Medicine;
use App\Models\MedicineSubscription;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\Setting;
use App\Models\User;
use App\Support\DoctorHours;
use App\Support\GoogleIdToken;
use App\Support\SignInGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Everything the patient's mobile app can do: register, sign in, book and cancel their own
 * appointments, and keep a monthly medicine list with the pharmacy. A patient sees only
 * what belongs to their own account.
 */
class PatientAppController extends Controller
{
    /** How far ahead an appointment can be booked from the app. */
    private const BOOK_AHEAD_DAYS = 30;

    // ---- Account ----

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'mobile' => ['required', 'regex:/^[6-9][0-9]{9}$/', 'unique:patient_accounts,mobile'],
            'gender' => ['required', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
        ], [
            'mobile.regex' => 'Enter a 10-digit mobile number.',
            'mobile.unique' => 'This mobile number already has an account. Sign in instead.',
            'password.regex' => 'Use at least 8 characters with both letters and numbers.',
            'password.min' => 'Use at least 8 characters with both letters and numbers.',
        ]);

        $account = DB::transaction(function () use ($data) {
            // A fresh patient record for this account. If the hospital already knows this person,
            // the front desk joins the two with "Merge patients" once they have seen them: a phone
            // number typed into an app is not proof enough to open someone's old records.
            $patient = Patient::create([
                'name' => $data['name'], 'gender' => $data['gender'], 'date_of_birth' => $data['date_of_birth'],
                'mobile' => $data['mobile'], 'channel' => 'Mobile app',
            ]);

            return PatientAccount::create(['patient_id' => $patient->id, 'name' => $data['name'], 'mobile' => $data['mobile'], 'password' => $data['password'], 'last_login_at' => now()]);
        });

        return response()->json(['token' => $account->createToken('app')->plainTextToken, 'account' => $this->present($account)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['mobile' => ['required', 'string', 'max:15'], 'password' => ['required', 'string']]);
        $mobile = preg_replace('/\D/', '', $data['mobile']);
        SignInGuard::refuseIfClosed('patient', $mobile, 'mobile');
        $account = PatientAccount::where('mobile', $mobile)->first();

        if (! $account || ! Hash::check($data['password'], $account->password)) {
            SignInGuard::wrong('patient', $mobile);
            throw ValidationException::withMessages(['mobile' => 'The mobile number or password is not right.']);
        }
        SignInGuard::clear('patient', $mobile);
        if (! $account->is_active) {
            throw ValidationException::withMessages(['mobile' => 'This account has been switched off. Please call the hospital.']);
        }
        $account->update(['last_login_at' => now()]);

        return response()->json(['token' => $account->createToken('app')->plainTextToken, 'account' => $this->present($account)]);
    }

    /**
     * A patient signs in with Google. The Google account must already be joined to an app account (when it was opened
     * with Google, or joined later under Profile). If it is not, the app is told so, with the name Google gives, and asks
     * for the mobile number and the rest to open a new account. An email is never used to reach someone's account.
     */
    public function google(Request $request): JsonResponse
    {
        $google = GoogleIdToken::verify((string) $request->validate(['credential' => ['required', 'string', 'max:4096']])['credential']);
        $account = PatientAccount::where('google_sub', $google['sub'])->first();
        if (! $account) {
            return response()->json(['new' => true, 'name' => $google['name'], 'email' => $google['email']]);
        }
        if (! $account->is_active) {
            throw ValidationException::withMessages(['credential' => 'This account has been switched off. Please call the hospital.']);
        }
        $account->update(['last_login_at' => now()]);

        return response()->json(['token' => $account->createToken('app')->plainTextToken, 'account' => $this->present($account)]);
    }

    /** Opens a new app account with Google, the same as registering, but signed in with Google instead of a password. */
    public function googleRegister(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'string', 'max:4096'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'mobile' => ['required', 'regex:/^[6-9][0-9]{9}$/', 'unique:patient_accounts,mobile'],
            'gender' => ['required', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today', 'after:1900-01-01'],
        ], [
            'mobile.regex' => 'Enter a 10-digit mobile number.',
            'mobile.unique' => 'This mobile number already has an account. Sign in with it and your password, then join Google under Profile.',
        ]);
        $google = GoogleIdToken::verify($data['credential']);
        if (PatientAccount::where('google_sub', $google['sub'])->exists()) {
            throw ValidationException::withMessages(['credential' => 'This Google account already has an app account. Sign in with Google instead.']);
        }

        $account = DB::transaction(function () use ($data, $google) {
            // As with any new account: a fresh patient record, joined to older hospital records only by the front desk.
            $patient = Patient::create([
                'name' => $data['name'], 'gender' => $data['gender'], 'date_of_birth' => $data['date_of_birth'],
                'mobile' => $data['mobile'], 'email' => $google['email'], 'channel' => 'Mobile app',
            ]);

            // No password of their own: a long random one nobody knows, so only Google opens it.
            $account = new PatientAccount(['patient_id' => $patient->id, 'name' => $data['name'], 'mobile' => $data['mobile'], 'password' => Str::random(48), 'last_login_at' => now()]);
            $account->forceFill(['google_sub' => $google['sub'], 'google_email' => $google['email']])->save();

            return $account;
        });

        return response()->json(['token' => $account->createToken('app')->plainTextToken, 'account' => $this->present($account)], 201);
    }

    /** Joins a Google account to the signed-in app account, so the patient can sign in with Google from then on. */
    public function connectGoogle(Request $request): JsonResponse
    {
        $google = GoogleIdToken::verify((string) $request->validate(['credential' => ['required', 'string', 'max:4096']])['credential']);
        $account = $request->user();
        if (PatientAccount::where('google_sub', $google['sub'])->where('id', '!=', $account->id)->exists()) {
            throw ValidationException::withMessages(['credential' => "{$google['email']} is already joined to another app account."]);
        }
        $account->forceFill(['google_sub' => $google['sub'], 'google_email' => $google['email']])->save();

        return response()->json($this->present($account));
    }

    public function disconnectGoogle(Request $request): JsonResponse
    {
        $request->user()->forceFill(['google_sub' => null, 'google_email' => null])->save();

        return response()->json($this->present($request->user()));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->present($request->user()));
    }

    /** Lets a patient change their own password, after proving they know the current one. Other phones are signed out. */
    public function changePassword(Request $request): JsonResponse
    {
        $account = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed', 'different:current_password', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
        ], [
            'password.regex' => 'Use at least 8 characters with both letters and numbers.', 'password.min' => 'Use at least 8 characters with both letters and numbers.',
            'password.different' => 'Choose a password different from the current one.', 'password.confirmed' => 'The two new passwords are not the same.',
        ]);
        if (! Hash::check($data['current_password'], $account->password)) {
            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }
        $account->update(['password' => $data['password']]);
        $account->tokens()->where('id', '!=', $account->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password changed.']);
    }

    /** The hospital's name, address and phone numbers, for the contact screen. */
    public function hospitalInfo(): JsonResponse
    {
        $clinic = Setting::group('clinic', AdminController::CLINIC_DEFAULTS);

        return response()->json(['name' => $clinic['name'] ?: 'Prasanthi Hospitals', 'tagline' => $clinic['tagline'], 'address' => $clinic['address'], 'phone' => $clinic['phone'], 'phone_alt' => $clinic['phone_alt'], 'email' => $clinic['email'],
            'doctors' => User::where('role', Role::Doctor)->where('is_active', true)->where('book_online', true)->orderBy('name')->get(['id', 'name', 'department'])]);
    }

    /**
     * The patient's own medical records: finished visits with their prescriptions, and lab reports
     * that are ready. Shown only once the hospital has verified that the account belongs to this
     * patient, because a phone number typed into an app is not proof of who someone is.
     */
    public function records(Request $request): JsonResponse
    {
        $account = $request->user();
        // Whose records: checked first, so asking about someone not on the account is refused whatever the account's state.
        $patientId = $account->patientFor($request->query('patient_id'));
        if (! $account->verified_at) {
            return response()->json(['verified' => false, 'visits' => [], 'lab' => []]);
        }

        $visits = Consultation::where('patient_id', $patientId)->whereNotNull('completed_at')
            ->with(['medicines', 'doctor:id,name,department'])->latest('completed_at')->limit(50)->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'date' => $c->completed_at->format('Y-m-d'), 'doctor' => $c->doctor?->only(['name', 'department']),
                'diagnosis' => $c->diagnosis, 'advice' => $c->advice, 'tests_advised' => $c->tests_advised, 'follow_up_on' => $c->follow_up_on?->format('Y-m-d'),
                'medicines' => $c->medicines->map(fn ($m) => ['medicine' => $m->medicine, 'directions' => implode(' · ', array_filter([$m->dose, $m->frequency, $m->timing, $m->duration, $m->instructions]))])->all(),
            ]);

        // A lab result goes out only when it is reported and, where the lab works that way, approved.
        $needsApproval = (bool) Setting::group('lab', Setting::LAB_DEFAULTS)['approval_required'];
        $lab = LabOrder::where('patient_id', $patientId)
            ->with(['items' => fn ($q) => $q->whereNotNull('reported_at')->when($needsApproval, fn ($w) => $w->whereNotNull('approved_at'))->with('results')])
            ->latest('ordered_at')->limit(50)->get()
            ->filter(fn ($o) => $o->items->isNotEmpty())
            ->map(fn ($o) => [
                'id' => $o->id, 'order_no' => $o->order_no, 'date' => $o->ordered_at->format('Y-m-d'),
                'tests' => $o->items->map(fn ($i) => [
                    'name' => $i->test_name, 'report' => $i->report_text, 'remarks' => $i->remarks,
                    'results' => $i->results->where('is_heading', false)->filter(fn ($r) => filled($r->value))
                        ->map(fn ($r) => ['parameter' => $r->parameter, 'value' => $r->value, 'unit' => $r->unit, 'range' => $r->reference_range, 'flag' => $r->flag])->values(),
                ])->values(),
            ])->values();

        return response()->json(['verified' => true, 'visits' => $visits, 'lab' => $lab]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    // ---- Appointments ----

    public function doctors(): JsonResponse
    {
        return response()->json(User::where('role', Role::Doctor)->where('is_active', true)->where('book_online', true)->orderBy('name')->get(['id', 'name', 'department']));
    }

    /** The times a doctor can be booked on one day, each marked free or not. */
    public function slots(Request $request, User $doctor): JsonResponse
    {
        abort_unless($doctor->role === Role::Doctor && $doctor->is_active && $doctor->book_online, 404);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.today()->addDays(self::BOOK_AHEAD_DAYS)->toDateString()]]);

        return response()->json($this->slotsFor($doctor, Carbon::parse($data['date'])));
    }

    public function book(Request $request): JsonResponse
    {
        $account = $request->user();
        $data = $request->validate([
            'doctor_id' => ['required', Rule::exists('users', 'id')->where('role', Role::Doctor->value)->where('is_active', true)->where('book_online', true)],
            'scheduled_at' => ['required', 'date_format:Y-m-d H:i'],
            'notes' => ['nullable', 'string', 'max:255'],
            'patient_id' => ['nullable', 'integer'],
        ]);
        $at = Carbon::parse($data['scheduled_at']);
        $doctor = User::find($data['doctor_id']);
        $patientId = $account->patientFor($data['patient_id'] ?? null);

        $appointment = DB::transaction(function () use ($patientId, $data, $at, $doctor) {
            // Read the day again under a lock, so two people cannot take the last place in a slot.
            Appointment::where('doctor_id', $doctor->id)->whereDate('scheduled_at', $at->toDateString())->lockForUpdate()->get(['id']);
            $slot = collect($this->slotsFor($doctor, $at))->firstWhere('time', $at->format('H:i'));
            if (! $slot) {
                throw ValidationException::withMessages(['scheduled_at' => 'The doctor does not see patients at that time. Choose one of the times shown.']);
            }
            if (! $slot['available']) {
                throw ValidationException::withMessages(['scheduled_at' => 'That time has just been taken or has passed. Choose another.']);
            }
            if (Appointment::where('patient_id', $patientId)->where('doctor_id', $doctor->id)->whereDate('scheduled_at', $at->toDateString())->where('status', '!=', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['scheduled_at' => 'There is already an appointment with this doctor on that day.']);
            }

            return Appointment::create([
                'patient_id' => $patientId, 'doctor_id' => $doctor->id, 'scheduled_at' => $at->format('Y-m-d H:i'), 'status' => 'booked',
                'notes' => $data['notes'] ?? null, 'booked_via' => 'app',
                'token_no' => (int) Appointment::where('doctor_id', $doctor->id)->whereDate('scheduled_at', $at->toDateString())->max('token_no') + 1,
            ]);
        });

        return response()->json($this->presentAppointment($appointment->load(['doctor:id,name,department', 'patient:id,name'])), 201);
    }

    public function appointments(Request $request): JsonResponse
    {
        return response()->json(
            Appointment::whereIn('patient_id', $request->user()->patientIds())->with(['doctor:id,name,department', 'patient:id,name'])
                ->latest('scheduled_at')->limit(80)->get()->map(fn (Appointment $a) => $this->presentAppointment($a))
        );
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless(in_array($appointment->patient_id, $request->user()->patientIds(), true), 404);
        if ($appointment->status !== 'booked' || $appointment->scheduled_at->isPast()) {
            throw ValidationException::withMessages(['appointment' => 'Only an upcoming appointment that has not started can be cancelled here. Please call the hospital.']);
        }
        $appointment->update(['status' => 'cancelled']);

        return response()->json($this->presentAppointment($appointment->load(['doctor:id,name,department', 'patient:id,name'])));
    }

    /**
     * Moves an upcoming appointment to another free time with the same doctor, in one step: the new time is taken
     * only if it is free, and the old one is let go at the same moment. The checks are the same as for booking.
     */
    public function move(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless(in_array($appointment->patient_id, $request->user()->patientIds(), true), 404);
        if ($appointment->status !== 'booked' || $appointment->scheduled_at->isPast()) {
            throw ValidationException::withMessages(['appointment' => 'Only an upcoming appointment that has not started can be moved here. Please call the hospital.']);
        }
        $data = $request->validate(['scheduled_at' => ['required', 'date_format:Y-m-d H:i']]);
        $at = Carbon::parse($data['scheduled_at']);
        if ($at->equalTo($appointment->scheduled_at)) {
            throw ValidationException::withMessages(['scheduled_at' => 'That is the time already booked.']);
        }
        $doctor = User::find($appointment->doctor_id);
        abort_unless($doctor && $doctor->is_active, 422, 'This doctor is not taking appointments now. Please book with another doctor.');

        DB::transaction(function () use ($appointment, $at, $doctor) {
            Appointment::where('doctor_id', $doctor->id)->whereDate('scheduled_at', $at->toDateString())->lockForUpdate()->get(['id']);
            $slot = collect($this->slotsFor($doctor, $at))->firstWhere('time', $at->format('H:i'));
            if (! $slot) {
                throw ValidationException::withMessages(['scheduled_at' => 'The doctor does not see patients at that time. Choose one of the times shown.']);
            }
            if (! $slot['available']) {
                throw ValidationException::withMessages(['scheduled_at' => 'That time has just been taken or has passed. Choose another.']);
            }
            $sameDay = $at->isSameDay($appointment->scheduled_at);
            if (! $sameDay && Appointment::where('patient_id', $appointment->patient_id)->where('doctor_id', $doctor->id)->whereDate('scheduled_at', $at->toDateString())->where('status', '!=', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['scheduled_at' => 'There is already an appointment with this doctor on that day.']);
            }
            $appointment->update([
                'scheduled_at' => $at->format('Y-m-d H:i'),
                // A new day gives a new place in that day's queue; the same day keeps the token already given.
                'token_no' => $sameDay ? $appointment->token_no : (int) Appointment::where('doctor_id', $doctor->id)->whereDate('scheduled_at', $at->toDateString())->max('token_no') + 1,
            ]);
        });

        return response()->json($this->presentAppointment($appointment->fresh(['doctor:id,name,department', 'patient:id,name'])));
    }

    // ---- Monthly medicines ----

    /** Medicine names to choose from while typing. Names only: no prices or stock. */
    public function medicines(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        return response()->json(Medicine::where('is_active', true)->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%')->orderBy('name')->limit(20)->get(['id', 'name', 'type']));
    }

    public function subscriptions(Request $request): JsonResponse
    {
        return response()->json($request->user()->subscriptions()->where('status', '!=', 'cancelled')->get());
    }

    public function storeSubscription(Request $request): JsonResponse
    {
        $account = $request->user();
        abort_if($account->subscriptions()->where('status', '!=', 'cancelled')->count() >= 10, 422, 'You can keep up to 10 medicine lists. Cancel one you no longer need.');
        $data = $this->subscriptionData($request);

        $subscription = $account->subscriptions()->create($data + [
            'patient_id' => $account->patient_id, 'status' => 'active',
            'next_due_on' => MedicineSubscription::dueOnOrAfter($data['day_of_month'], today()->addDay()),
        ]);

        return response()->json($subscription->fresh(), 201);
    }

    public function updateSubscription(Request $request, MedicineSubscription $subscription): JsonResponse
    {
        abort_unless($subscription->patient_account_id === $request->user()->id && $subscription->status !== 'cancelled', 404);
        $data = $this->subscriptionData($request) + $request->validate(['status' => ['sometimes', Rule::in(['active', 'paused'])]]);

        // Changing the day, or starting again after a pause, sets the next date afresh.
        if ($data['day_of_month'] !== $subscription->day_of_month || (($data['status'] ?? $subscription->status) === 'active' && $subscription->status === 'paused')) {
            $data['next_due_on'] = MedicineSubscription::dueOnOrAfter($data['day_of_month'], today()->addDay());
        }
        $subscription->update($data);

        return response()->json($subscription->fresh());
    }

    public function cancelSubscription(Request $request, MedicineSubscription $subscription): JsonResponse
    {
        abort_unless($subscription->patient_account_id === $request->user()->id, 404);
        $subscription->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Cancelled.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionData(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'day_of_month' => ['required', 'integer', 'between:1,28'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.medicine_id' => ['nullable', 'integer', 'exists:medicines,id'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'between:1,1000'],
        ], [
            'items.required' => 'Add at least one medicine.',
            'items.*.name.required' => 'Enter the medicine name or remove the empty line.',
            'items.*.quantity.required' => 'Enter how many you need each month.',
            'day_of_month.between' => 'Choose a day from 1 to 28, so it exists in every month.',
        ]);
        $data['items'] = collect($data['items'])->map(fn ($i) => ['medicine_id' => $i['medicine_id'] ?? null, 'name' => trim($i['name']), 'quantity' => (int) $i['quantity']])->values()->all();

        return $data;
    }

    // ---- Helpers ----

    /**
     * @return list<array{time: string, label: string, available: bool}>
     */
    private function slotsFor(User $doctor, Carbon $day): array
    {
        $settings = Setting::group('calendar', Setting::CALENDAR_DEFAULTS);
        $step = DoctorHours::step($doctor);
        $limit = (int) $settings['slot_limit'] ?: 1; // from the app, one patient a slot unless the hospital allows more

        // The doctor's own hours for that weekday if set, otherwise the hospital's OPD hours.
        $ranges = DoctorHours::ranges($doctor, $day);

        $blocks = DoctorBlock::where('doctor_id', $doctor->id)->between($day->toDateString(), $day->toDateString())->get();
        $taken = Appointment::where('doctor_id', $doctor->id)->whereDate('scheduled_at', $day->toDateString())->where('status', '!=', 'cancelled')->get(['scheduled_at'])
            ->countBy(fn (Appointment $a) => $a->scheduled_at->copy()->startOfDay()->addMinutes(intdiv($a->scheduled_at->hour * 60 + $a->scheduled_at->minute, $step) * $step)->format('H:i'));
        $earliest = now()->addMinutes(30); // not bookable at the last minute

        $slots = [];
        foreach ($ranges as $range) {
            $at = Carbon::parse($day->toDateString().' '.$range['from']);
            $end = Carbon::parse($day->toDateString().' '.$range['to']);
            for (; $at->lt($end); $at = $at->copy()->addMinutes($step)) {
                $time = $at->format('H:i');
                $blocked = $blocks->contains(fn (DoctorBlock $b) => $b->covers($at));
                $slots[$time] = ['time' => $time, 'label' => $at->format('g:i A'), 'available' => ! $blocked && $at->gte($earliest) && ($taken[$time] ?? 0) < $limit];
            }
        }
        ksort($slots);

        return array_values($slots);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PatientAccount $account): array
    {
        $patient = $account->patient;

        return [
            'id' => $account->id, 'name' => $account->name, 'mobile' => $account->mobile, 'verified' => $account->verified_at !== null, 'google_email' => $account->google_email,
            'patient' => ['id' => $patient->id, 'uhid' => $patient->uhid, 'gender' => $patient->gender, 'age_label' => $patient->age_label],
            'people' => self::people($account),
            'hospital' => $this->hospital(),
        ];
    }

    /**
     * The person and the family members they look after, the person first.
     *
     * @return list<array<string, mixed>>
     */
    public static function people(PatientAccount $account): array
    {
        $show = fn (Patient $p, string $relation) => ['patient_id' => $p->id, 'name' => $p->name, 'relation' => $relation, 'uhid' => $p->uhid, 'gender' => $p->gender, 'age_label' => $p->age_label];

        return collect([$show($account->patient, 'Self')])
            ->concat($account->members()->with('patient')->get()->filter(fn ($m) => $m->patient && $m->patient_id !== $account->patient_id)->unique('patient_id')->map(fn ($m) => $show($m->patient, $m->relation)))
            ->values()->all();
    }

    /**
     * The hospital's name and phone as set under Setup, for the app's headings and "call us" lines.
     *
     * @return array{name: string, phone: string}
     */
    private function hospital(): array
    {
        $clinic = Setting::group('clinic', AdminController::CLINIC_DEFAULTS);

        return ['name' => $clinic['name'] ?: 'Prasanthi Hospitals', 'phone' => (string) $clinic['phone']];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAppointment(Appointment $a): array
    {
        return [
            'id' => $a->id, 'scheduled_at' => $a->scheduled_at->format('Y-m-d H:i'), 'status' => $a->status, 'token_no' => $a->token_no, 'notes' => $a->notes,
            'doctor' => $a->doctor?->only(['id', 'name', 'department']),
            'patient' => $a->patient?->only(['id', 'name']),
            'can_cancel' => $a->status === 'booked' && $a->scheduled_at->isFuture(),
        ];
    }
}
