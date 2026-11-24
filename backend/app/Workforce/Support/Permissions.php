<?php

namespace App\Workforce\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * What a person may do in the workforce module: staff records, attendance, leave, payroll, advances and expenses.
 *
 * A permission is named `wf:<what>:<action>`, the action being one of view, create, edit, approve, finalise,
 * reverse, export or admin. Each role starts with a preset; a tick or an untick made for one person under
 * Staff → Login and access overrides the preset for that person, and an untick always wins.
 *
 * A preset also says how far a permission reaches: over all staff of the branch, over the staff of the departments
 * the person looks after, or over the person's own record only. The reach is enforced by `Access` on the server.
 */
final class Permissions
{
    public const ALL = 'all';

    public const DEPARTMENT = 'department';

    public const SELF = 'self';

    /**
     * Every permission: [group shown on the Staff screen, name, what it allows, whether only an admin may add it on
     * top of a role].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public const CATALOGUE = [
        'wf:self:view' => ['Own records', 'See own attendance, leave and payslips', 'Every member of staff with a login sees their own record. Nothing of anyone else’s.', false],
        'wf:self:create' => ['Own records', 'Ask for leave and corrections', 'Ask for leave, ask for an attendance correction, and mark own attendance where the hospital allows it.', false],

        'wf:staff:view' => ['Staff directory', 'See the staff directory', 'Names, departments, designations, contact numbers and employment dates.', false],
        'wf:staff:create' => ['Staff directory', 'Add staff', 'Add a member of staff to the directory.', false],
        'wf:staff:edit' => ['Staff directory', 'Change staff details', 'Change a person’s details, department, shift and employment status.', false],
        'wf:staff:export' => ['Staff directory', 'Export the staff directory', 'Download the directory as a file.', true],
        'wf:staff_private:view' => ['Staff directory', 'See bank, tax and identity details', 'Bank account, PAN, Aadhaar, PF and ESI numbers. Every look is recorded.', true],
        'wf:staff_private:edit' => ['Staff directory', 'Change bank, tax and identity details', 'Enter or change those numbers.', true],

        'wf:attendance:view' => ['Attendance', 'See attendance', 'Daily and monthly attendance.', false],
        'wf:attendance:create' => ['Attendance', 'Enter attendance', 'Mark attendance by hand and bring in an attendance sheet.', false],
        'wf:attendance:edit' => ['Attendance', 'Correct attendance directly', 'Change a saved attendance entry without asking, giving the reason.', false],
        'wf:attendance:approve' => ['Attendance', 'Decide correction requests', 'Approve or refuse a correction someone has asked for.', false],
        'wf:attendance:export' => ['Attendance', 'Export attendance', 'Download attendance as a file.', true],

        'wf:leave:view' => ['Leave', 'See leave and balances', 'Leave requests and each person’s leave account.', false],
        'wf:leave:create' => ['Leave', 'Enter leave for staff', 'Put in a leave request on someone’s behalf.', false],
        'wf:leave:approve' => ['Leave', 'Approve or refuse leave', 'Decide a leave request.', false],
        'wf:leave:edit' => ['Leave', 'Adjust leave balances', 'Add a correcting line to a leave account, with a reason.', true],
        'wf:leave:export' => ['Leave', 'Export leave', 'Download leave records as a file.', true],

        'wf:salary:view' => ['Pay', 'See salaries', 'What each person is paid, and each payslip’s lines.', true],
        'wf:salary:edit' => ['Pay', 'Set salaries', 'Enter or change a person’s salary structure, and the parts salaries are made of.', true],
        'wf:salary:approve' => ['Pay', 'Approve a salary change', 'A second person’s approval of a new salary structure.', true],

        'wf:payroll:view' => ['Payroll', 'See payroll runs', 'Each run’s status and totals. Individual pay needs “See salaries” as well.', false],
        'wf:payroll:create' => ['Payroll', 'Start a payroll run', 'Work out a draft payroll for a month.', true],
        'wf:payroll:edit' => ['Payroll', 'Review a payroll run', 'Check a draft, add one-off additions and deductions, and mark it reviewed.', true],
        'wf:payroll:approve' => ['Payroll', 'Approve a payroll run', 'Approve a reviewed payroll for finalising, and record salaries as paid.', true],
        'wf:payroll:finalise' => ['Payroll', 'Finalise a payroll run', 'Lock a payroll for good. After this it can only be reversed.', true],
        'wf:payroll:reverse' => ['Payroll', 'Reverse a finalised payroll', 'Undo a finalised payroll, with a reason, so that it can be run again.', true],
        'wf:payroll:export' => ['Payroll', 'Export payroll and payslips', 'Download the payroll register and payslips.', true],

        'wf:advances:view' => ['Advances and loans', 'See advances and loans', 'What has been given, the repayment plan and what is still owed.', false],
        'wf:advances:create' => ['Advances and loans', 'Enter an advance or loan request', 'Put in a request for a member of staff.', false],
        'wf:advances:approve' => ['Advances and loans', 'Approve and issue advances', 'Approve a request and record the money as given.', true],
        'wf:advances:reverse' => ['Advances and loans', 'Waive or write off an advance', 'Let go of what is still owed, with a reason.', true],
        'wf:advances:export' => ['Advances and loans', 'Export advances', 'Download advances and loans as a file.', true],

        'wf:expenses:view' => ['Expenses', 'See expenses', 'Expense claims and bills at every stage.', false],
        'wf:expenses:create' => ['Expenses', 'Enter an expense', 'Write up an expense and send it for approval.', false],
        'wf:expenses:edit' => ['Expenses', 'Change an expense', 'Correct an expense that has not been paid yet.', false],
        'wf:expenses:approve' => ['Expenses', 'Approve or refuse expenses', 'Decide an expense, up to the amount its category allows.', false],
        'wf:expenses:finalise' => ['Expenses', 'Approve large expenses and record payment', 'Approve above a category’s limit, and record an approved expense as paid.', true],
        'wf:expenses:reverse' => ['Expenses', 'Reverse or cancel a paid expense', 'Undo a paid expense, with a reason.', true],
        'wf:expenses:export' => ['Expenses', 'Export expenses', 'Download expenses as a file.', true],

        'wf:reports:view' => ['Workforce reports', 'See workforce dashboards and reports', 'Who is in today, leave waiting, and the monthly summaries, within what the person may otherwise see.', false],
        'wf:reports:export' => ['Workforce reports', 'Export workforce reports', 'Download a report as a file.', true],
        'wf:settings:admin' => ['Workforce setup', 'Set up shifts, holidays, leave types and categories', 'The lists and rules the module runs on.', false],
        'wf:audit:view' => ['Workforce setup', 'See the workforce record of changes', 'Who changed or looked at what, when and why.', true],
        'wf:audit:export' => ['Workforce setup', 'Export the record of changes', 'Download the record as a file.', true],
    ];

    /**
     * What each role starts with, and how far each permission reaches. A role not named here has only its own records.
     *
     * @return array<string, string> permission => reach
     */
    public static function preset(Role $role): array
    {
        $own = ['wf:self:view' => self::SELF, 'wf:self:create' => self::SELF];
        $all = fn (array $keys) => array_fill_keys($keys, self::ALL);

        return $own + match ($role) {
            // The owner, or whoever holds the admin login: everything.
            Role::Admin => $all(array_keys(self::CATALOGUE)),

            // Runs the day and sees the whole picture, without pay details, money decisions or exports.
            Role::Manager => $all([
                'wf:staff:view', 'wf:staff:create', 'wf:staff:edit',
                'wf:attendance:view', 'wf:attendance:create', 'wf:attendance:edit', 'wf:attendance:approve',
                'wf:leave:view', 'wf:leave:create', 'wf:leave:approve',
                'wf:payroll:view', 'wf:advances:view', 'wf:advances:create',
                'wf:expenses:view', 'wf:expenses:create', 'wf:expenses:edit', 'wf:expenses:approve',
                'wf:reports:view', 'wf:settings:admin',
            ]),

            // Keeps the staff records and prepares the payroll. Approving and finalising it is someone else's.
            Role::Hr => $all([
                'wf:staff:view', 'wf:staff:create', 'wf:staff:edit', 'wf:staff:export', 'wf:staff_private:view', 'wf:staff_private:edit',
                'wf:attendance:view', 'wf:attendance:create', 'wf:attendance:edit', 'wf:attendance:approve', 'wf:attendance:export',
                'wf:leave:view', 'wf:leave:create', 'wf:leave:approve', 'wf:leave:edit', 'wf:leave:export',
                'wf:salary:view', 'wf:salary:edit',
                'wf:payroll:view', 'wf:payroll:create', 'wf:payroll:edit', 'wf:payroll:export',
                'wf:advances:view', 'wf:advances:create',
                'wf:reports:view', 'wf:reports:export', 'wf:settings:admin',
            ]),

            // Checks and approves the money: payroll, advances and expenses. Does not keep staff records.
            Role::Accountant => $all([
                'wf:staff:view', 'wf:attendance:view', 'wf:leave:view',
                'wf:salary:view', 'wf:salary:approve',
                'wf:payroll:view', 'wf:payroll:approve', 'wf:payroll:export',
                'wf:advances:view', 'wf:advances:approve', 'wf:advances:export',
                'wf:expenses:view', 'wf:expenses:create', 'wf:expenses:edit', 'wf:expenses:approve', 'wf:expenses:finalise', 'wf:expenses:export',
                'wf:reports:view', 'wf:reports:export',
            ]),

            // Looks after the staff of their own departments: who is in, their leave, their corrections.
            Role::DeptManager => array_fill_keys([
                'wf:staff:view', 'wf:attendance:view', 'wf:attendance:create', 'wf:attendance:approve',
                'wf:leave:view', 'wf:leave:create', 'wf:leave:approve', 'wf:reports:view',
            ], self::DEPARTMENT) + ['wf:expenses:create' => self::SELF, 'wf:expenses:view' => self::SELF],

            default => [],
        };
    }

    /** What comes with managing logins (canManageStaff), for someone whose role does not already give it. */
    public const WITH_LOGINS = ['wf:staff:view', 'wf:staff:create', 'wf:staff:edit'];

    /** Whether a permission name belongs to this module. */
    public static function has(string $permission): bool
    {
        return isset(self::CATALOGUE[$permission]);
    }

    /**
     * How far this person's permission reaches: all, department, self, or null when they do not have it.
     * Their own untick beats the role; their own tick gives the role's reach, or the whole branch where the role
     * had none.
     */
    public static function reach(User $user, string $permission): ?string
    {
        if (! self::has($permission)) {
            return null;
        }
        $preset = self::preset($user->role)[$permission] ?? null;
        if ($user->role === Role::Admin) {
            return self::ALL;
        }
        $own = $user->permissions[$permission] ?? null;
        if ($own === false) {
            return null;
        }
        // Whoever manages the hospital's logins keeps the list of people they belong to: a person and their login
        // are one record. Pay, bank details and the rest of the module stay with those named for them.
        if ($preset === null && in_array($permission, self::WITH_LOGINS, true) && $user->allows('canManageStaff')) {
            return self::ALL;
        }

        return $own === true ? ($preset ?? self::ALL) : $preset;
    }

    public static function allows(User $user, string $permission): bool
    {
        return self::reach($user, $permission) !== null;
    }

    /** Whether only an admin may add this permission on top of a role. */
    public static function sensitive(string $permission): bool
    {
        return self::CATALOGUE[$permission][3] ?? false;
    }
}
