<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Workforce\Models\Employee;
use App\Workforce\Support\Audit;
use App\Workforce\Support\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The hospital's list of doctors, seen from the staff list. A doctor is a login with the Doctor role, and nothing
 * else: every screen that offers doctors reads that. This is where a member of staff who is a doctor, and has no
 * login, is given the record that puts them on those lists without being able to sign in.
 */
final class DoctorList
{
    /** Engaged as a consultant: a doctor, by the hospital's own rule. Such a person is put on the list without being asked. */
    public static function isConsultant(Employee $employee): bool
    {
        return $employee->employment_type === 'consultant';
    }

    /** Written as a doctor ("Dr …") though not engaged as a consultant. A likely doctor: offered, never assumed. */
    public static function isNamedDoctor(Employee $employee): bool
    {
        return preg_match('/^\s*(dr|doctor)\b/i', (string) $employee->name) === 1;
    }

    /**
     * Staff who are doctors by what is written on their record, are on the staff, and are on no list of doctors
     * because they have no login.
     *
     * @param  Collection<int, Employee>  $staff
     * @return Collection<int, Employee>
     */
    public static function leftOut(Collection $staff): Collection
    {
        return $staff->filter(fn (Employee $e) => $e->user_id === null && $e->status !== 'inactive' && (self::isConsultant($e) || self::isNamedDoctor($e)))->values();
    }

    /**
     * Gives a member of staff with no login the record that puts them on the lists of doctors. It cannot be signed
     * in with, and patients' own booking does not offer it until someone says so.
     *
     * @param  array<string, mixed>  $more  Qualification (`signature_text`) and `book_online`, when they were given.
     */
    public static function put(Employee $employee, array $more = [], ?User $by = null, string $why = 'Put on the list of doctors; does not sign in'): User
    {
        return DB::transaction(function () use ($employee, $more, $by, $why) {
            $login = User::create($more + [
                'name' => $employee->name, 'phone' => $employee->phone, 'gender' => $employee->gender, 'department' => $employee->department?->name, 'role' => Role::Doctor->value,
                'branch_id' => $by?->branch_id ?? $employee->branch_id, 'is_active' => true, 'sign_in_with' => 'none', 'password' => Str::random(48),
                'book_online' => $more['book_online'] ?? false,
            ])->refresh();
            $employee->update(['user_id' => $login->id]);
            Audit::record('staff.doctor_on', $employee, ['doctor' => false], ['doctor' => true, 'user_id' => $login->id], $why, $employee->id, $by);

            return $login;
        });
    }

    /**
     * Every consultant on the staff list who is on no list of doctors is put on them. Run once when this rule came
     * in, for the consultants added before it. One who cannot be put on is left for the staff check to show.
     */
    public static function catchUp(): int
    {
        $done = 0;
        $waiting = Employee::withoutGlobalScopes()->whereNull('user_id')->where('status', '!=', 'inactive')->where('employment_type', 'consultant')->get();
        foreach ($waiting as $employee) {
            try {
                $work = function () use ($employee) {
                    $login = self::put($employee, [], null, 'A consultant on the staff list is on the list of doctors');
                    AuditLog::record('staff.doctor', null, $login, "{$login->name} · on the list of doctors · does not sign in", null, 'The system');
                };
                $employee->branch_id ? Tenant::run((int) $employee->branch_id, $work) : $work();
                $done++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $done;
    }
}
