<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\DatabaseBackup;
use App\Support\SignInGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        SignInGuard::refuseIfClosed('staff', $credentials['email'], 'email');
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            SignInGuard::wrong('staff', $credentials['email']);
            // Kept so that someone guessing at passwords shows up in the activity log. The password tried is never stored.
            AuditLog::record('auth.failed', null, null, $user ? 'Wrong password' : 'No such login', $user, mb_substr($credentials['email'], 0, 100));
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }

        if (! $user->is_active) {
            AuditLog::record('auth.failed', null, null, 'The login is switched off', $user);
            throw ValidationException::withMessages(['email' => 'This account has been deactivated.']);
        }
        SignInGuard::clear('staff', $credentials['email']);
        AuditLog::record('auth.login', null, null, null, $user);

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
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
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
            'theme' => ['required', Rule::in(self::THEMES)],
            'text' => ['required', Rule::in(['small', 'normal', 'large'])],
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
            'hidden.*' => ['string', Rule::in(self::MENU_TABS)],
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
            // Everything this person may do, by its own name: the Staff screen uses it to show what they can hand on.
            'abilities' => $user->abilities,
            'can_consult' => $user->allows('canConsult'),
            'can_view_clinical' => $user->seesClinical(),
            'can_run_lab' => $user->allows('canRunLab'),
            'can_approve_lab' => $user->allows('canApproveLab'),
            'can_run_pharmacy' => $user->allows('canRunPharmacy'),
            'can_view_pharmacy' => $user->allows('canRunPharmacy') || $user->allows('canViewPharmacy'),
            'can_manage_setup' => $user->allows('canManageSetup'),
            'can_set_prices' => $user->allows('canSetPrices'),
            'can_export' => $user->allows('canExportData'),
            'can_front_desk' => $user->allows('canWorkFrontDesk'),
            'can_file_past_records' => $user->allows('canFilePastRecords'),
            'can_ipd' => $user->allows('canWorkIpd'),
            'can_approve_ipd' => $user->allows('canApproveIpd'),
            'can_view_reports' => $user->allows('canViewReports'),
            'can_delete_bills' => $user->allows('canDeleteBills'),
            'can_audit_pharmacy' => $user->allows('canAuditPharmacy'),
            'can_change_price' => $user->allows('canChangePrice'),
            'can_record_expenses' => $user->allows('canRecordExpenses'),
            // Something an admin should act on about the backups, shown at the top of every screen until it is done.
            'backup_alert' => $user->role === Role::Admin ? DatabaseBackup::alert() : null,
            'pad_hidden_sections' => $user->pad_hidden_sections ?? [],
            'hidden_tabs' => $user->hidden_tabs ?? [],
            'appearance' => ($user->appearance ?? []) + ['theme' => 'prasanthi', 'text' => 'normal'],
            'branch' => $user->branch?->only(['id', 'name', 'city']),
        ];
    }
}
