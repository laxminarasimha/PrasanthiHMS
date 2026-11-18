<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Patient;
use App\Models\PatientDeposit;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillController extends Controller
{
    /** Front desk and lab bills in a date range, or matching a search across all dates, newest first. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::in(['appointment', 'lab', 'other'])],
            'due' => ['sometimes', 'boolean'],
        ]);
        $term = trim((string) ($filters['q'] ?? ''));
        $from = Carbon::parse($filters['from'] ?? today())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? $filters['from'] ?? today())->endOfDay();

        return response()->json(
            Bill::query()
                ->when($term === '', fn ($q) => $q->whereBetween('created_at', [$from, $to]))
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('bill_no', 'like', "{$term}%")
                    ->orWhereHas('patient', fn ($p) => $p->where('name', 'like', "%{$term}%")->orWhere('mobile', 'like', "{$term}%")->orWhere('uhid', 'like', "{$term}%"))))
                ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
                ->when($request->boolean('due'), fn ($q) => $q->whereColumn('paid', '<', 'total'))
                ->with(['patient:id,uhid,title,name,mobile', 'appointment:id,doctor_id', 'appointment.doctor:id,name', 'labOrder:id,bill_id,order_no'])
                ->withIpdTransfers()->latest()->latest('id')->limit(500)->get()
        );
    }

    /** Pays some or all of a bill from the money the patient has on deposit. */
    public function useDeposit(Request $request, Bill $bill): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0']]);

        DB::transaction(function () use ($bill, $data, $request) {
            // Lock the patient so two desks cannot spend the same deposit.
            Patient::whereKey($bill->patient_id)->lockForUpdate()->first();
            $balance = PatientDeposit::balance($bill->patient_id);
            if ($data['amount'] > $balance) {
                throw ValidationException::withMessages(['amount' => "Only ₹{$balance} is on deposit."]);
            }
            if ($data['amount'] > $bill->due) {
                throw ValidationException::withMessages(['amount' => "Only ₹{$bill->due} is due on this bill."]);
            }

            PatientDeposit::create(['patient_id' => $bill->patient_id, 'type' => 'used', 'amount' => $data['amount'], 'bill_id' => $bill->id, 'reference' => "Bill {$bill->bill_no}", 'received_by' => $request->user()->id]);
            // Recorded on the bill as paid by deposit; the money itself was counted when the deposit was taken.
            $bill->payments()->create(['type' => 'payment', 'amount' => $data['amount'], 'mode' => 'deposit', 'received_by' => $request->user()->id]);
            $bill->recalculate();
        });

        return response()->json($this->present($bill), 201);
    }

    public function show(Bill $bill): JsonResponse
    {
        return response()->json($this->present($bill));
    }

    /**
     * Opens another bill: for a visit, or for a walk-in buying a service ("other").
     * A first charge can be given at the same time.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'kind' => ['nullable', Rule::in(['appointment', 'other'])],
        ] + $this->lineRules('sometimes'));

        $bill = DB::transaction(function () use ($data, $request) {
            $bill = Bill::create([
                'patient_id' => $data['patient_id'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'kind' => $data['kind'] ?? (($data['appointment_id'] ?? null) ? 'appointment' : 'other'),
                'branch_id' => $request->user()->branch_id,
                'created_by' => $request->user()->id,
            ]);

            if (isset($data['service_id']) || isset($data['description'])) {
                $this->addLineFrom($bill, $data);
            }

            return $bill;
        });
        AuditLog::record('bill.created', $bill->patient_id, $bill, 'Bill '.$bill->fresh()->bill_no);

        return response()->json($this->present($bill), 201);
    }

    /**
     * Changes the bill discount. If the patient has already paid more than the bill is then worth,
     * `refund_excess` returns the difference in the same step ("give refund and add to discount").
     */
    public function update(Request $request, Bill $bill): JsonResponse
    {
        $data = $request->validate([
            'discount' => ['required', 'numeric', 'min:0'],
            'refund_excess' => ['sometimes', 'boolean'],
            'refund_mode' => ['nullable', 'required_if:refund_excess,true', Rule::in(Payment::MODES)],
        ]);

        $worth = $bill->subtotal + $bill->tax - $data['discount'];
        if ($worth < 0) {
            throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the bill amount.']);
        }

        $excess = round($bill->paid - $worth, 2);
        if ($excess > 0 && ! ($data['refund_excess'] ?? false)) {
            throw ValidationException::withMessages(['discount' => "This discount would bring the bill below what is already paid. Refund ₹{$excess} as well, or refund first."]);
        }

        $before = round((float) $bill->discount, 2);
        $wasWorth = round((float) $bill->total, 2);

        DB::transaction(function () use ($bill, $data, $excess, $request) {
            if ($excess > 0) {
                $bill->payments()->create(['type' => 'refund', 'amount' => $excess, 'mode' => $data['refund_mode'], 'reference' => 'Discount given after payment', 'received_by' => $request->user()->id]);
            }
            $bill->discount = $data['discount'];
            $bill->recalculate();
        });

        // Saving the same discount again changes nothing, so it leaves no line in the log.
        $after = round((float) $bill->discount, 2);
        AuditLog::$written = true;
        if ($after !== $before) {
            $money = fn (float $amount) => '₹'.number_format($amount, 2);
            AuditLog::record('bill.discount', $bill->patient_id, $bill, "{$bill->bill_no} · discount {$money($before)} → {$money($after)} · bill {$money($wasWorth)} → {$money((float) $bill->total)}"
                .($excess > 0 ? " · {$money($excess)} refunded" : ''));
        }

        return response()->json($this->present($bill));
    }

    /** Adds a charge: a service from the price list, or anything typed in, with its own price, quantity and discount. */
    public function addItem(Request $request, Bill $bill): JsonResponse
    {
        $this->addLineFrom($bill, $data = $request->validate($this->lineRules('required_without')));
        AuditLog::record('bill.item_added', $bill->patient_id, $bill, 'Bill '.$bill->bill_no.': '.($data['description'] ?? Service::find($data['service_id'] ?? null)?->name ?? 'a charge'));

        return response()->json($this->present($bill), 201);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function lineRules(string $mode): array
    {
        return [
            'service_id' => ['nullable', $mode === 'required_without' ? 'required_without:description' : 'sometimes', Rule::exists('services', 'id')->where('is_active', true)],
            'description' => ['nullable', 'string', 'max:255'],
            'unit_price' => ['nullable', 'required_without:service_id', 'numeric', 'min:0', 'max:9999999'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'line_discount' => ['nullable', 'numeric', 'min:0'],
            'gst_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addLineFrom(Bill $bill, array $data): void
    {
        $service = Service::find($data['service_id'] ?? null);
        $quantity = (int) ($data['quantity'] ?? 1);
        $price = (float) ($data['unit_price'] ?? $service?->price ?? 0);
        if ($service && abs($price - (float) $service->price) > 0.004 && ! request()->user()->allows('canChangePrice')) {
            throw ValidationException::withMessages(['unit_price' => 'You are not allowed to change the price. It is ₹'.(float) $service->price.' on the price list.']);
        }
        $discount = (float) ($data['line_discount'] ?? 0);

        if ($discount > $price * $quantity) {
            throw ValidationException::withMessages(['line_discount' => 'The discount cannot be more than the charge.']);
        }

        $bill->addLine(
            ($data['description'] ?? null) ?: $service->name,
            $price,
            $quantity,
            (float) ($data['gst_percent'] ?? $service?->gst_percent ?? 0),
            $service?->id,
            $discount,
        );
    }

    public function removeItem(Bill $bill, BillItem $item): JsonResponse
    {
        abort_unless($item->bill_id === $bill->id, 404);

        $remaining = $bill->subtotal + $bill->tax - $item->amount - $item->tax;
        if ($remaining - $bill->discount < $bill->paid) {
            throw ValidationException::withMessages(['item' => 'Removing this would bring the bill below what is already paid. Refund the difference first.']);
        }

        AuditLog::record('bill.item_removed', $bill->patient_id, $bill, 'Bill '.$bill->bill_no.': '.$item->description.', ₹'.(float) $item->amount);
        $item->delete();
        $bill->recalculate();

        return response()->json($this->present($bill));
    }

    public function addPayment(Request $request, Bill $bill): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['payment', 'refund'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(Payment::MODES)],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['type'] === 'payment' && $data['amount'] > $bill->due) {
            throw ValidationException::withMessages(['amount' => "Only ₹{$bill->due} is due on this bill."]);
        }
        if ($data['type'] === 'refund' && $data['amount'] > $bill->paid) {
            throw ValidationException::withMessages(['amount' => "Only ₹{$bill->paid} has been paid on this bill."]);
        }

        $bill->payments()->create($data + ['received_by' => $request->user()->id]);
        $bill->recalculate();
        AuditLog::record('bill.payment', $bill->patient_id, $bill, 'Bill '.$bill->bill_no.': '.($data['type'] === 'refund' ? 'refunded' : 'received').' ₹'.(float) $data['amount'].' by '.$data['mode']);

        return response()->json($this->present($bill), 201);
    }

    /**
     * Corrects how a payment or refund was taken when it was entered under the wrong mode: cash put down as UPI, and the like.
     * The amount and the day stay as they are, and the change is kept in the record with who made it and why.
     */
    public function updatePayment(Request $request, Bill $bill, Payment $payment): JsonResponse
    {
        abort_unless($request->user()->allows('canWorkFrontDesk') || $request->user()->allows('canRunLab'), 403, 'You are not allowed to change payments on bills.');
        abort_unless($payment->bill_id === $bill->id, 404);

        $data = $request->validate([
            'mode' => ['required', Rule::in(Payment::MODES)],
            'reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], ['reason.required' => 'Say why the payment mode is being changed.']);

        // Money taken from a deposit, or moved onto an IPD bill, was never cash or card at the counter.
        if (! in_array($payment->mode, Payment::MODES, true)) {
            throw ValidationException::withMessages(['mode' => 'This entry is not money taken at the counter, so its mode cannot be changed.']);
        }
        $reference = $data['reference'] ?? null;
        if ($payment->mode === $data['mode'] && $payment->reference === $reference) {
            throw ValidationException::withMessages(['mode' => 'That is already how this payment is recorded.']);
        }

        $was = $payment->mode;
        $payment->update(['mode' => $data['mode'], 'reference' => $reference]);
        AuditLog::record('bill.payment_mode', $bill->patient_id, $bill, 'Bill '.$bill->bill_no.': '.($payment->type === 'refund' ? 'refund' : 'payment').' of ₹'.(float) $payment->amount
            .' on '.$payment->created_at->format('j M Y').' changed from '.$was.' to '.$data['mode'].($reference ? " ({$reference})" : '').'. Reason: '.$data['reason']);

        return response()->json($this->present($bill));
    }

    private function present(Bill $bill): Bill
    {
        $bill = $bill->fresh()->load([
            'items', 'payments.receiver:id,name', 'labOrder:id,bill_id,order_no', 'patient:id,uhid,title,name,gender,date_of_birth,mobile,address,city',
            'appointment:id,doctor_id,scheduled_at', 'appointment.doctor:id,name',
        ]);
        // What the patient has on deposit, so the desk can offer to use it.
        $bill->setAttribute('deposit_balance', PatientDeposit::balance($bill->patient_id));

        return $bill;
    }
}
