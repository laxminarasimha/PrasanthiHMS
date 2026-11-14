<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\DoctorBlock;
use App\Models\Medicine;
use App\Models\MedicineSubscription;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        $account = PatientAccount::where('mobile', preg_replace('/\D/', '', $data['mobile']))->first();

        if (! $account || ! Hash::check($data['password'], $account->password)) {
            throw ValidationException::withMessages(['mobile' => 'The mobile number or password is not right.']);
        }
        if (! $account->is_active) {
            throw ValidationException::withMessages(['mobile' => 'This account has been switched off. Please call the hospital.']);
        }
        $account->update(['last_login_at' => now()]);

        return response()->json(['token' => $account->createToken('app')->plainTextToken, 'account' => $this->present($account)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->present($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    // ---- Appointments ----

    public function doctors(): JsonResponse
    {
        return response()->json(User::where('role', Role::Doctor)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'department']));
    }

    /** The times a doctor can be booked on one day, each marked free or not. */
    public function slots(Request $request, User $doctor): JsonResponse
    {
        abort_unless($doctor->role === Role::Doctor && $doctor->is_active, 404);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.today()->addDays(self::BOOK_AHEAD_DAYS)->toDateString()]]);

        return response()->json($this->slotsFor($doctor, Carbon::parse($data['date'])));
    }

    public function book(Request $request): JsonResponse
    {
        $account = $request->user();
        $data = $request->validate([
            'doctor_id' => ['required', Rule::exists('users', 'id')->where('role', Role::Doctor->value)->where('is_active', true)],
            'scheduled_at' => ['required', 'date_format:Y-m-d H:i'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $at = Carbon::parse($data['scheduled_at']);
        $doctor = User::find($data['doctor_id']);

        $appointment = DB::transaction(function () use ($account, $data, $at, $doctor) {
            // Read the day again under a lock, so two people cannot take the last place in a slot.
            Appointment::where('doctor_id', $doctor->id)->whereDate('scheduled_at', $at->toDateString())->lockForUpdate()->get(['id']);
            $slot = collect($this->slotsFor($doctor, $at))->firstWhere('time', $at->format('H:i'));
            if (! $slot) {
                throw ValidationException::withMessages(['scheduled_at' => 'The doctor does not see patients at that time. Choose one of the times shown.']);
            }
            if (! $slot['available']) {
                throw ValidationException::withMessages(['scheduled_at' => 'That time has just been taken or has passed. Choose another.']);
            }
            if (Appointment::where('patient_id', $account->patient_id)->where('doctor_id', $doctor->id)->whereDate('scheduled_at', $at->toDateString())->where('status', '!=', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['scheduled_at' => 'You already have an appointment with this doctor on that day.']);
            }

            return Appointment::create([
                'patient_id' => $account->patient_id, 'doctor_id' => $doctor->id, 'scheduled_at' => $at->format('Y-m-d H:i'), 'status' => 'booked',
                'notes' => $data['notes'] ?? null, 'booked_via' => 'app',
                'token_no' => (int) Appointment::where('doctor_id', $doctor->id)->whereDate('scheduled_at', $at->toDateString())->max('token_no') + 1,
            ]);
        });

        return response()->json($this->presentAppointment($appointment->load('doctor:id,name,department')), 201);
    }

    public function appointments(Request $request): JsonResponse
    {
        return response()->json(
            Appointment::where('patient_id', $request->user()->patient_id)->with('doctor:id,name,department')
                ->latest('scheduled_at')->limit(50)->get()->map(fn (Appointment $a) => $this->presentAppointment($a))
        );
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->patient_id === $request->user()->patient_id, 404);
        if ($appointment->status !== 'booked' || $appointment->scheduled_at->isPast()) {
            throw ValidationException::withMessages(['appointment' => 'Only an upcoming appointment that has not started can be cancelled here. Please call the hospital.']);
        }
        $appointment->update(['status' => 'cancelled']);

        return response()->json($this->presentAppointment($appointment->load('doctor:id,name,department')));
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
        $step = max(5, (int) $settings['slot_minutes']);
        $limit = (int) $settings['slot_limit'] ?: 1; // from the app, one patient a slot unless the hospital allows more

        // The doctor's own hours for that weekday if set, otherwise the hospital's OPD hours.
        $weekday = strtolower($day->format('D'));
        $own = $doctor->availability;
        $ranges = is_array($own) && $own !== []
            ? ($own[$weekday] ?? [])
            : [['from' => $settings['morning_start'], 'to' => $settings['morning_end']], ['from' => $settings['evening_start'], 'to' => $settings['evening_end']]];

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
            'id' => $account->id, 'name' => $account->name, 'mobile' => $account->mobile, 'verified' => $account->verified_at !== null,
            'patient' => ['uhid' => $patient->uhid, 'gender' => $patient->gender, 'age_label' => $patient->age_label],
            'hospital' => $this->hospital(),
        ];
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
            'can_cancel' => $a->status === 'booked' && $a->scheduled_at->isFuture(),
        ];
    }
}
