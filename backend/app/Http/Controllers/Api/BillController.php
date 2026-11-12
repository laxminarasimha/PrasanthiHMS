<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillController extends Controller
{
    public function show(Bill $bill): JsonResponse
    {
        return response()->json($this->present($bill));
    }

    /** Opens a bill that is not tied to an appointment, e.g. a walk-in buying a service. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
        ]);

        $bill = Bill::create($data + [
            'branch_id' => $request->user()->branch_id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($this->present($bill), 201);
    }

    public function update(Request $request, Bill $bill): JsonResponse
    {
        $data = $request->validate(['discount' => ['required', 'numeric', 'min:0']]);

        if ($data['discount'] > $bill->subtotal + $bill->tax) {
            throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the bill amount.']);
        }
        if ($bill->subtotal + $bill->tax - $data['discount'] < $bill->paid) {
            throw ValidationException::withMessages(['discount' => 'This discount would bring the bill below what is already paid. Refund the difference first.']);
        }

        $bill->discount = $data['discount'];
        $bill->recalculate();

        return response()->json($this->present($bill));
    }

    public function addItem(Request $request, Bill $bill): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', Rule::exists('services', 'id')->where('is_active', true)],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $bill->addService(Service::findOrFail($data['service_id']), (int) ($data['quantity'] ?? 1));

        return response()->json($this->present($bill), 201);
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
            'items', 'payments.receiver:id,name', 'patient:id,uhid,name,gender,date_of_birth,mobile,address,city',
            'appointment:id,doctor_id,scheduled_at', 'appointment.doctor:id,name',
        ]);
    }
}
