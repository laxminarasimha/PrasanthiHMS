<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SignInGuard;
use App\Support\StaffLogin;
use App\Workforce\Models\Employee;
use App\Workforce\Support\Audit;
use App\Workforce\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffController extends Controller
{
    /** Worked-out values the staff screen needs with each person. */
    private const EXTRA = ['abilities', 'has_signature'];

    /** A doctor's share of collections. It is pay, so only an admin sees it or sets it. */
    private const SHARES = ['share_front_desk', 'share_lab', 'share_ipd'];

    private function seesShares(Request $request): bool
    {
        return $request->user()->role === Role::Admin;
    }

    /** What the staff screen is sent for one person: with the shares for an admin, without for anyone else. */
    private function shown(Request $request, User $staff): User
    {
        return $this->seesShares($request) ? $staff->append(self::EXTRA)->makeVisible(self::SHARES) : $staff->append(self::EXTRA);
    }

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
            'data' => $staff->each(fn (User $person) => $this->shown($request, $person)),
            'roles' => array_map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()], Role::cases()),
            // Every permission, with what each role gets unless it is changed for a person.
            'abilities' => collect(User::catalogue())->map(fn (array $text, string $key) => [
                'key' => $key, 'label' => $text[0], 'hint' => $text[1], 'group' => $text[2], 'sensitive' => $text[3],
                'roles' => collect(Role::cases())->filter(fn (Role $r) => User::roleAllows($r, $key))->map->value->values(),
            ])->values(),
        ]);
    }

    /** Stores a picture of the person's signature, printed on prescriptions and lab reports. */
    public function saveSignature(Request $request, User $staff): JsonResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'max:300', 'mimes:png,jpg,jpeg']],
            ['file.max' => 'The signature picture must be 300 KB or smaller.', 'file.mimes' => 'Use a PNG or JPG picture of the signature.'],
        );
        $this->forgetSignature($staff);
        $staff->update(['signature_path' => $request->file('file')->store('staff-signatures', 'local')]);

        return response()->json($staff->append(self::EXTRA));
    }

    public function destroySignature(User $staff): JsonResponse
    {
        $this->forgetSignature($staff);
        $staff->update(['signature_path' => null]);

        return response()->json($staff->append(self::EXTRA));
    }

    private function forgetSignature(User $staff): void
    {
        if ($staff->signature_path) {
            Storage::disk('local')->delete($staff->signature_path);
        }
    }

    /** The signature picture itself, for anyone signed in to show on a print. */
    public function signature(User $user): StreamedResponse
    {
        abort_unless($user->signature_path && Storage::disk('local')->exists($user->signature_path), 404, 'No signature picture.');

        return Storage::disk('local')->response($user->signature_path);
    }

    /** The doctor's seal picture, for anyone signed in to show on a prescription. */
    public function seal(User $user): StreamedResponse
    {
        abort_unless($user->seal_path && Storage::disk('local')->exists($user->seal_path), 404, 'No seal picture.');

        return Storage::disk('local')->response($user->seal_path);
    }

    /**
     * Keeps only the permissions that differ from what the role gives, so a later change of role still makes sense.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withPermissions(array $data): array
    {
        if (array_key_exists('permissions', $data)) {
            $role = Role::from($data['role']);
            $data['permissions'] = collect($data['permissions'] ?? [])
                ->map(fn ($allowed) => (bool) $allowed)
                ->filter(fn (bool $allowed, string $ability) => $allowed !== User::roleAllows($role, $ability))
                ->all() ?: null;
        }
        if (array_key_exists('availability', $data)) {
            $data['availability'] = collect($data['availability'] ?? [])->map(fn ($ranges) => array_values($ranges ?? []))->filter()->all() ?: null;
        }

        return $data;
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->shown($request, $this->make($request)), 201);
    }

    /** What the screens need to offer when a login is made or changed: the roles, and every permission with the roles that carry it. */
    public function catalogue(): JsonResponse
    {
        return response()->json([
            'roles' => array_map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()], Role::cases()),
            'abilities' => collect(User::catalogue())->map(fn (array $text, string $key) => [
                'key' => $key, 'label' => $text[0], 'hint' => $text[1], 'group' => $text[2], 'sensitive' => $text[3],
                'roles' => collect(Role::cases())->filter(fn (Role $r) => User::roleAllows($r, $key))->map->value->values(),
            ])->values(),
        ]);
    }

    /** One login, as the staff page's "Login and access" section shows it. */
    public function show(Request $request, User $staff): JsonResponse
    {
        return response()->json($this->shown($request, $staff));
    }

    /**
     * Whether this person may give or reset a login for a member of staff. Those who manage logins may, for any role
     * within their own limits. Those who keep the staff records (HR) may for an own-record login only, which opens
     * nothing but that person's own attendance, leave and payslips.
     */
    private function mayGive(Request $request, string $role): void
    {
        $user = $request->user();
        $keepsStaff = $role === Role::Staff->value && Permissions::reach($user, 'wf:staff:edit') === Permissions::ALL;
        abort_unless($user->allows('canManageStaff') || $keepsStaff, 403, $role === Role::Staff->value ? 'You are not allowed to give logins.' : 'Only those who manage logins can give this role.');
    }

    /**
     * Gives a member of staff their login. The name, mobile and gender are the staff record's own, so they are typed
     * once. An own-record login gets a starting PIN made here and shown once: the person must choose their own the
     * first time they sign in.
     */
    public function storeFor(Request $request, int $employee): JsonResponse
    {
        $employee = Employee::query()->findOrFail($employee);
        $role = (string) $request->input('role', Role::Staff->value);
        $this->mayGive($request, $role);
        if ($employee->user_id) {
            throw ValidationException::withMessages(['role' => "{$employee->name} already has a login."]);
        }
        if ($employee->status === 'inactive') {
            throw ValidationException::withMessages(['role' => "{$employee->name} has left the staff. Mark them as on the staff again first."]);
        }
        $pin = $role === Role::Staff->value ? StaffLogin::startingPin() : null;
        $request->merge(['role' => $role, 'name' => $employee->name, 'phone' => $employee->phone, 'gender' => $employee->gender, 'email' => $request->input('email') ?: $employee->email] + ($pin ? ['password' => $pin] : []));
        if (blank($employee->phone)) {
            throw ValidationException::withMessages(['phone' => "Add {$employee->name}'s mobile number on the Details tab first: it is what they sign in with."]);
        }

        $staff = DB::transaction(function () use ($request, $employee) {
            $staff = $this->make($request);
            $employee->update(['user_id' => $staff->id]);
            Audit::record('staff.login_given', $employee, ['user_id' => null], ['user_id' => $staff->id, 'role' => $staff->role->value], $request->input('reason'), $employee->id);

            return $staff;
        });

        return response()->json(['login' => $this->shown($request, $staff)] + ($pin ? ['starting_pin' => $pin] : []), 201);
    }

    /**
     * A new starting PIN for an own-record login whose PIN is forgotten. Every device signed in as the person is
     * signed out, a login closed by wrong tries is opened, and the person must choose their own PIN again.
     */
    public function startingPin(Request $request, User $staff): JsonResponse
    {
        $this->mayGive($request, Role::Staff->value);
        abort_unless($staff->usesPin() && $staff->role === Role::Staff, 422, 'Only an own-record login is opened with a PIN.');
        abort_if($staff->is($request->user()), 422, 'Change your own PIN under My account.');
        $pin = StaffLogin::startingPin($staff);
        $staff->update(['password' => $pin, 'must_change_secret' => true]);
        $staff->tokens()->delete();
        SignInGuard::clear('staff', StaffLogin::guardKey($staff));
        AuditLog::record('staff.pin_reset', null, $staff, "{$staff->name} · A new starting PIN was given");

        return response()->json(['starting_pin' => $pin]);
    }

    private function make(Request $request): User
    {
        $pin = $request->input('role') === Role::Staff->value;
        $data = $request->validate($this->rules() + ['password' => ['required', ...$this->secretRule($pin)]], self::PIN_WORDS);
        $this->signInIsPossible($data, null);
        $data += $this->secretOf($pin, $data['password'], true);

        $this->onlyAdminsTouchAdmins($request, null, $data['role']);
        $data = $this->sharesOnlyFromAdmins($request, $data);
        $data['branch_id'] = $request->user()->branch_id;
        $reason = $data['reason'] ?? null;
        unset($data['reason']);
        $data = $this->withPermissions($data);
        $this->onlyAdminsGiveSensitive($request, [], $this->abilitiesOf($data['role'], $data['permissions'] ?? null), $data['role']);

        $staff = User::create($data)->refresh();
        AuditLog::record('staff.permissions', null, $staff, $this->permissionWords($staff, null, [], $staff->abilities, $reason ?: 'New member of staff'));
        AuditLog::$written = false; // the usual "Added a staff login" line is still written as well

        return $staff;
    }

    public function update(Request $request, User $staff): JsonResponse
    {
        // An own-record login is opened with a PIN, every other with a password. Moving a person across that line
        // needs the new one given in the same step.
        $pin = ($staff->is($request->user()) ? $staff->role->value : $request->input('role')) === Role::Staff->value;
        $data = $request->validate($this->rules($staff) + [
            'password' => [$pin !== $staff->usesPin() ? 'required' : 'nullable', ...$this->secretRule($pin)],
            'is_active' => ['sometimes', 'boolean'],
        ], self::PIN_WORDS + ['password.required' => $pin ? 'Give a starting PIN: an own-record login is opened with a PIN.' : 'Give a password: this role cannot be opened with a PIN.']);
        $this->signInIsPossible($data, $staff);

        $this->onlyAdminsTouchAdmins($request, $staff, $data['role']);
        $data = $this->sharesOnlyFromAdmins($request, $data);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $newPassword = isset($data['password']) && ! $staff->is($request->user());
        if (isset($data['password'])) {
            $data += $this->secretOf($pin, $data['password'], $newPassword);
        }

        // Stop people locking themselves out of staff management.
        if ($staff->is($request->user())) {
            unset($data['is_active'], $data['permissions']);
            $data['role'] = $staff->role->value;
        }
        $reason = $data['reason'] ?? null;
        unset($data['reason']);
        $data = $this->withPermissions($data);

        // What the person may do before and after: a change of role and a change of ticks are judged together.
        $before = $staff->abilities;
        $wasRole = $staff->role;
        $after = $this->abilitiesOf($data['role'], array_key_exists('permissions', $data) ? $data['permissions'] : $staff->permissions);
        $changed = $before !== $after || $wasRole->value !== $data['role'];
        if ($changed) {
            $this->onlyAdminsGiveSensitive($request, $before, $after, $data['role']);
            if (blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'Say why this person’s role or permissions are being changed. It is kept with the change.']);
            }
        }

        $staff->update($data);
        // The person's staff record carries the same name and mobile.
        StaffLogin::fromUser($staff, array_keys($staff->getChanges()));
        if ($changed) {
            AuditLog::record('staff.permissions', null, $staff, $this->permissionWords($staff, $wasRole, $before, $after, $reason));
            AuditLog::$written = false; // the usual "Changed a staff login" line is still written as well
        }

        // Switched off, or given a new password by someone else: every device signed in as this person is signed out.
        if ($staff->is_active === false || $newPassword) {
            $staff->tokens()->delete();
        }
        // A fresh password from the hospital opens a login that was closed after too many wrong ones.
        if ($newPassword) {
            SignInGuard::clear('staff', StaffLogin::guardKey($staff));
        }

        return response()->json($this->shown($request, $staff));
    }

    private const PIN_WORDS = ['password.digits' => 'A PIN is '.StaffLogin::PIN_DIGITS.' digits.'];

    /** What a PIN or a password must look like. */
    private function secretRule(bool $pin): array
    {
        return $pin ? ['digits:'.StaffLogin::PIN_DIGITS] : [Password::min(8)->letters()->numbers()];
    }

    /**
     * How the login is opened from now on. A PIN handed out by someone else is only a starting PIN: the person must
     * choose their own before anything else opens.
     *
     * @return array<string, mixed>
     */
    private function secretOf(bool $pin, string $secret, bool $givenByAnother): array
    {
        if ($pin && StaffLogin::weakPin($secret)) {
            throw ValidationException::withMessages(['password' => 'That PIN is too easy to guess. Do not use one digit repeated or a run like 123456.']);
        }

        return ['sign_in_with' => $pin ? 'pin' : 'password', 'must_change_secret' => $pin && $givenByAnother];
    }

    /**
     * Every login needs something to sign in with: an email, or a ten-digit mobile number that is this person's alone.
     * A login that has an email may carry a number another login already signs in with (a shared desk phone, say);
     * that number then opens only the other login, and this person signs in with their email.
     *
     * @param  array<string, mixed>  $data
     */
    private function signInIsPossible(array $data, ?User $staff): void
    {
        if (filled($data['email'] ?? null)) {
            return;
        }
        if (StaffLogin::digits($data['phone']) === null) {
            throw ValidationException::withMessages(['phone' => 'Without an email, the person signs in with their mobile number: give all ten digits.']);
        }
        StaffLogin::refuseTaken($data['phone'], $staff);
    }

    /**
     * What a person with this role and these own settings may do, without saving anything.
     *
     * @param  array<string, bool>|null  $permissions
     * @return array<string, bool>
     */
    private function abilitiesOf(string $role, ?array $permissions): array
    {
        return (new User)->forceFill(['role' => $role, 'permissions' => $permissions])->abilities;
    }

    /**
     * Nobody hands out more than they hold. Someone who manages staff without being an admin can give a person only
     * what they may do themself, so a manager cannot make a doctor's login to get round their own limits. And a
     * permission that carries clinical authority, money or control (User::SENSITIVE), ticked for one person on top
     * of what their role gives, is the admin's alone to give. Taking a permission away is open to all who manage staff.
     *
     * @param  array<string, bool>  $before
     * @param  array<string, bool>  $after
     */
    private function onlyAdminsGiveSensitive(Request $request, array $before, array $after, string $role): void
    {
        $giver = $request->user();
        if ($giver->role === Role::Admin) {
            return;
        }
        $given = collect($after)->filter(fn (bool $allowed, string $a) => $allowed && ! ($before[$a] ?? false))->keys();
        $label = fn (string $a) => User::catalogue()[$a][0];

        $notHeld = $given->reject(fn (string $a) => $giver->allows($a))->map($label);
        if ($notHeld->isNotEmpty()) {
            throw ValidationException::withMessages(['permissions' => 'You cannot give what you are not allowed to do yourself: '.$notHeld->implode(', ').'. Untick these, or ask an admin.']);
        }
        $preset = Role::from($role);
        $extra = $given->filter(fn (string $a) => User::catalogue()[$a][3] && ! User::roleAllows($preset, $a))->map($label);
        if ($extra->isNotEmpty()) {
            throw ValidationException::withMessages(['permissions' => 'Only an admin can give these on top of the role: '.$extra->implode(', ').'.']);
        }
    }

    /**
     * One line for the record: whose permissions changed, how, and why.
     *
     * @param  array<string, bool>  $before
     * @param  array<string, bool>  $after
     */
    private function permissionWords(User $staff, ?Role $wasRole, array $before, array $after, ?string $reason): string
    {
        $names = fn (bool $now) => collect(User::catalogue())->filter(fn ($text, $a) => ($after[$a] ?? false) === $now && ($before[$a] ?? false) !== $now)->map(fn ($text) => $text[0])->values();
        $parts = array_filter([
            $staff->name,
            $wasRole === null ? 'Role: '.$staff->role->label() : ($wasRole !== $staff->role ? "Role: {$wasRole->label()} → {$staff->role->label()}" : null),
            $names(true)->isNotEmpty() ? 'Given: '.$names(true)->implode(', ') : null,
            $names(false)->isNotEmpty() ? 'Taken away: '.$names(false)->implode(', ') : null,
            filled($reason) ? 'Reason: '.trim((string) $reason) : null,
        ]);

        return implode(' · ', $parts);
    }

    /** Every change to this person's role and permissions: who made it, when, what changed and why. Newest first. */
    public function permissionHistory(User $staff): JsonResponse
    {
        return response()->json(
            AuditLog::where('action', 'staff.permissions')->where('subject_type', 'User')->where('subject_id', $staff->id)->latest('id')->limit(50)
                ->get(['id', 'user_name', 'summary', 'created_at'])
                ->map(fn (AuditLog $row) => ['id' => $row->id, 'by' => $row->user_name, 'at' => $row->created_at?->format('Y-m-d H:i'), 'what' => $row->summary])
        );
    }

    /**
     * An admin login can do everything, including reopening closed accounts and downloading backups, so only
     * an admin may make one, change one, or turn someone into one. Others who manage staff work on the rest.
     */
    private function onlyAdminsTouchAdmins(Request $request, ?User $staff, string $newRole): void
    {
        if ($request->user()->role === Role::Admin) {
            return;
        }
        if ($staff?->role === Role::Admin) {
            throw ValidationException::withMessages(['role' => 'Only an admin can change an admin login.']);
        }
        if ($newRole === Role::Admin->value) {
            throw ValidationException::withMessages(['role' => 'Only an admin can give someone the Admin role.']);
        }
    }

    /**
     * Drops a doctor's share from what is saved unless an admin sent it, and keeps it to doctors.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sharesOnlyFromAdmins(Request $request, array $data): array
    {
        foreach (self::SHARES as $key) {
            if (! $this->seesShares($request)) {
                unset($data[$key]);
            } elseif (array_key_exists($key, $data) && ($data['role'] !== Role::Doctor->value || blank($data[$key]))) {
                $data[$key] = null;
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?User $staff = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Optional: a person without one signs in with their mobile number.
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($staff)],
            'role' => ['required', Rule::enum(Role::class)],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'phone' => ['required', 'string', 'regex:/^[0-9+\- ]{7,20}$/'],
            'department' => ['nullable', 'string', 'max:255'],
            'signature_text' => ['nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'nullable', 'array:'.implode(',', array_keys(User::catalogue()))],
            'permissions.*' => ['boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:300'],
            'slot_minutes' => ['sometimes', 'nullable', 'integer', Rule::in([5, 10, 15, 20, 30, 60])],
            // A doctor's share of what is collected from their own patients, as a percentage for each counter.
            'share_front_desk' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'share_lab' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'share_ipd' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'availability' => ['sometimes', 'nullable', 'array:mon,tue,wed,thu,fri,sat,sun'],
            'availability.*' => ['nullable', 'array', 'max:3'],
            'availability.*.*.from' => ['required', 'date_format:H:i'],
            'availability.*.*.to' => ['required', 'date_format:H:i', 'after:availability.*.*.from'],
        ];
    }
}
