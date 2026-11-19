<?php

namespace App\Workforce\Http;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Workforce\Models\AuditEvent;
use App\Workforce\Models\Employee;
use App\Workforce\Support\Access;
use App\Workforce\Support\Audit;
use App\Workforce\Support\Permissions;
use App\Workforce\Support\Reauth;
use App\Workforce\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** What the module stands on: who may do what, the password step for sensitive actions, and the record of changes. */
class FoundationController extends Controller
{
    /** What the signed-in person may do in the module, and how far each permission reaches. */
    public function access(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'permissions' => collect(Permissions::CATALOGUE)->map(fn ($text, string $key) => Permissions::reach($user, $key))->filter()->all(),
            'departments' => Access::departments($user),
            'employee_id' => Employee::query()->where('user_id', $user->id)->value('id'),
            'password_fresh' => Reauth::fresh($user),
        ]);
    }

    /** Every permission against every role: what each role starts with and how far it reaches. */
    public function matrix(Request $request): JsonResponse
    {
        abort_unless($request->user()->allows('wf:settings:admin') || $request->user()->allows('canManageStaff'), 403, 'You are not allowed to see this.');

        return response()->json([
            'roles' => array_map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()], Role::cases()),
            'permissions' => collect(Permissions::CATALOGUE)->map(fn (array $text, string $key) => [
                'key' => $key, 'group' => $text[0], 'label' => $text[1], 'hint' => $text[2], 'sensitive' => $text[3],
                'reach' => collect(Role::cases())->mapWithKeys(fn (Role $r) => [$r->value => Permissions::preset($r)[$key] ?? null])->all(),
            ])->values(),
        ]);
    }

    /** The password typed again, before a sensitive step. Good for a few minutes on this device only. */
    public function confirmPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);
        $user = $request->user();
        if (! Hash::check($data['password'], $user->password)) {
            Audit::record('security.password_refused', $user);
            throw ValidationException::withMessages(['password' => 'That is not your password.']);
        }
        Reauth::confirm($user);

        return response()->json(['fresh_for_minutes' => Reauth::MINUTES]);
    }

    /** The module's record of changes and sensitive reads, newest first. */
    public function audit(Request $request): JsonResponse
    {
        Access::require($request->user(), 'wf:audit:view');
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:80'], 'employee_id' => ['nullable', 'integer'], 'actor_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);

        return response()->json(
            AuditEvent::query()
                ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', $a.'%'))
                ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
                ->when($filters['actor_id'] ?? null, fn ($q, $id) => $q->where('actor_id', $id))
                ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
                ->latest('id')->paginate(50, ['id', 'actor_name', 'action', 'subject_type', 'subject_id', 'employee_id', 'reason', 'before', 'after', 'created_at'])
        );
    }

    /** Checks the record of changes from its first line: whether any line was altered or taken out behind the system's back. */
    public function verifyAudit(Request $request): JsonResponse
    {
        Access::require($request->user(), 'wf:audit:view');

        return response()->json(Audit::verify((int) Tenant::id()));
    }
}
