<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\DatabaseBackup;
use App\Support\GoogleIdToken;
use App\Support\SignInGuard;
use App\Support\StaffLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Signing in with a mobile number or an email, and a password or (for an own-record login) a PIN.
     * `email` is still taken as the name of the first box, for phone apps installed before mobile sign-in.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['required_without:login', 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], ['login.required_without' => 'Type your mobile number or email.', 'email.required_without' => 'Type your mobile number or email.']);
        $field = filled($credentials['login'] ?? null) ? 'login' : 'email';
        $typed = trim((string) $credentials[$field]);
        $user = StaffLogin::typed($typed, $field);
        // A doctor who is only on the lists has no login to open.
        if ($user && ! $user->signsIn()) {
            $user = null;
        }
        // Wrong tries are counted against the login itself, so trying by mobile and then by email is one count.
        $key = $user ? StaffLogin::guardKey($user) : StaffLogin::key($typed);
        SignInGuard::refuseIfClosed('staff', $key, $field, $user?->usesPin() ? StaffLogin::PIN_TRIES : SignInGuard::TRIES);

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            SignInGuard::wrong('staff', $key);
            // Kept so that someone guessing at passwords shows up in the activity log. The password tried is never stored.
            AuditLog::record('auth.failed', null, null, $user ? 'Wrong password' : 'No such login', $user, mb_substr($typed, 0, 100));
            throw ValidationException::withMessages([$field => 'That is not right. Check the mobile number or email, and the password or PIN.']);
        }

        if (! $user->is_active) {
            AuditLog::record('auth.failed', null, null, 'The login is switched off', $user);
            throw ValidationException::withMessages([$field => 'This account has been deactivated.']);
        }
        SignInGuard::clear('staff', $key);
        AuditLog::record('auth.login', null, null, null, $user);

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $this->present($user),
        ]);
    }

    /**
     * Staff sign-in with Google. Only for a login that already exists and is switched on: the Google account joined to it
     * earlier, or, the first time, a Google account whose address Google vouches for and which is the login's own email.
     * Google never makes a new staff login.
     */
    public function googleLogin(Request $request): JsonResponse
    {
        $google = GoogleIdToken::verify((string) $request->validate(['credential' => ['required', 'string', 'max:4096']])['credential']);
        $user = User::where('google_sub', $google['sub'])->first();
        if (! $user && $google['authoritative']) {
            $user = User::whereRaw('lower(email) = ?', [$google['email']])->whereNull('google_sub')->first();
        }
        if (! $user) {
            AuditLog::record('auth.failed', null, null, 'Google account not joined to a login', null, mb_substr($google['email'], 0, 100));
            throw ValidationException::withMessages(['credential' => "No staff login is joined to {$google['email']}. Sign in with your email and password, then join your Google account under My account."]);
        }
        if (! $user->is_active || ! $user->signsIn()) {
            AuditLog::record('auth.failed', null, null, 'The login is switched off', $user);
            throw ValidationException::withMessages(['credential' => 'This account has been deactivated.']);
        }
        if (! $user->google_sub) {
            $user->forceFill(['google_sub' => $google['sub'], 'google_email' => $google['email']])->save();
        }
        AuditLog::record('auth.login', null, null, 'With Google ('.$google['email'].')', $user);

        return response()->json(['token' => $user->createToken('web')->plainTextToken, 'user' => $this->present($user)]);
    }

    /** Joins the signed-in person's Google account to their login, so they can sign in with Google from then on. */
    public function connectGoogle(Request $request): JsonResponse
    {
        $google = GoogleIdToken::verify((string) $request->validate(['credential' => ['required', 'string', 'max:4096']])['credential']);
        $user = $request->user();
        if (User::where('google_sub', $google['sub'])->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages(['credential' => "{$google['email']} is already joined to another staff login."]);
        }
        $user->forceFill(['google_sub' => $google['sub'], 'google_email' => $google['email']])->save();
        AuditLog::record('auth.google_joined', null, null, $google['email'], $user);

        return response()->json($this->present($user));
    }

    public function disconnectGoogle(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->google_sub) {
            AuditLog::record('auth.google_removed', null, null, $user->google_email, $user);
            $user->forceFill(['google_sub' => null, 'google_email' => null])->save();
        }

        return response()->json($this->present($user));
    }

    /**
     * Lets a person change their own password or PIN, after proving they know the current one. This is also how
     * someone handed a starting PIN chooses their own, after which the rest of the system opens to them.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $pin = $user->usesPin();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', ...($pin ? ['digits:'.StaffLogin::PIN_DIGITS] : [Password::min(8)->letters()->numbers()])],
        ], $pin
            ? ['password.different' => 'Choose a PIN different from the current one.', 'password.confirmed' => 'The two new PINs do not match.', 'password.digits' => 'A PIN is '.StaffLogin::PIN_DIGITS.' digits.']
            : ['password.different' => 'Choose a password different from the current one.', 'password.confirmed' => 'The two new passwords do not match.']);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => $pin ? 'That is not your current PIN.' : 'That is not your current password.']);
        }
        if ($pin && StaffLogin::weakPin($data['password'], $user)) {
            throw ValidationException::withMessages(['password' => 'That PIN is too easy to guess. Do not use one digit repeated, a run like 123456, or the end of your own mobile number.']);
        }
        $user->update(['password' => $data['password'], 'must_change_secret' => false]);
        // Sign out every other device; this one stays signed in.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => $pin ? 'PIN changed.' : 'Password changed.']);
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
            // The Google account they can sign in with, if they have joined one.
            'google_email' => $user->google_email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            // A login opened with a PIN, and one that must choose its own PIN or password before anything else opens.
            'sign_in_with' => $user->sign_in_with ?? 'password',
            'must_change_secret' => (bool) $user->must_change_secret,
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
            'can_pay_consultants' => $user->allows('canPayConsultants'),
            // Something an admin should act on about the backups, shown at the top of every screen until it is done.
            'backup_alert' => $user->role === Role::Admin ? DatabaseBackup::alert() : null,
            'pad_hidden_sections' => $user->pad_hidden_sections ?? [],
            'hidden_tabs' => $user->hidden_tabs ?? [],
            'appearance' => ($user->appearance ?? []) + ['theme' => 'prasanthi', 'text' => 'normal'],
            'branch' => $user->branch?->only(['id', 'name', 'city']),
        ];
    }
}
