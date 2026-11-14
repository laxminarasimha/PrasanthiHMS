<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\LabOrder;
use App\Models\LabOrderFile;
use App\Models\LabOrderItem;
use App\Models\LabPackage;
use App\Models\LabTest;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LabController extends Controller
{
    private const ORDER_WITH = ['patient:id,uhid,title,name,gender,date_of_birth,mobile', 'items:id,lab_order_id,test_name,package_name,collected_at,reported_at,approved_at'];

    // ---- Test catalogue ----

    public function tests(Request $request): JsonResponse
    {
        return response()->json(
            // Screens that only pick tests ask for the short list, without each test's report template.
            LabTest::when(! $request->boolean('brief'), fn ($q) => $q->with('parameters'))
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

    /** Sets the order tests appear in pick lists and on printed reports: the ids given, first to last. */
    public function orderTests(Request $request): JsonResponse
    {
        $this->authorizeLab($request);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:5000'], 'ids.*' => ['integer', 'distinct', 'exists:lab_tests,id']]);

        DB::transaction(function () use ($data) {
            foreach (array_values($data['ids']) as $index => $id) {
                LabTest::whereKey($id)->update(['print_order' => $index + 1]);
            }
        });

        return $this->tests($request->merge(['all' => true]));
    }

    private function saveTest(LabTest $test, Request $request): LabTest
    {
        $text = $request->input('kind') === 'text';

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['nullable', Rule::in(['values', 'text'])],
            'department' => ['nullable', 'string', 'max:255'],
            'sample_type' => ['nullable', 'string', 'max:50'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'print_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'default_comment' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            // A written report (ECG, X-ray) has no parameters; a values report needs at least one.
            'parameters' => [$text ? 'nullable' : 'required', 'array', $text ? 'min:0' : 'min:1', 'max:120'],
            'parameters.*.name' => ['required', 'string', 'max:255'],
            'parameters.*.is_heading' => ['sometimes', 'boolean'],
            'parameters.*.unit' => ['nullable', 'string', 'max:30'],
            'parameters.*.reference_range' => ['nullable', 'string', 'max:255'],
            'parameters.*.method' => ['nullable', 'string', 'max:255'],
        ], [
            'parameters.required' => 'Add at least one parameter to report.',
            'parameters.*.name.required' => 'Name every parameter or remove the empty row.',
        ]);

        return DB::transaction(function () use ($test, $data, $text) {
            $parameters = $text ? [] : ($data['parameters'] ?? []);
            unset($data['parameters']);
            $test->fill($data + ['print_order' => 0, 'kind' => 'values'])->save();

            $test->parameters()->delete();
            foreach (array_values($parameters) as $position => $row) {
                $test->parameters()->create($row + ['position' => $position]);
            }

            return $test->load('parameters');
        });
    }

    // ---- Packages: several tests sold together for one price ----

    public function packages(Request $request): JsonResponse
    {
        return response()->json(
            LabPackage::with('tests:id,name,price')->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))->orderBy('name')->get()
        );
    }

    public function storePackage(Request $request): JsonResponse
    {
        $this->authorizeLab($request);

        return response()->json($this->savePackage(new LabPackage, $request), 201);
    }

    public function updatePackage(Request $request, LabPackage $package): JsonResponse
    {
        $this->authorizeLab($request);

        return response()->json($this->savePackage($package, $request));
    }

    private function savePackage(LabPackage $package, Request $request): LabPackage
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'is_active' => ['sometimes', 'boolean'],
            'test_ids' => ['required', 'array', 'min:2', 'max:80'],
            'test_ids.*' => ['integer', 'distinct', 'exists:lab_tests,id'],
        ], ['test_ids.min' => 'A package needs at least two tests.']);

        return DB::transaction(function () use ($package, $data) {
            $package->fill($data)->save();
            $package->tests()->sync($data['test_ids']);

            return $package->load('tests:id,name,price');
        });
    }

    // ---- Lab preferences ----

    public function settings(): JsonResponse
    {
        return response()->json($this->labSettings());
    }

    /**
     * The lab preferences as the screens need them: signature images are sent as embedded pictures
     * so the printed report can show them, and where they are stored is left out.
     *
     * @return array<string, mixed>
     */
    private function labSettings(): array
    {
        $settings = Setting::group('lab', Setting::LAB_DEFAULTS);
        foreach ([1, 2] as $slot) {
            $path = $settings["technician_{$slot}_signature_path"] ?? null;
            unset($settings["technician_{$slot}_signature_path"]);
            $settings["technician_{$slot}_signature"] = $path && Storage::disk('local')->exists($path)
                ? 'data:'.Storage::disk('local')->mimeType($path).';base64,'.base64_encode(Storage::disk('local')->get($path))
                : null;
        }

        return $settings;
    }

    /** Stores a picture of a technician's signature, printed above their name on reports. */
    public function saveSignature(Request $request): JsonResponse
    {
        $this->authorizeLab($request);
        $data = $request->validate([
            'slot' => ['required', Rule::in([1, 2])],
            'file' => ['required', 'file', 'max:300', 'mimes:png,jpg,jpeg'],
        ], ['file.max' => 'The signature picture must be 300 KB or smaller.', 'file.mimes' => 'Use a PNG or JPG picture of the signature.']);

        $this->forgetSignature((int) $data['slot'], $request->file('file')->store('lab-signatures', 'local'));

        return response()->json($this->labSettings());
    }

    public function destroySignature(Request $request, int $slot): JsonResponse
    {
        $this->authorizeLab($request);
        abort_unless(in_array($slot, [1, 2], true), 404);
        $this->forgetSignature($slot, null);

        return response()->json($this->labSettings());
    }

    /** Replaces (or clears) one signature picture and deletes the old file. */
    private function forgetSignature(int $slot, ?string $newPath): void
    {
        $settings = Setting::group('lab', Setting::LAB_DEFAULTS);
        $key = "technician_{$slot}_signature_path";
        if (! empty($settings[$key])) {
            Storage::disk('local')->delete($settings[$key]);
        }
        $settings[$key] = $newPath;
        Setting::updateOrCreate(['key' => 'lab'], ['value' => $settings]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->authorizeLab($request);

        $data = $request->validate([
            'lab_name' => ['nullable', 'string', 'max:255'],
            'lab_address' => ['nullable', 'string', 'max:500'],
            'technician_1' => ['nullable', 'string', 'max:255'],
            'technician_2' => ['nullable', 'string', 'max:255'],
            'letterhead_top_mm' => ['required', 'integer', 'between:0,150'],
            'letterhead_bottom_mm' => ['required', 'integer', 'between:0,100'],
            'each_test_on_new_page' => ['required', 'boolean'],
            'allow_print_without_payment' => ['required', 'boolean'],
            'print_sample_date' => ['required', 'boolean'],
            'print_approved_date' => ['required', 'boolean'],
            'approval_required' => ['required', 'boolean'],
            'tabular_print' => ['sometimes', 'boolean'],
        ]);

        // Keep what this form does not send, such as the signature pictures.
        Setting::updateOrCreate(['key' => 'lab'], ['value' => array_merge(Setting::group('lab', Setting::LAB_DEFAULTS), $data)]);

        return response()->json($this->labSettings());
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
            ->with($patientId ? ['items.results'] : self::ORDER_WITH)
            ->with(['bill' => fn ($bill) => $bill->select('id', 'bill_no', 'total', 'paid')->withIpdTransfers()])
            ->latest('ordered_at')->latest('id')
            ->limit($patientId ? 15 : 200)
            ->get();

        return response()->json($orders);
    }

    /**
     * Creates the order, a result sheet for every test, and the bill, in one step.
     * Tests can be chosen one by one, as packages, or both; a package is billed as one line at its own price.
     */
    public function storeOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'referred_by' => ['nullable', 'string', 'max:255'],
            'test_ids' => ['nullable', 'required_without:package_ids', 'array', 'max:60'],
            'test_ids.*' => ['integer', 'distinct', Rule::exists('lab_tests', 'id')->where('is_active', true)],
            'package_ids' => ['nullable', 'array', 'max:20'],
            'package_ids.*' => ['integer', 'distinct', Rule::exists('lab_packages', 'id')->where('is_active', true)],
        ], ['test_ids.required_without' => 'Choose at least one test or package.']);

        if (empty($data['test_ids']) && empty($data['package_ids'])) {
            throw ValidationException::withMessages(['test_ids' => 'Choose at least one test or package.']);
        }

        $order = DB::transaction(function () use ($data, $request) {
            $user = $request->user();
            $bill = Bill::create([
                'branch_id' => $user->branch_id,
                'patient_id' => $data['patient_id'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'kind' => 'lab',
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

            $added = [];
            $addTest = function (LabTest $test, ?string $package, float $price) use ($order, &$added) {
                if (isset($added[$test->id])) {
                    return; // already on this order, singly or through another package
                }
                $added[$test->id] = true;

                $item = $order->items()->create([
                    'lab_test_id' => $test->id,
                    'test_name' => $test->name,
                    'kind' => $test->kind,
                    'package_name' => $package,
                    'department' => $test->department,
                    'sample_type' => $test->sample_type,
                    'price' => $price,
                    'remarks' => $test->default_comment,
                ]);
                foreach ($test->parameters as $parameter) {
                    $item->results()->create([
                        'parameter' => $parameter->name,
                        'is_heading' => $parameter->is_heading,
                        'unit' => $parameter->unit,
                        'reference_range' => $parameter->reference_range,
                        'method' => $parameter->method,
                        'position' => $parameter->position,
                    ]);
                }
            };

            foreach (LabPackage::with('tests.parameters')->whereIn('id', $data['package_ids'] ?? [])->orderBy('name')->get() as $package) {
                foreach ($package->tests as $test) {
                    $addTest($test, $package->name, 0);
                }
                $bill->addLine($package->name.' (package)', $package->price);
            }

            foreach (LabTest::with('parameters')->whereIn('id', $data['test_ids'] ?? [])->orderBy('print_order')->orderBy('name')->get() as $test) {
                if (! isset($added[$test->id])) {
                    $addTest($test, null, $test->price);
                    $bill->addLine($test->name, $test->price);
                }
            }

            return $order;
        });

        return response()->json($this->present($order), 201);
    }

    public function showOrder(LabOrder $order): JsonResponse
    {
        return response()->json($this->present($order));
    }

    /** Notes that the sample has been taken from the patient (or undoes that before any result is entered). */
    public function collectSample(Request $request, LabOrder $order, LabOrderItem $item): JsonResponse
    {
        $this->authorizeLab($request);
        abort_unless($item->lab_order_id === $order->id, 404);

        $collected = $request->validate(['collected' => ['required', 'boolean']])['collected'];
        if (! $collected && $item->reported_at) {
            throw ValidationException::withMessages(['collected' => 'This test already has a result.']);
        }

        $item->update(['collected_at' => $collected ? ($item->collected_at ?? now()) : null, 'collected_by' => $collected ? $request->user()->id : null]);

        return response()->json($this->present($order));
    }

    /**
     * Saves the result of one test: the values, or the written report. `complete` marks it
     * reported (and so collected); reopening it also withdraws any approval.
     */
    public function saveResults(Request $request, LabOrder $order, LabOrderItem $item): JsonResponse
    {
        $this->authorizeLab($request);
        abort_unless($item->lab_order_id === $order->id, 404);

        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:5000'],
            'report_text' => ['nullable', 'string', 'max:20000'],
            'complete' => ['sometimes', 'boolean'],
            'results' => ['present', 'array'],
            'results.*.id' => ['required', 'integer'],
            'results.*.value' => ['nullable', 'string', 'max:255'],
            'results.*.flag' => ['nullable', Rule::in(['high', 'low'])],
        ]);

        if ($item->approved_at && ($data['complete'] ?? true)) {
            throw ValidationException::withMessages(['results' => 'This result is approved. Reopen it to make changes.']);
        }

        DB::transaction(function () use ($data, $item, $order, $request) {
            $rows = $item->results()->get()->keyBy('id');
            foreach ($data['results'] as $entry) {
                $rows->get($entry['id'])?->update(['value' => $entry['value'] ?? null, 'flag' => $entry['flag'] ?? null]);
            }

            $item->remarks = $data['remarks'] ?? null;
            if (array_key_exists('report_text', $data)) {
                $item->report_text = $data['report_text'];
            }
            if (array_key_exists('complete', $data)) {
                $item->reported_at = $data['complete'] ? ($item->reported_at ?? now()) : null;
                $item->reported_by = $data['complete'] ? $request->user()->id : null;
                if ($data['complete']) {
                    $item->collected_at ??= now();
                    $item->collected_by ??= $request->user()->id;
                } else {
                    $item->approved_at = null;
                    $item->approved_by = null;
                }
            }
            $item->save();
            $order->refreshStatus();
        });

        return response()->json($this->present($order));
    }

    /** A second person signs off a reported result, or withdraws that sign-off. */
    public function approve(Request $request, LabOrder $order, LabOrderItem $item): JsonResponse
    {
        $this->authorizeLab($request);
        abort_unless($item->lab_order_id === $order->id, 404);

        $approved = $request->validate(['approved' => ['required', 'boolean']])['approved'];
        if ($approved && ! $item->reported_at) {
            throw ValidationException::withMessages(['approved' => 'Enter and report the result before approving it.']);
        }

        $item->update(['approved_at' => $approved ? now() : null, 'approved_by' => $approved ? $request->user()->id : null]);

        return response()->json($this->present($order));
    }

    // ---- Result files ----

    /** Attaches a file to an order: a report from an outside lab, an ECG strip, a scanned sheet. */
    public function storeFile(Request $request, LabOrder $order): JsonResponse
    {
        $this->authorizeLab($request);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'lab_order_item_id' => ['nullable', Rule::exists('lab_order_items', 'id')->where('lab_order_id', $order->id)],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ], ['file.mimes' => 'Attach a PDF or an image (JPG, PNG or WebP).', 'file.max' => 'The file must be 10 MB or smaller.']);

        $file = $request->file('file');
        // Kept on the private disk: these are medical records and are only ever served to signed-in staff.
        $order->files()->create([
            'lab_order_item_id' => $data['lab_order_item_id'] ?? null,
            'title' => filled($data['title'] ?? null) ? $data['title'] : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'path' => $file->store("lab-files/{$order->id}", 'local'),
            'original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($this->present($order), 201);
    }

    public function downloadFile(LabOrderFile $file): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($file->path), 404, 'The file is missing.');

        return Storage::disk('local')->response($file->path, $file->original_name, ['Content-Type' => $file->mime]);
    }

    public function destroyFile(Request $request, LabOrderFile $file): JsonResponse
    {
        $this->authorizeLab($request);
        Storage::disk('local')->delete($file->path);
        $order = $file->order;
        $file->delete();

        return response()->json($this->present($order));
    }

    private function authorizeLab(Request $request): void
    {
        abort_unless($request->user()->allows('canRunLab'), 403, 'You are not allowed to do this in the lab.');
    }

    private function present(LabOrder $order): LabOrder
    {
        return $order->fresh()->load([
            'patient:id,uhid,title,name,gender,date_of_birth,mobile', 'items.results', 'items.reporter:id,name,signature_text', 'items.approver:id,name,signature_text',
            'bill:id,bill_no,total,paid', 'files.uploader:id,name',
        ]);
    }
}
