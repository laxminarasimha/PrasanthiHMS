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
            // Kept so that someone guessing at passwords shows up in the activity log. The password tried is never stored.
            \App\Models\AuditLog::record('auth.failed', null, null, $user ? 'Wrong password' : 'No such login', $user, mb_substr($credentials['email'], 0, 100));
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }

        if (! $user->is_active) {
            \App\Models\AuditLog::record('auth.failed', null, null, 'The login is switched off', $user);
            throw ValidationException::withMessages(['email' => 'This account has been deactivated.']);
        }
        \App\Models\AuditLog::record('auth.login', null, null, null, $user);

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $this->present($user),
        ]);
    }

    /** Lets a person change their own password, after proving they know the current one. */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()],
        ], ['password.different' => 'Choose a password different from the current one.', 'password.confirmed' => 'The two new passwords do not match.']);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }
        $user->update(['password' => $data['password']]);
        // Sign out every other device; this one stays signed in.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password changed.']);
    }

    public const THEMES = ['prasanthi', 'ocean', 'forest', 'rose', 'slate', 'night'];

    /** Saves a person's own choice of theme and text size. */
    public function saveAppearance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['required', \Illuminate\Validation\Rule::in(self::THEMES)],
            'text' => ['required', \Illuminate\Validation\Rule::in(['small', 'normal', 'large'])],
        ]);
        $request->user()->update(['appearance' => $data]);

        return response()->json($this->present($request->user()));
    }

    public const MENU_TABS = ['front-desk', 'doctor', 'lab', 'pharmacy', 'ipd', 'patients', 'reports', 'setup'];

    /** Saves which menu tabs a person does not want to see. Their permissions stay as they are. */
    public function saveTabs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hidden' => ['present', 'array'],
            'hidden.*' => ['string', \Illuminate\Validation\Rule::in(self::MENU_TABS)],
        ]);
        $request->user()->update(['hidden_tabs' => array_values(array_unique($data['hidden'])) ?: null]);

        return response()->json($this->present($request->user()));
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
            'can_approve_ipd' => $user->allows('canApproveIpd'),
            'can_view_reports' => $user->allows('canViewReports'),
            'can_delete_bills' => $user->allows('canDeleteBills'),
            'can_change_price' => $user->allows('canChangePrice'),
            'pad_hidden_sections' => $user->pad_hidden_sections ?? [],
            'hidden_tabs' => $user->hidden_tabs ?? [],
            'appearance' => ($user->appearance ?? []) + ['theme' => 'prasanthi', 'text' => 'normal'],
            'branch' => $user->branch?->only(['id', 'name', 'city']),
        ];
    }
}
