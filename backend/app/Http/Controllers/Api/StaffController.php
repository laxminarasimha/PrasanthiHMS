<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $staff = User::query()
            ->when($request->string('q')->trim()->value(), function ($query, $q) {
                $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%"));
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $staff,
            'roles' => array_map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()], Role::cases()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ]);

        $data['branch_id'] = $request->user()->branch_id;

        return response()->json(User::create($data), 201);
    }

    public function update(Request $request, User $staff): JsonResponse
    {
        $data = $request->validate($this->rules($staff) + [
            'password' => ['nullable', Password::min(8)->letters()->numbers()],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        // Stop people locking themselves out of staff management.
        if ($staff->is($request->user())) {
            unset($data['is_active'], $data['role']);
        }

        $staff->update($data);

        if ($staff->is_active === false) {
            $staff->tokens()->delete();
        }

        return response()->json($staff);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?User $staff = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($staff)],
            'role' => ['required', Rule::enum(Role::class)],
            'gender' => ['required', Rule::in(['male', 'female', 'other'])],
            'phone' => ['required', 'string', 'regex:/^[0-9+\- ]{7,20}$/'],
            'department' => ['nullable', 'string', 'max:255'],
            'signature_text' => ['nullable', 'string', 'max:255'],
        ];
    }
}
