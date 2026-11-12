<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'This account has been deactivated.']);
        }

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $this->present($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->present($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        $user->loadMissing('branch');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'can_manage_staff' => $user->allows('canManageStaff'),
            'can_consult' => $user->allows('canConsult'),
            'can_run_lab' => $user->allows('canRunLab'),
            'can_run_pharmacy' => $user->allows('canRunPharmacy'),
            'can_manage_setup' => $user->allows('canManageSetup'),
            'can_front_desk' => $user->allows('canWorkFrontDesk'),
            'can_ipd' => $user->allows('canWorkIpd'),
            'can_view_reports' => $user->allows('canViewReports'),
            'can_delete_bills' => $user->allows('canDeleteBills'),
            'can_change_price' => $user->allows('canChangePrice'),
            'pad_hidden_sections' => $user->pad_hidden_sections ?? [],
            'branch' => $user->branch?->only(['id', 'name', 'city']),
        ];
    }
}
