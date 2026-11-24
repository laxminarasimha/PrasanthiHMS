<?php

namespace App\Workforce\Http;

use App\Http\Controllers\Controller;
use App\Models\AdminEntry;
use App\Models\Branch;
use App\Models\User;
use App\Support\StaffLogin;
use App\Workforce\Models\Employee;
use App\Workforce\Support\Access;
use App\Workforce\Support\Audit;
use App\Workforce\Support\Permissions;
use App\Workforce\Support\Settings;
use App\Workforce\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The staff check: where the hospital's logins and its staff records do not yet agree, and the means to put it right.
 * A person and their login are meant to be one record. This lists every login that belongs to no person, every pair
 * whose details differ, and every mobile number that sits on two logins. Nothing here is changed without being asked.
 */
class StaffCheckController extends Controller
{
    use Helpers;

    /** For those who manage logins and reach the whole staff list. */
    private function allowed(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->allows('canManageStaff') && Permissions::reach($user, 'wf:staff:edit') === Permissions::ALL, 403, 'This is for those who manage staff logins.');

        return $user;
    }

    /** The logins of this hospital. One with no branch of its own belongs here only where there is a single branch. */
    private function logins(): Builder
    {
        $branch = Tenant::id();

        return User::query()->where(fn ($q) => $q->where('branch_id', $branch)->when(Branch::query()->count() === 1, fn ($or) => $or->orWhereNull('branch_id')));
    }

    public function show(Request $request): JsonResponse
    {
        $this->allowed($request);
        $staff = Employee::query()->orderBy('name')->get();
        $logins = $this->logins()->orderBy('name')->get();
        $joined = $staff->whereNotNull('user_id')->keyBy('user_id');
        $free = $staff->whereNull('user_id')->where('status', '!=', 'inactive');
        $person = fn (Employee $e) => ['id' => $e->id, 'name' => $e->name, 'employee_no' => $e->employee_no, 'phone' => $e->phone, 'designation' => $e->designation];
        $login = fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->label(), 'phone' => $u->phone, 'email' => $u->email, 'is_active' => (bool) $u->is_active];

        $loose = $logins->reject(fn (User $u) => $joined->has($u->id) || $u->is_shared_screen);

        return response()->json([
            // Logins that belong to no member of staff yet, each with the person it most likely is.
            'unjoined' => $loose->where('is_active', true)->values()->map(function (User $u) use ($free, $login, $person) {
                $match = $this->match($u, $free);

                return $login($u) + ['match' => $match ? $person($match[0]) + ['by' => $match[1]] : null];
            }),
            'switched_off' => $loose->where('is_active', false)->count(),
            // Joined pairs whose name, mobile or gender are not the same on both.
            'differences' => $staff->whereNotNull('user_id')->flatMap(function (Employee $e) use ($logins) {
                $u = $logins->firstWhere('id', $e->user_id);

                return $u ? collect(StaffLogin::SHARED)->filter(fn (string $f) => $this->differ($f, $e->{$f}, $u->{$f}))
                    ->map(fn (string $f) => ['employee_id' => $e->id, 'name' => $e->name, 'employee_no' => $e->employee_no, 'field' => $f, 'staff' => $e->{$f}, 'login' => $u->{$f}])->values() : [];
            })->values(),
            // A mobile number opens one login only. Where two active logins carry the same one, neither signs in with it by number alone.
            'shared_numbers' => $logins->where('is_active', true)->filter(fn (User $u) => StaffLogin::digits($u->phone) !== null)->groupBy(fn (User $u) => StaffLogin::digits($u->phone))
                ->filter(fn (Collection $same) => $same->count() > 1)->map(fn (Collection $same, string $mobile) => ['mobile' => $mobile, 'logins' => $same->map($login)->values()])->values(),
            'shared_screens' => $logins->where('is_shared_screen', true)->values()->map($login),
            // Staff who do not sign in. Nothing is wrong with that; they are listed so that the choice is a deliberate one.
            'without_login' => $free->values()->map($person),
            // Consultants on the staff list who cannot be chosen for an appointment or an admission yet.
            'consultants_not_doctors' => $free->where('employment_type', 'consultant')->values()->map($person),
            'totals' => ['staff' => $staff->where('status', '!=', 'inactive')->count(), 'logins' => $logins->where('is_active', true)->filter(fn (User $u) => $u->signsIn())->count(), 'joined' => $joined->count()],
        ]);
    }

    /**
     * The member of staff a login most likely is: the only one with the same mobile number, or failing that the
     * only one with the same email. Two candidates is no match: it is left for a person to choose.
     *
     * @return array{0: Employee, 1: string}|null
     */
    private function match(User $login, Collection $free): ?array
    {
        $mobile = StaffLogin::digits($login->phone);
        $same = $mobile ? $free->filter(fn (Employee $e) => StaffLogin::digits($e->phone) === $mobile) : collect();
        if ($same->count() === 1) {
            return [$same->first(), 'mobile'];
        }
        $same = filled($login->email) ? $free->filter(fn (Employee $e) => filled($e->email) && mb_strtolower($e->email) === mb_strtolower($login->email)) : collect();

        return $same->count() === 1 ? [$same->first(), 'email'] : null;
    }

    private function differ(string $field, mixed $staff, mixed $login): bool
    {
        if (blank($staff) || blank($login)) {
            return false;
        }

        return $field === 'phone' ? (StaffLogin::digits($staff) ?? $staff) !== (StaffLogin::digits($login) ?? $login) : mb_strtolower(trim((string) $staff)) !== mb_strtolower(trim((string) $login));
    }

    /** Joins a login to the member of staff it belongs to. Neither's details are changed: differences are settled separately. */
    public function join(Request $request): JsonResponse
    {
        $this->allowed($request);
        $data = $request->validate(['pairs' => ['required', 'array', 'min:1', 'max:200'], 'pairs.*.user_id' => ['required', 'integer'], 'pairs.*.employee_id' => ['required', 'integer']]);

        $done = DB::transaction(function () use ($data) {
            $done = 0;
            foreach ($data['pairs'] as $i => $pair) {
                $login = $this->logins()->find($pair['user_id']);
                $employee = Employee::query()->find($pair['employee_id']);
                if (! $login || ! $employee) {
                    throw ValidationException::withMessages(["pairs.{$i}" => 'Choose a login and a member of staff from the lists.']);
                }
                if ($employee->user_id || Employee::query()->where('user_id', $login->id)->exists()) {
                    throw ValidationException::withMessages(["pairs.{$i}" => "{$login->name} or {$employee->name} is already joined. Load the page again."]);
                }
                $employee->update(['user_id' => $login->id]);
                Audit::record('staff.login_joined', $employee, ['user_id' => null], ['user_id' => $login->id], 'Staff check', $employee->id);
                // A person who has already left does not keep an open login.
                StaffLogin::fromEmployee($employee);
                $done++;
            }

            return $done;
        });

        return response()->json(['joined' => $done]);
    }

    /** Takes a login off a member of staff again, should a join have been the wrong one. */
    public function unjoin(Request $request, int $employee): JsonResponse
    {
        $this->allowed($request);
        $employee = Employee::query()->findOrFail($employee);
        $was = $employee->user_id;
        abort_unless($was, 422, 'No login is joined to this member of staff.');
        $employee->update(['user_id' => null]);
        Audit::record('staff.login_unjoined', $employee, ['user_id' => $was], ['user_id' => null], $request->input('reason') ?: 'Staff check', $employee->id);

        return response()->json(['ok' => true]);
    }

    /**
     * Makes a staff record for each of these logins from what the login already says: name, mobile, email, gender and,
     * where the hospital's list has one by that name, the department. Nothing else is filled in: no designation, no
     * joining date, no pay. Those are for a person to enter.
     */
    public function create(Request $request): JsonResponse
    {
        $by = $this->allowed($request);
        $ids = $request->validate(['user_ids' => ['required', 'array', 'min:1', 'max:200'], 'user_ids.*' => ['integer']])['user_ids'];
        $departments = AdminEntry::query()->where('kind', 'department')->get(['id', 'name']);
        ['employee_prefix' => $prefix, 'employee_digits' => $digits] = Settings::get('numbering');

        $made = DB::transaction(function () use ($ids, $by, $departments, $prefix, $digits) {
            $next = (Employee::query()->where('employee_no', 'like', $prefix.'%')->pluck('employee_no')->map(fn (string $no) => (int) preg_replace('/\D/', '', substr($no, strlen($prefix))))->max() ?? 0) + 1;
            $made = 0;
            foreach ($this->logins()->whereIn('id', $ids)->where('is_shared_screen', false)->orderBy('id')->get() as $login) {
                if (Employee::query()->where('user_id', $login->id)->exists()) {
                    continue;
                }
                $employee = Employee::create([
                    'employee_no' => $prefix.str_pad((string) $next++, (int) $digits, '0', STR_PAD_LEFT), 'user_id' => $login->id, 'name' => $login->name, 'phone' => $login->phone, 'email' => $login->email, 'gender' => $login->gender,
                    'department_id' => filled($login->department) ? $departments->first(fn (AdminEntry $d) => mb_strtolower($d->name) === mb_strtolower(trim($login->department)))?->id : null,
                    'status' => $login->is_active ? 'active' : 'inactive', 'created_by' => $by->id,
                ]);
                Audit::record('staff.added', $employee, null, $employee->only(['employee_no', 'name', 'phone', 'email', 'gender', 'department_id', 'user_id']), 'Made from the login, on the staff check', $employee->id);
                $made++;
            }

            return $made;
        });

        return response()->json(['made' => $made]);
    }

    /** Settles a difference between a person and their login: one side's value is written to the other. */
    public function settle(Request $request): JsonResponse
    {
        $this->allowed($request);
        $data = $request->validate(['employee_id' => ['required', 'integer'], 'field' => ['required', Rule::in(StaffLogin::SHARED)], 'keep' => ['required', Rule::in(['staff', 'login'])]]);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        $login = $employee->user_id ? $this->logins()->find($employee->user_id) : null;
        abort_unless($login, 422, 'No login is joined to this member of staff.');
        $field = $data['field'];

        if ($data['keep'] === 'staff') {
            if ($field === 'phone') {
                StaffLogin::refuseTaken($employee->phone, $login, 'keep');
            }
            $login->update([$field => $employee->{$field}]);
        } else {
            $was = [$field => $employee->{$field}];
            $employee->update([$field => $login->{$field}]);
            Audit::record('staff.changed', $employee, $was, [$field => $login->{$field}], 'Taken from the login, on the staff check', $employee->id);
        }

        return response()->json(['ok' => true]);
    }

    /** Marks a login as a screen on the wall (the waiting-room board), which is not a member of staff, or takes the mark off. */
    public function sharedScreen(Request $request, int $user): JsonResponse
    {
        $this->allowed($request);
        $login = $this->logins()->findOrFail($user);
        $shared = (bool) $request->validate(['shared' => ['required', 'boolean']])['shared'];
        if ($shared && Employee::query()->where('user_id', $login->id)->exists()) {
            throw ValidationException::withMessages(['shared' => "{$login->name} is joined to a member of staff, so it is a person’s login."]);
        }
        $login->forceFill(['is_shared_screen' => $shared])->save();

        return response()->json(['ok' => true]);
    }
}
