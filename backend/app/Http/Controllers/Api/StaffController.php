<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\DoctorFee;
use App\Support\SignInGuard;
use App\Support\StaffLogin;
use App\Workforce\Models\Employee;
use App\Workforce\Support\Audit;
use App\Workforce\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffController extends Controller
{
    /** Worked-out values the staff screen needs with each person. */
    private const EXTRA = ['abilities', 'has_signature', 'opd_fee'];

    /** A doctor's share of collections. It is pay, so only an admin sees it or sets it. */
    private const SHARES = ['share_front_desk', 'share_lab', 'share_ipd'];

    private function seesShares(Request $request): bool
    {
        return $request->user()->allows('canPayConsultants');
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
     * Gives an own-record login to every member of staff who has none and has a mobile number of their own. Each gets
     * a starting PIN, returned here once for the hospital to hand out; each person must choose their own at first
     * sign-in. Someone with no ten-digit number, or a number that already opens another login, is left out and named.
     */
    public function storeForAll(Request $request): JsonResponse
    {
        $this->mayGive($request, Role::Staff->value);
        $given = [];
        $skipped = [];
        foreach (Employee::query()->whereNull('user_id')->where('status', '!=', 'inactive')->orderBy('name')->get() as $employee) {
            $mobile = StaffLogin::digits($employee->phone);
            $holder = $mobile ? StaffLogin::holder($employee->phone) : null;
            if (! $mobile || $holder) {
                $skipped[] = ['name' => $employee->name, 'employee_no' => $employee->employee_no, 'why' => $mobile ? "The mobile number already opens {$holder->name}’s login" : 'No ten-digit mobile number on the record'];

                continue;
            }
            $pin = StaffLogin::startingPin();
            DB::transaction(function () use ($request, $employee, $pin) {
                $staff = User::create([
                    'name' => $employee->name, 'phone' => $employee->phone, 'gender' => $employee->gender, 'role' => Role::Staff->value, 'branch_id' => $request->user()->branch_id,
                    'password' => $pin, 'sign_in_with' => 'pin', 'must_change_secret' => true, 'is_active' => true,
                ])->refresh();
                AuditLog::record('staff.permissions', null, $staff, $this->permissionWords($staff, null, [], $staff->abilities, 'Login given with the others who had none'));
                AuditLog::$written = false;
                $employee->update(['user_id' => $staff->id]);
                Audit::record('staff.login_given', $employee, ['user_id' => null], ['user_id' => $staff->id, 'role' => $staff->role->value], 'Given with the others who had none', $employee->id);
            });
            $given[] = ['name' => $employee->name, 'employee_no' => $employee->employee_no, 'mobile' => $mobile, 'starting_pin' => $pin];
        }

        return response()->json(['given' => $given, 'skipped' => $skipped]);
    }

    /**
     * Puts a member of staff on the hospital's list of doctors, or takes them off it. A doctor on that list can be
     * chosen for appointments, admissions and consultant visits, and has a month under Consultants, whether or not
     * they sign in: someone with no login is given a record that cannot be signed in with. Nothing here gives anyone
     * a way in, so it is open to those who keep the staff records as well as those who manage logins.
     */
    public function doctorFor(Request $request, int $employee): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->allows('canManageStaff') || Permissions::reach($user, 'wf:staff:edit') === Permissions::ALL, 403, 'You are not allowed to change the list of doctors.');
        $employee = Employee::query()->with('department:id,name')->findOrFail($employee);
        $data = $request->validate(['doctor' => ['required', 'boolean'], 'qualification' => ['sometimes', 'nullable', 'string', 'max:255'], 'book_online' => ['sometimes', 'boolean'], 'opd_fee' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999']]);
        $login = $employee->user_id ? User::query()->find($employee->user_id) : null;
        $more = array_filter(['signature_text' => $data['qualification'] ?? null, 'book_online' => $data['book_online'] ?? null], fn ($v) => $v !== null)
            + (array_key_exists('qualification', $data) && $data['qualification'] === null ? ['signature_text' => null] : []);

        if ($login && $login->role !== Role::Doctor) {
            throw ValidationException::withMessages(['doctor' => "{$employee->name} signs in as {$login->role->label()}. To make them a doctor, change their role under Login and access."]);
        }
        if (! $data['doctor']) {
            if ($login?->signsIn()) {
                throw ValidationException::withMessages(['doctor' => "{$employee->name} signs in as a doctor. To take them off the doctors' list, change their role or close their login under Login and access."]);
            }
            // Never removed: their appointments, admissions and fees still name them. They are only taken off the lists.
            $login?->update(['is_active' => false]);
            if ($login) {
                Audit::record('staff.doctor_off', $employee, ['doctor' => true], ['doctor' => false], $request->input('reason'), $employee->id);
            }

            return response()->json(['doctor' => false]);
        }
        if ($employee->status === 'inactive') {
            throw ValidationException::withMessages(['doctor' => "{$employee->name} has left the staff. Mark them as on the staff again first."]);
        }
        // Opening a login again is a matter of access, and stays with those who manage logins.
        if ($login && $login->signsIn() && ! $login->is_active) {
            throw ValidationException::withMessages(['doctor' => "{$employee->name}'s login is switched off. Open it again under Login and access."]);
        }

        $login = DB::transaction(function () use ($employee, $login, $more, $user) {
            if ($login) {
                $login->update($more + ['is_active' => true]);

                return $login;
            }
            $login = User::create($more + [
                'name' => $employee->name, 'phone' => $employee->phone, 'gender' => $employee->gender, 'department' => $employee->department?->name, 'role' => Role::Doctor->value,
                'branch_id' => $user->branch_id, 'is_active' => true, 'sign_in_with' => 'none', 'password' => Str::random(48),
                // A consultant is not offered to patients in the phone app until someone says so.
                'book_online' => $more['book_online'] ?? false,
            ])->refresh();
            $employee->update(['user_id' => $login->id]);
            Audit::record('staff.doctor_on', $employee, ['doctor' => false], ['doctor' => true, 'user_id' => $login->id], 'Put on the list of doctors; does not sign in', $employee->id);

            return $login;
        });
        if (array_key_exists('opd_fee', $data)) {
            DoctorFee::change($login, $data['opd_fee'], $user);
        }
        AuditLog::record('staff.doctor', null, $login, "{$login->name} · on the list of doctors".($login->signsIn() ? '' : ' · does not sign in'));

        return response()->json(['doctor' => true, 'login_id' => $login->id]);
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
        $fee = $data['opd_fee'] ?? null;
        unset($data['opd_fee']);

        $this->onlyAdminsTouchAdmins($request, null, $data['role']);
        $data = $this->sharesOnlyFromAdmins($request, $data);
        $data['branch_id'] = $request->user()->branch_id;
        $reason = $data['reason'] ?? null;
        unset($data['reason']);
        $data = $this->withPermissions($data);
        $this->onlyAdminsGiveSensitive($request, [], $this->abilitiesOf($data['role'], $data['permissions'] ?? null), $data['role']);

        $staff = User::create($data)->refresh();
        if ($staff->role === Role::Doctor && $fee !== null) {
            DoctorFee::change($staff, $fee, $request->user());
        }
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
            // Unticked for a doctor: they stay on the hospital's lists and can no longer sign in.
            'signs_in' => ['sometimes', 'boolean'],
        ], self::PIN_WORDS + ['password.required' => $pin ? 'Give a starting PIN: an own-record login is opened with a PIN.' : 'Give a password: this role cannot be opened with a PIN.']);
        // A doctor may be on the lists without signing in. Anyone else needs a way in, and so does a doctor being given one.
        $feeSent = array_key_exists('opd_fee', $data);
        $fee = $data['opd_fee'] ?? null;
        unset($data['opd_fee']);
        $stops = ($data['signs_in'] ?? null) === false && $data['role'] === Role::Doctor->value && ! $staff->is($request->user());
        $none = $stops || (! $staff->signsIn() && empty($data['password']));
        unset($data['signs_in']);
        if ($none && $data['role'] !== Role::Doctor->value) {
            throw ValidationException::withMessages(['password' => 'Give a password: only a doctor is kept on the hospital’s lists without signing in.']);
        }
        if (! $none) {
            $this->signInIsPossible($data, $staff);
        }

        $this->onlyAdminsTouchAdmins($request, $staff, $data['role']);
        $data = $this->sharesOnlyFromAdmins($request, $data);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $newPassword = isset($data['password']) && ! $staff->is($request->user());
        if ($stops) {
            unset($data['password']);
            $data['sign_in_with'] = 'none';
            $data['must_change_secret'] = false;
        } elseif (isset($data['password'])) {
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
        if ($feeSent && $staff->role === Role::Doctor) {
            DoctorFee::change($staff, $fee, $request->user());
        }
        // The person's staff record carries the same name and mobile.
        StaffLogin::fromUser($staff, array_keys($staff->getChanges()));
        if ($changed) {
            AuditLog::record('staff.permissions', null, $staff, $this->permissionWords($staff, $wasRole, $before, $after, $reason));
            AuditLog::$written = false; // the usual "Changed a staff login" line is still written as well
        }

        // Switched off, or given a new password by someone else: every device signed in as this person is signed out.
        if ($staff->is_active === false || $newPassword || $stops) {
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
        if (blank($data['phone'] ?? null) && blank($data['email'] ?? null)) {
            throw ValidationException::withMessages(['phone' => 'Give the mobile number they will sign in with, or an email.']);
        }
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
            // Needed by anyone who signs in with it; a doctor who is only on the lists may have none on record.
            'phone' => ['nullable', 'string', 'regex:/^[0-9+\- ]{7,20}$/'],
            'department' => ['nullable', 'string', 'max:255'],
            'signature_text' => ['nullable', 'string', 'max:255'],
            // Whether patients may book this doctor from the phone app.
            'book_online' => ['sometimes', 'boolean'],
            // A doctor's own OPD consultation fee. Kept as a service of theirs, not on the login: see DoctorFee.
            'opd_fee' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999'],
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
