<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $services = Service::with('doctor:id,name')
            ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
            ->orderBy('type')->orderBy('priority')->orderBy('name')
            ->get();

        return response()->json($services);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Service::create($this->validated($request))->load('doctor:id,name'), 201);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $service->update($this->validated($request));

        return response()->json($service->load('doctor:id,name'));
    }

    /** Active doctors, for the appointment and service forms. */
    public function doctors(): JsonResponse
    {
        return response()->json(
            User::where('role', Role::Doctor)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'department', 'availability'])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['appointment', 'other'])],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'gst_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'priority' => ['nullable', 'integer', 'between:1,3'],
            'doctor_id' => ['nullable', Rule::exists('users', 'id')->where('role', Role::Doctor->value)],
            'is_active' => ['sometimes', 'boolean'],
        ]) + ['gst_percent' => 0, 'priority' => 2];
    }
}
