<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

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
            'abilities' => collect(User::ABILITIES)->map(fn (array $text, string $key) => [
                'key' => $key, 'label' => $text[0], 'hint' => $text[1],
                'roles' => collect(Role::cases())->filter(fn (Role $r) => $r->{$key}())->map->value->values(),
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
                ->filter(fn (bool $allowed, string $ability) => $allowed !== $role->{$ability}())
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

        $data['branch_id'] = $request->user()->branch_id;

        return response()->json(User::create($this->withPermissions($data))->refresh()->append(self::EXTRA), 201);
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
            unset($data['is_active'], $data['permissions']);
            $data['role'] = $staff->role->value;
        }
        $data = $this->withPermissions($data);

        $staff->update($data);

        if ($staff->is_active === false) {
            $staff->tokens()->delete();
        }

        return response()->json($staff->append(self::EXTRA));
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
            'permissions' => ['sometimes', 'nullable', 'array:'.implode(',', array_keys(User::ABILITIES))],
            'permissions.*' => ['boolean'],
            'availability' => ['sometimes', 'nullable', 'array:mon,tue,wed,thu,fri,sat,sun'],
            'availability.*' => ['nullable', 'array', 'max:3'],
            'availability.*.*.from' => ['required', 'date_format:H:i'],
            'availability.*.*.to' => ['required', 'date_format:H:i', 'after:availability.*.*.from'],
        ];
    }
}
