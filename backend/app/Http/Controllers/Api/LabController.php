<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LabController extends Controller
{
    private const ORDER_WITH = ['patient:id,uhid,name,gender,date_of_birth,mobile', 'items:id,lab_order_id,test_name,reported_at', 'bill:id,bill_no,total,paid'];

    // ---- Test catalogue ----

    public function tests(Request $request): JsonResponse
    {
        return response()->json(
            LabTest::with('parameters')
                ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
                ->orderBy('print_order')->orderBy('name')
                ->get()
        );
    }

    public function storeTest(Request $request): JsonResponse
    {
        $this->authorizeLab($request);

        return response()->json($this->saveTest(new LabTest, $request), 201);
    }

    public function updateTest(Request $request, LabTest $test): JsonResponse
    {
        $this->authorizeLab($request);

        return response()->json($this->saveTest($test, $request));
    }

    private function saveTest(LabTest $test, Request $request): LabTest
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'sample_type' => ['nullable', 'string', 'max:50'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'print_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'is_active' => ['sometimes', 'boolean'],
            'parameters' => ['required', 'array', 'min:1', 'max:80'],
            'parameters.*.name' => ['required', 'string', 'max:255'],
            'parameters.*.unit' => ['nullable', 'string', 'max:30'],
            'parameters.*.reference_range' => ['nullable', 'string', 'max:255'],
            'parameters.*.method' => ['nullable', 'string', 'max:255'],
        ], [
            'parameters.required' => 'Add at least one parameter to report.',
            'parameters.*.name.required' => 'Name every parameter or remove the empty row.',
        ]);

        return DB::transaction(function () use ($test, $data) {
            $parameters = $data['parameters'];
            unset($data['parameters']);
            $test->fill($data + ['print_order' => 0])->save();

            $test->parameters()->delete();
            foreach (array_values($parameters) as $position => $row) {
                $test->parameters()->create($row + ['position' => $position]);
            }

            return $test->load('parameters');
        });
    }

    // ---- Orders ----

    /** One day's lab orders; with `q` every matching order; with `patient_id` that patient's recent orders and results. */
    public function orders(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['pending', 'partial', 'completed'])],
            'patient_id' => ['nullable', 'integer'],
        ]);
        $patientId = $filters['patient_id'] ?? null;
        $term = trim((string) ($filters['q'] ?? ''));

        $orders = LabOrder::query()
            ->when($patientId, fn ($q) => $q->where('patient_id', $patientId))
            ->when($term === '' && ! $patientId, fn ($q) => $q->whereDate('ordered_at', $filters['date'] ?? today()))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('order_no', 'like', "{$term}%")
                ->orWhereHas('patient', fn ($p) => $p->where('name', 'like', "%{$term}%")
                    ->orWhere('mobile', 'like', "{$term}%")
                    ->orWhere('uhid', 'like', "{$term}%"))))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            // A patient's history is shown with the values, so the doctor can read them in the visit pad.
            ->with($patientId ? ['items.results', 'bill:id,bill_no,total,paid'] : self::ORDER_WITH)
            ->latest('ordered_at')->latest('id')
            ->limit($patientId ? 15 : 200)
            ->get();

        return response()->json($orders);
    }

    /** Creates the order, a result sheet for every test, and the bill, in one step. */
    public function storeOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'referred_by' => ['nullable', 'string', 'max:255'],
            'test_ids' => ['required', 'array', 'min:1', 'max:60'],
            'test_ids.*' => ['integer', 'distinct', Rule::exists('lab_tests', 'id')->where('is_active', true)],
        ], ['test_ids.required' => 'Choose at least one test.']);

        $order = DB::transaction(function () use ($data, $request) {
            $user = $request->user();
            $bill = Bill::create([
                'branch_id' => $user->branch_id,
                'patient_id' => $data['patient_id'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'created_by' => $user->id,
            ]);

            $order = LabOrder::create([
                'branch_id' => $user->branch_id,
                'patient_id' => $data['patient_id'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'bill_id' => $bill->id,
                'referred_by' => $data['referred_by'] ?? null,
                'ordered_at' => now(),
                'created_by' => $user->id,
            ]);

            $tests = LabTest::with('parameters')->whereIn('id', $data['test_ids'])->orderBy('print_order')->orderBy('name')->get();
            foreach ($tests as $test) {
                $item = $order->items()->create([
                    'lab_test_id' => $test->id,
                    'test_name' => $test->name,
                    'department' => $test->department,
                    'sample_type' => $test->sample_type,
                    'price' => $test->price,
                ]);
                foreach ($test->parameters as $parameter) {
                    $item->results()->create([
                        'parameter' => $parameter->name,
                        'unit' => $parameter->unit,
                        'reference_range' => $parameter->reference_range,
                        'method' => $parameter->method,
                        'position' => $parameter->position,
                    ]);
                }
                $bill->addLine($test->name, $test->price);
            }

            return $order;
        });

        return response()->json($this->present($order), 201);
    }

    public function showOrder(LabOrder $order): JsonResponse
    {
        return response()->json($this->present($order));
    }

    /** Saves the values for one test; `complete` marks that test as reported. */
    public function saveResults(Request $request, LabOrder $order, LabOrderItem $item): JsonResponse
    {
        $this->authorizeLab($request);
        abort_unless($item->lab_order_id === $order->id, 404);

        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
            'complete' => ['sometimes', 'boolean'],
            'results' => ['present', 'array'],
            'results.*.id' => ['required', 'integer'],
            'results.*.value' => ['nullable', 'string', 'max:255'],
            'results.*.flag' => ['nullable', Rule::in(['high', 'low'])],
        ]);

        DB::transaction(function () use ($data, $item, $order, $request) {
            $rows = $item->results()->get()->keyBy('id');
            foreach ($data['results'] as $entry) {
                $rows->get($entry['id'])?->update(['value' => $entry['value'] ?? null, 'flag' => $entry['flag'] ?? null]);
            }

            $item->remarks = $data['remarks'] ?? null;
            if (array_key_exists('complete', $data)) {
                $item->reported_at = $data['complete'] ? ($item->reported_at ?? now()) : null;
                $item->reported_by = $data['complete'] ? $request->user()->id : null;
            }
            $item->save();
            $order->refreshStatus();
        });

        return response()->json($this->present($order));
    }

    private function authorizeLab(Request $request): void
    {
        abort_unless($request->user()->role->canRunLab(), 403, 'You are not allowed to do this in the lab.');
    }

    private function present(LabOrder $order): LabOrder
    {
        return $order->fresh()->load([
            'patient:id,uhid,name,gender,date_of_birth,mobile', 'items.results', 'items.reporter:id,name,signature_text',
            'bill:id,bill_no,total,paid',
        ]);
    }
}
