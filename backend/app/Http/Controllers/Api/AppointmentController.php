<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    private const WITH = ['patient:id,uhid,name,gender,date_of_birth,mobile', 'doctor:id,name', 'service:id,name', 'bills.labOrder:id,bill_id,order_no'];

    /**
     * The bills on a visit, each with the amount moved to an IPD bill.
     *
     * @return array<string, \Closure>
     */
    private static function bills(): array
    {
        return ['bills' => fn ($bills) => $bills->select('id', 'appointment_id', 'bill_no', 'kind', 'total', 'paid')->withIpdTransfers()];
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
                ->orWhere('uhid', 'like', "{$term}%")));

        return response()->json([
            'counts' => (clone $day)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'data' => $day->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->with(self::WITH)->with(self::bills())->orderBy('scheduled_at')->orderBy('id')->get(),
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

        return response()->json([
            'settings' => Setting::group('calendar', Setting::CALENDAR_DEFAULTS),
            'data' => Appointment::query()
                ->whereDate('scheduled_at', '>=', $from)
                ->whereDate('scheduled_at', '<=', $from->copy()->addDays((int) ($filters['days'] ?? 1) - 1))
                ->when($filters['doctor_id'] ?? null, fn ($q, $id) => $q->where('doctor_id', $id))
                ->with(self::WITH)->with(self::bills())->orderBy('scheduled_at')->orderBy('id')->get(),
        ]);
    }

    public function saveCalendarSettings(Request $request): JsonResponse
    {
        $time = ['required', 'date_format:H:i'];
        $data = $request->validate([
            'slot_minutes' => ['required', 'integer', Rule::in([5, 10, 15, 20, 30, 60])],
            'morning_start' => $time,
            'morning_end' => [...$time, 'after:morning_start'],
            'evening_start' => [...$time, 'after_or_equal:morning_end'],
            'evening_end' => [...$time, 'after:evening_start'],
        ]);

        Setting::updateOrCreate(['key' => 'calendar'], ['value' => $data]);

        return response()->json(Setting::group('calendar', Setting::CALENDAR_DEFAULTS));
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
        ]);

        $billing = array_intersect_key($data, array_flip(['skip_billing', 'unit_price', 'quantity', 'discount', 'paid', 'payment_mode']));
        $data = array_diff_key($data, $billing);

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

        return response()->json($appointment->fresh()->load(self::WITH)->load(self::bills()), 201);
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(array_map(fn ($rule) => array_merge(['sometimes'], $rule), $this->rules()));

        // Record when the patient first reached the hospital, for wait-time reporting later.
        if (($data['status'] ?? null) === 'arrived' && ! $appointment->arrived_at) {
            $data['arrived_at'] = now();
        }

        $appointment->update($data);

        return response()->json($appointment->load(self::WITH)->load(self::bills()));
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
