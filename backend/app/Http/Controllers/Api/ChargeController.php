<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Patient;
use App\Models\Payment;
use App\Support\DiscountWhy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Billing a patient from the front desk in one go: services and lab tests chosen together and
 * saved together, then everything the patient owes for the visit collected in one payment.
 *
 * The bills themselves are made by the same code as everywhere else (a services bill, and a lab
 * order with its own bill), so prices, permissions, the lab's work list and the reports all
 * behave as before. This only saves the desk the walking between screens.
 */
class ChargeController extends Controller
{
    /** Saves the chosen services as one bill and the chosen lab tests as one lab order, all or nothing. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'appointment_id' => ['nullable', Rule::exists('appointments', 'id')->where('patient_id', $request->input('patient_id'))],
            'services' => ['sometimes', 'array', 'max:40'],
            'services.*' => ['array'],
            'test_ids' => ['sometimes', 'array', 'max:60'],
            'package_ids' => ['sometimes', 'array', 'max:20'],
            'referred_by' => ['nullable', 'string', 'max:255'],
        ] + DiscountWhy::RULES, ['appointment_id.exists' => 'That visit belongs to another patient.']);

        $services = array_values($data['services'] ?? []);
        $hasLab = ! empty($data['test_ids']) || ! empty($data['package_ids']);
        if (! $services && ! $hasLab) {
            throw ValidationException::withMessages(['services' => 'Choose at least one service or lab test.']);
        }

        $made = [];
        if ($services) {
            $about = ['patient_id' => $data['patient_id'], 'appointment_id' => $data['appointment_id'] ?? null];
            // Why any discount on these lines is given, and who allowed it: asked once for the whole bill.
            $why = array_filter(['discount_reason' => $data['discount_reason'] ?? null, 'discount_allowed_by' => $data['discount_allowed_by'] ?? null]);
            $bills = app(BillController::class);
            $billId = $bills->store($this->ask($request, $about + $services[0] + $why))->getData(true)['id'];
            foreach (array_slice($services, 1) as $line) {
                $bills->addItem($this->ask($request, $line + $why), Bill::findOrFail($billId));
            }
            $made[] = $billId;
        }
        if ($hasLab) {
            $order = app(LabController::class)->storeOrder($this->ask($request, [
                'patient_id' => $data['patient_id'], 'appointment_id' => $data['appointment_id'] ?? null,
                'test_ids' => $data['test_ids'] ?? [], 'package_ids' => $data['package_ids'] ?? [], 'referred_by' => $data['referred_by'] ?? null,
            ]))->getData(true);
            $made[] = $order['bill_id'];
        }

        return response()->json(['made' => $made, 'due' => $this->present($this->owed((int) $data['patient_id'], $data['appointment_id'] ?? null))], 201);
    }

    /**
     * Everything billed to one patient on one day, paid or not: each bill with its lines, so the desk sees
     * all that was ordered in one place instead of opening the bills one by one. A bill belongs to the day
     * it was made on, and a visit's bill also to the day of the visit, since that is where the day list shows it.
     */
    public function day(Request $request): JsonResponse
    {
        $data = $request->validate(['patient_id' => ['required', 'exists:patients,id'], 'date' => ['nullable', 'date']]);
        $day = Carbon::parse($data['date'] ?? today())->toDateString();

        $bills = Bill::where('patient_id', $data['patient_id'])
            ->where(fn ($q) => $q->whereDate('created_at', $day)->orWhereHas('appointment', fn ($a) => $a->whereDate('scheduled_at', $day)))
            ->with(['items', 'payments', 'labOrder:id,bill_id,order_no', 'appointment:id,doctor_id,scheduled_at', 'appointment.doctor:id,name'])
            ->withIpdTransfers()->oldest('id')->get();

        return response()->json([
            'date' => $day,
            'patient' => Patient::find($data['patient_id'])->only(['id', 'uhid', 'title', 'name', 'gender', 'age_label', 'mobile']),
            'bills' => $bills->map(fn (Bill $b) => [
                'id' => $b->id, 'bill_no' => $b->bill_no, 'kind' => $b->kind, 'order_no' => $b->labOrder?->order_no,
                'created_at' => $b->created_at->format('Y-m-d H:i'), 'appointment_id' => $b->appointment_id, 'doctor' => $b->appointment?->doctor?->name,
                'subtotal' => (float) $b->subtotal, 'discount' => (float) $b->discount, 'tax' => (float) $b->tax,
                'total' => (float) $b->total, 'paid' => (float) $b->paid, 'due' => (float) $b->due, 'moved_to_ipd' => (float) $b->moved_to_ipd,
                'cancelled_at' => $b->cancelled_at?->format('Y-m-d H:i'), 'cancel_reason' => $b->cancel_reason,
                'items' => $b->items->map(fn ($i) => ['id' => $i->id, 'description' => $i->description, 'quantity' => (float) $i->quantity, 'unit_price' => (float) $i->unit_price, 'discount' => (float) $i->discount, 'amount' => round((float) $i->amount + (float) $i->tax, 2)])->all(),
                'payments' => $b->payments->map(fn (Payment $p) => ['type' => $p->type, 'mode' => $p->mode, 'amount' => (float) $p->amount])->all(),
            ])->all(),
            'total' => round((float) $bills->sum('total'), 2), 'paid' => round((float) $bills->sum('paid'), 2), 'due' => round((float) $bills->sum('due'), 2),
        ]);
    }

    /** What the patient still owes for this visit, or for today when there is no visit. */
    public function due(Request $request): JsonResponse
    {
        $data = $request->validate(['patient_id' => ['required', 'exists:patients,id'], 'appointment_id' => ['nullable', 'integer']]);

        return response()->json(['due' => $this->present($this->owed((int) $data['patient_id'], $data['appointment_id'] ?? null))]);
    }

    /**
     * Takes one payment and puts it against several bills of the same patient, in the order given.
     * Each bill gets its own receipt line, exactly as if it had been paid on its own.
     */
    public function collect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'appointment_id' => ['nullable', 'integer'],
            'bill_ids' => ['required', 'array', 'min:1', 'max:20'],
            'bill_ids.*' => ['integer', 'distinct'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(Payment::MODES)],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $bills = Bill::whereIn('id', $data['bill_ids'])->where('patient_id', $data['patient_id'])->lockForUpdate()->get()->keyBy('id');
        if ($bills->count() !== count($data['bill_ids'])) {
            throw ValidationException::withMessages(['bill_ids' => 'One of these bills is not this patient’s. Close this and open it again.']);
        }
        $owed = round((float) $bills->sum('due'), 2);
        $left = round((float) $data['amount'], 2);
        if ($left > $owed + 0.004) {
            throw ValidationException::withMessages(['amount' => 'Only ₹'.rtrim(rtrim(number_format($owed, 2, '.', ''), '0'), '.').' is due on these bills.']);
        }

        $controller = app(BillController::class);
        foreach ($data['bill_ids'] as $id) {
            $bill = $bills[$id];
            $part = min($left, round((float) $bill->due, 2));
            if ($part <= 0) {
                continue;
            }
            $controller->addPayment($this->ask($request, ['type' => 'payment', 'amount' => $part, 'mode' => $data['mode'], 'reference' => $data['reference'] ?? null]), $bill);
            $left = round($left - $part, 2);
        }

        return response()->json(['due' => $this->present($this->owed((int) $data['patient_id'], $data['appointment_id'] ?? null))]);
    }

    /**
     * A request for one of the existing billing actions, made on behalf of the person signed in.
     *
     * @param  array<string, mixed>  $input
     */
    private function ask(Request $request, array $input): Request
    {
        $ask = Request::create($request->path(), 'POST', $input);
        $ask->setUserResolver($request->getUserResolver());

        return $ask;
    }

    /**
     * Bills with money still due: those of the visit, or with no visit those made today.
     *
     * @return Collection<int, Bill>
     */
    private function owed(int $patientId, ?int $appointmentId): Collection
    {
        return Bill::where('patient_id', $patientId)
            ->when($appointmentId, fn ($q) => $q->where('appointment_id', $appointmentId), fn ($q) => $q->whereDate('created_at', today()))
            ->with(['items:id,bill_id,description,amount', 'labOrder:id,bill_id,order_no'])->oldest('id')->get()
            ->filter(fn (Bill $b) => $b->due > 0)->values();
    }

    /**
     * @param  Collection<int, Bill>  $bills
     * @return list<array<string, mixed>>
     */
    private function present(Collection $bills): array
    {
        return $bills->map(fn (Bill $b) => [
            'id' => $b->id, 'bill_no' => $b->bill_no, 'kind' => $b->kind, 'order_no' => $b->labOrder?->order_no,
            'total' => (float) $b->total, 'paid' => (float) $b->paid, 'due' => (float) $b->due,
            'items' => $b->items->pluck('description')->all(),
        ])->all();
    }
}
