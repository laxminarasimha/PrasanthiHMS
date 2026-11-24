<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Bill;
use App\Models\DoctorBlock;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Models\Patient;
use App\Support\DiscountWhy;
use App\Support\DoctorFee;
use App\Support\DoctorHours;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    private const WITH = ['patient:id,uhid,title,name,gender,date_of_birth,mobile', 'doctor:id,name', 'service:id,name', 'bills.labOrder:id,bill_id,order_no'];

    /**
     * What the desk needs beside each visit: its bills (with any amount moved to an IPD bill)
     * and what the doctor decided, so the desk can print, book tests or book the next visit.
     *
     * @return array<string, \Closure>
     */
    private static function extras(): array
    {
        return [
            'bills' => fn ($bills) => $bills->select('id', 'appointment_id', 'bill_no', 'kind', 'total', 'paid')->whereNull('cancelled_at')->withIpdTransfers(),
            'consultation' => fn ($c) => $c->select('id', 'appointment_id', 'completed_at', 'tests_advised', 'follow_up_on', 'refer_to')->withCount('medicines'),
        ];
    }

    /** One day's appointments, oldest first, with counts per status for the filter tabs. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(Appointment::STATUSES)],
            'doctor_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $day = Appointment::query()
            ->whereDate('scheduled_at', $filters['date'] ?? today())
            ->when($filters['doctor_id'] ?? null, fn ($q, $id) => $q->where('doctor_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereHas('patient', fn ($p) => $p
                ->where('name', 'like', "%{$term}%")
                ->orWhere('mobile', 'like', "{$term}%")
                ->orWhere(fn ($id) => Patient::idStarts($id, $term))));

        return response()->json([
            'counts' => (clone $day)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'data' => $day->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->with(self::WITH)->with(self::extras())->orderBy('scheduled_at')->orderBy('id')->get(),
        ]);
    }

    /** Every appointment in a run of days (one day or a week), with the OPD hours the calendar is drawn from. */
    public function calendar(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'between:1,31'],
            'doctor_id' => ['nullable', 'integer'],
        ]);

        $from = Carbon::parse($filters['from'])->startOfDay();
        $to = $from->copy()->addDays((int) ($filters['days'] ?? 1) - 1);

        return response()->json([
            'settings' => Setting::group('calendar', Setting::CALENDAR_DEFAULTS),
            'blocks' => DoctorBlock::between($from->toDateString(), $to->toDateString())
                ->when($filters['doctor_id'] ?? null, fn ($q, $id) => $q->where('doctor_id', $id))
                ->with('doctor:id,name')->orderBy('from_date')->orderBy('start_time')->get(),
            'data' => Appointment::query()
                ->whereDate('scheduled_at', '>=', $from)
                ->whereDate('scheduled_at', '<=', $to)
                ->when($filters['doctor_id'] ?? null, fn ($q, $id) => $q->where('doctor_id', $id))
                ->with(self::WITH)->with(self::extras())->orderBy('scheduled_at')->orderBy('id')->get(),
        ]);
    }

    public function saveCalendarSettings(Request $request): JsonResponse
    {
        $time = ['required', 'date_format:H:i'];
        $data = $request->validate([
            'slot_minutes' => ['required', 'integer', Rule::in([5, 10, 15, 20, 30, 60])],
            'slot_limit' => ['nullable', 'integer', 'between:0,50'], // 0: any number of patients in a slot
            'morning_start' => $time,
            'morning_end' => [...$time, 'after:morning_start'],
            'evening_start' => [...$time, 'after_or_equal:morning_end'],
            'evening_end' => [...$time, 'after:evening_start'],
        ]);

        $data['slot_limit'] = (int) ($data['slot_limit'] ?? 0);
        Setting::updateOrCreate(['key' => 'calendar'], ['value' => $data]);

        return response()->json(Setting::group('calendar', Setting::CALENDAR_DEFAULTS));
    }

    /** Marks a doctor as away for whole days, or for the same hours on each of those days. */
    public function storeBlock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'doctor_id' => ['required', Rule::exists('users', 'id')->where('role', Role::Doctor->value)],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'start_time' => ['nullable', 'required_with:end_time', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_with:start_time', 'date_format:H:i', 'after:start_time'],
            'reason' => ['nullable', 'string', 'max:100'],
        ], [
            'to_date.after_or_equal' => 'The last day cannot be before the first day.',
            'end_time.after' => 'The end time must be after the start time.',
        ]);

        $block = DoctorBlock::create($data + ['created_by' => $request->user()->id]);

        // Patients already booked in that time are not moved; tell the desk how many to call.
        $booked = Appointment::where('doctor_id', $block->doctor_id)
            ->whereDate('scheduled_at', '>=', $data['from_date'])->whereDate('scheduled_at', '<=', $data['to_date'])
            ->whereIn('status', ['booked', 'arrived'])
            ->get(['id', 'scheduled_at'])
            ->filter(fn (Appointment $a) => $block->covers(Carbon::parse($a->getRawOriginal('scheduled_at'))))
            ->count();

        return response()->json($block->load('doctor:id,name')->toArray() + ['booked' => $booked], 201);
    }

    public function destroyBlock(DoctorBlock $block): JsonResponse
    {
        $block->delete();

        return response()->json(null, 204);
    }

    /**
     * Refuses a time when the doctor is away, or when the slot already has as many patients as allowed.
     * A visit booked for a later time also has to fall inside the doctor's hours, unless the desk says to book it
     * anyway; a patient who is already here, or is being entered for a time that has come, is never refused for the hour.
     */
    private function guardSlot(int $doctorId, Carbon $at, ?int $ignoreId = null, bool $checkHours = false): void
    {
        $doctor = User::find($doctorId);
        if ($checkHours && $doctor && $at->gt(now()) && ! DoctorHours::covers($doctor, $at)) {
            throw ValidationException::withMessages(['outside_hours' => DoctorHours::describe($doctor, $at).' '.$at->format('g:i A').' is outside those hours.']);
        }

        $block = DoctorBlock::where('doctor_id', $doctorId)->between($at->toDateString(), $at->toDateString())->with('doctor:id,name')->get()
            ->first(fn (DoctorBlock $b) => $b->covers($at));
        if ($block) {
            throw ValidationException::withMessages(['scheduled_at' => "{$block->doctor->name} is {$block->describe()}. Choose another time or doctor."]);
        }

        $settings = Setting::group('calendar', Setting::CALENDAR_DEFAULTS);
        $limit = (int) $settings['slot_limit'];
        if ($limit < 1) {
            return;
        }
        $step = DoctorHours::step($doctor);
        $start = $at->copy()->startOfDay()->addMinutes(intdiv($at->hour * 60 + $at->minute, $step) * $step);
        $taken = Appointment::where('doctor_id', $doctorId)
            ->where('status', '!=', 'cancelled')
            // Compared to the minute, as text, so it reads the same on MySQL and on the test database.
            ->where('scheduled_at', '>=', $start->format('Y-m-d H:i'))->where('scheduled_at', '<', $start->copy()->addMinutes($step)->format('Y-m-d H:i'))
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->count();
        if ($taken >= $limit) {
            throw ValidationException::withMessages(['scheduled_at' => 'The '.$start->format('g:i A')." slot is full ({$limit} per slot). Choose another time."]);
        }
    }

    /**
     * Books the visit and, unless billing is skipped, opens a bill for the chosen service.
     * The front desk may change the price, quantity and discount, and take payment at once.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'patient_id' => ['required', 'exists:patients,id'],
            'skip_billing' => ['sometimes', 'boolean'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'paid' => ['nullable', 'numeric', 'min:0'],
            'payment_mode' => ['nullable', 'required_with:paid', Rule::in(Payment::MODES)],
            'outside_hours' => ['sometimes', 'boolean'],
        ] + DiscountWhy::RULES);

        $billing = array_intersect_key($data, array_flip(['skip_billing', 'unit_price', 'quantity', 'discount', 'paid', 'payment_mode', 'discount_reason', 'discount_allowed_by']));
        $data = array_diff_key($data, $billing + ['outside_hours' => 1]);
        DoctorFee::fits($data['service_id'] ?? null, (int) $data['doctor_id']);
        $this->guardSlot((int) $data['doctor_id'], Carbon::parse($data['scheduled_at']), null, ($data['status'] ?? 'booked') === 'booked' && ! $request->boolean('outside_hours'));

        $appointment = DB::transaction(function () use ($data, $billing, $request) {
            $day = Carbon::parse($data['scheduled_at'])->toDateString();
            $appointment = Appointment::create($data + [
                'branch_id' => $request->user()->branch_id,
                'created_by' => $request->user()->id,
                'arrived_at' => ($data['status'] ?? 'booked') === 'arrived' ? now() : null,
                // Next number in this doctor's line for that day.
                'token_no' => (int) Appointment::where('doctor_id', $data['doctor_id'])->whereDate('scheduled_at', $day)->lockForUpdate()->max('token_no') + 1,
            ]);

            $service = Service::find($data['service_id'] ?? null);
            if ($service && ! ($billing['skip_billing'] ?? false)) {
                $quantity = (int) ($billing['quantity'] ?? 1);
                $price = (float) ($billing['unit_price'] ?? $service->price);
                if (abs($price - (float) $service->price) > 0.004 && ! $request->user()->allows('canChangePrice')) {
                    throw ValidationException::withMessages(['unit_price' => 'You are not allowed to change the price. It is ₹'.(float) $service->price.' on the price list.']);
                }
                $discount = (float) ($billing['discount'] ?? 0);
                if ($discount > $price * $quantity) {
                    throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the charge.']);
                }

                $bill = Bill::create([
                    'branch_id' => $appointment->branch_id,
                    'patient_id' => $appointment->patient_id,
                    'appointment_id' => $appointment->id,
                    'kind' => 'appointment',
                    'created_by' => $request->user()->id,
                ]);
                if ($discount > 0) {
                    DiscountWhy::need($bill, $billing);
                }
                $bill->addLine($service->name, $price, $quantity, $service->gst_percent, $service->id, $discount);

                $paid = (float) ($billing['paid'] ?? 0);
                if ($paid > $bill->fresh()->total) {
                    throw ValidationException::withMessages(['paid' => 'That is more than the bill amount.']);
                }
                if ($paid > 0) {
                    $bill->payments()->create(['type' => 'payment', 'amount' => $paid, 'mode' => $billing['payment_mode'], 'received_by' => $request->user()->id]);
                    $bill->recalculate();
                }
            }

            return $appointment;
        });

        return response()->json($appointment->fresh()->load(self::WITH)->load(self::extras()), 201);
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(array_map(fn ($rule) => array_merge(['sometimes'], $rule), $this->rules()));
        $anyway = $request->boolean('outside_hours');

        // Moving to another time or doctor has to respect leave and full slots; other edits do not.
        $at = Carbon::parse($data['scheduled_at'] ?? $appointment->getRawOriginal('scheduled_at'));
        $doctorId = (int) ($data['doctor_id'] ?? $appointment->doctor_id);
        $moved = $doctorId !== (int) $appointment->doctor_id || ! $at->equalTo(Carbon::parse($appointment->getRawOriginal('scheduled_at')));
        if ($moved && ($data['status'] ?? $appointment->status) !== 'cancelled') {
            $this->guardSlot($doctorId, $at, $appointment->id, ($data['status'] ?? $appointment->status) === 'booked' && ! $anyway);
        }

        // A doctor's own fee is not carried to another doctor. Looked at only when the doctor or the service changes.
        // A bill already made for the visit is left as it is.
        $serviceId = array_key_exists('service_id', $data) ? $data['service_id'] : $appointment->service_id;
        if ((int) $serviceId !== (int) $appointment->service_id) {
            DoctorFee::fits($serviceId ? (int) $serviceId : null, $doctorId);
        } elseif ($doctorId !== (int) $appointment->doctor_id && $serviceId && Service::whereKey($serviceId)->whereNotNull('doctor_id')->where('doctor_id', '!=', $doctorId)->exists()) {
            // Moved to another doctor with nothing said about the service: the visit takes the new doctor's own.
            $data['service_id'] = DoctorFee::of($doctorId)?->id;
        }

        // Record when the patient first reached the hospital, for wait-time reporting later.
        if (($data['status'] ?? null) === 'arrived' && ! $appointment->arrived_at) {
            $data['arrived_at'] = now();
        }

        $appointment->update($data);

        return response()->json($appointment->load(self::WITH)->load(self::extras()));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'doctor_id' => ['required', Rule::exists('users', 'id')->where('role', Role::Doctor->value)->where('is_active', true)],
            'service_id' => ['nullable', Rule::exists('services', 'id')->where('type', 'appointment')],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'status' => ['nullable', Rule::in(Appointment::STATUSES)],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
