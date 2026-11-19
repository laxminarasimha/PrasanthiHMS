<?php

namespace App\Workforce\Support;

use App\Models\User;
use App\Workforce\Models\DepartmentManager;
use App\Workforce\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose records a person may reach with a permission. The branch wall is already in every query; this narrows
 * further, on the server, to the departments the person looks after or to the person's own record, according to
 * how far their permission reaches. A screen cannot ask for more than this returns.
 */
final class Access
{
    /** Stops with "not allowed" unless the person has the permission at all. */
    public static function require(User $user, string $permission): string
    {
        $reach = Permissions::reach($user, $permission);
        abort_if($reach === null, 403, 'You are not allowed to do this.');

        return $reach;
    }

    /** The staff this person may reach with this permission. Nobody at all if they do not have it. */
    public static function employees(User $user, string $permission): Builder
    {
        return self::narrow(Employee::query(), $user, $permission, 'id');
    }

    /**
     * Narrows any query whose rows belong to a member of staff (attendance, leave, payslips…) to the staff this
     * person may reach. `$column` is the column holding the employee's id.
     */
    public static function narrow(Builder $query, User $user, string $permission, string $column = 'employee_id'): Builder
    {
        $reach = Permissions::reach($user, $permission);
        $column = str_contains($column, '.') ? $column : $query->getModel()->getTable().'.'.$column;

        return match ($reach) {
            Permissions::ALL => $query,
            Permissions::DEPARTMENT => $query->whereIn($column, Employee::query()->whereIn('department_id', self::departments($user))->select('id')),
            Permissions::SELF => $query->whereIn($column, Employee::query()->where('user_id', $user->id)->select('id')),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Whether this person may reach this one member of staff with this permission. */
    public static function reaches(User $user, string $permission, Employee $employee): bool
    {
        return self::employees($user, $permission)->whereKey($employee->getKey())->exists();
    }

    /** Stops with "not allowed" unless the person may reach this member of staff. */
    public static function requireFor(User $user, string $permission, Employee $employee): void
    {
        abort_unless(self::reaches($user, $permission, $employee), 403, 'You are not allowed to do this for this member of staff.');
    }

    /**
     * The departments a person looks after.
     *
     * @return list<int>
     */
    public static function departments(User $user): array
    {
        return DepartmentManager::query()->where('user_id', $user->id)->pluck('department_id')->map(fn ($id) => (int) $id)->all();
    }
}
