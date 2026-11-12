<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillController extends Controller
{
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

        DB::transaction(function () use ($bill, $data, $excess, $request) {
            if ($excess > 0) {
                $bill->payments()->create(['type' => 'refund', 'amount' => $excess, 'mode' => $data['refund_mode'], 'reference' => 'Discount given after payment', 'received_by' => $request->user()->id]);
            }
            $bill->discount = $data['discount'];
            $bill->recalculate();
        });

        return response()->json($this->present($bill));
    }

    /** Adds a charge: a service from the price list, or anything typed in, with its own price, quantity and discount. */
    public function addItem(Request $request, Bill $bill): JsonResponse
    {
        $this->addLineFrom($bill, $request->validate($this->lineRules('required_without')));

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

        return response()->json($this->present($bill), 201);
    }

    private function present(Bill $bill): Bill
    {
        return $bill->fresh()->load([
            'items', 'payments.receiver:id,name', 'labOrder:id,bill_id,order_no', 'patient:id,uhid,name,gender,date_of_birth,mobile,address,city',
            'appointment:id,doctor_id,scheduled_at', 'appointment.doctor:id,name',
        ]);
    }
}
