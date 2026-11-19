<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SignInGuard;
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
            'data' => $staff->each->append(self::EXTRA),
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
        $data = $request->validate($this->rules() + [
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ]);

        $this->onlyAdminsTouchAdmins($request, null, $data['role']);
        $data['branch_id'] = $request->user()->branch_id;
        $reason = $data['reason'] ?? null;
        unset($data['reason']);
        $data = $this->withPermissions($data);
        $this->onlyAdminsGiveSensitive($request, [], $this->abilitiesOf($data['role'], $data['permissions'] ?? null), $data['role']);

        $staff = User::create($data)->refresh();
        AuditLog::record('staff.permissions', null, $staff, $this->permissionWords($staff, null, [], $staff->abilities, $reason ?: 'New member of staff'));
        AuditLog::$written = false; // the usual "Added a staff login" line is still written as well

        return response()->json($staff->append(self::EXTRA), 201);
    }

    public function update(Request $request, User $staff): JsonResponse
    {
        $data = $request->validate($this->rules($staff) + [
            'password' => ['nullable', Password::min(8)->letters()->numbers()],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->onlyAdminsTouchAdmins($request, $staff, $data['role']);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $newPassword = isset($data['password']) && ! $staff->is($request->user());

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
            SignInGuard::clear('staff', $staff->email);
        }

        return response()->json($staff->append(self::EXTRA));
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
            'permissions' => ['sometimes', 'nullable', 'array:'.implode(',', array_keys(User::catalogue()))],
            'permissions.*' => ['boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:300'],
            'availability' => ['sometimes', 'nullable', 'array:mon,tue,wed,thu,fri,sat,sun'],
            'availability.*' => ['nullable', 'array', 'max:3'],
            'availability.*.*.from' => ['required', 'date_format:H:i'],
            'availability.*.*.to' => ['required', 'date_format:H:i', 'after:availability.*.*.from'],
        ];
    }
}
