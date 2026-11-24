<?php

namespace App\Workforce\Http;

use App\Http\Controllers\Controller;
use App\Models\AdminEntry;
use App\Models\User;
use App\Support\StaffLogin;
use App\Workforce\Models\DepartmentManager;
use App\Workforce\Models\Employee;
use App\Workforce\Models\EmployeePrivate;
use App\Workforce\Models\HolidayCalendar;
use App\Workforce\Models\Shift;
use App\Workforce\Support\Access;
use App\Workforce\Support\Audit;
use App\Workforce\Support\Permissions;
use App\Workforce\Support\Reauth;
use App\Workforce\Support\Settings;
use App\Enums\Role;
use App\Models\Appointment;
use App\Workforce\Models\Attendance;
use App\Workforce\Models\LeaveRequest;
use App\Workforce\Models\LeaveType;
use App\Workforce\Support\AttendanceRules;
use App\Workforce\Support\LeaveBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The staff directory: everyone who works for the hospital, whether or not they sign in to this system. */
class EmployeeController extends Controller
{
    use Helpers;

    private const WITH = ['department:id,name', 'shift:id,name', 'manager:id,name,employee_no', 'user:id,name,role,is_active,sign_in_with,email,must_change_secret,signature_text,book_online'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:staff:view');
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in([...Employee::STATUSES, 'all'])], 'department_id' => ['nullable', 'integer']]);
        $term = trim((string) ($filters['q'] ?? ''));
        $status = $filters['status'] ?? 'active';

        $staff = Access::employees($user, 'wf:staff:view')->with(self::WITH)
            ->when($status !== 'all', fn ($q) => $status === 'active' ? $q->where('status', '!=', 'inactive') : $q->where('status', $status))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('employee_no', 'like', "%{$term}%")->orWhere('phone', 'like', "{$term}%")->orWhere('designation', 'like', "%{$term}%")))
            ->orderBy('name')->limit(500)->get();

        return response()->json([
            'data' => $staff->map(fn (Employee $e) => $this->present($e)),
            'counts' => Access::employees($user, 'wf:staff:view')->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    /** What the staff form and the filters need to offer. */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(collect(Permissions::CATALOGUE)->keys()->contains(fn ($key) => ! str_starts_with($key, 'wf:self:') && $user->allows($key)), 403, 'You are not allowed to see this.');
        $linked = Employee::query()->whereNotNull('user_id')->pluck('user_id');

        return response()->json([
            'departments' => AdminEntry::query()->where('kind', 'department')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'shifts' => Shift::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'starts_at', 'ends_at']),
            'calendars' => HolidayCalendar::query()->orderBy('name')->get(['id', 'name', 'is_default']),
            'managers' => Access::employees($user, 'wf:staff:view')->where('status', '!=', 'inactive')->orderBy('name')->get(['id', 'name', 'employee_no']),
            // Logins of this branch that no member of staff is joined to yet.
            'logins' => $user->allows('wf:staff:edit') ? User::query()->where('branch_id', $user->branch_id)->where('is_active', true)->whereNotIn('id', $linked)->orderBy('name')->get(['id', 'name', 'role'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->label()]) : [],
            'types' => Employee::TYPES, 'statuses' => Employee::STATUSES,
            'next_employee_no' => $this->nextNumber(),
        ]);
    }

    public function show(Request $request, int $employee): JsonResponse
    {
        $employee = Employee::query()->with(self::WITH)->findOrFail($employee);
        Access::requireFor($request->user(), 'wf:staff:view', $employee);

        return response()->json($this->present($employee) + [
            'login' => $employee->user ? ['id' => $employee->user->id, 'name' => $employee->user->name, 'email' => $employee->user->email] : null,
            'has_private' => EmployeePrivate::query()->where('employee_id', $employee->id)->exists(),
        ]);
    }

    /**
     * One person at a glance, for the window that opens from their name wherever it is shown: who they are, whether
     * they are in today, their month, their leave, and a doctor's hours. Each part is there only for someone who may
     * see it elsewhere, and pay and bank details are never part of it.
     */
    public function card(Request $request, int $employee): JsonResponse
    {
        $user = $request->user();
        $employee = Employee::query()->with(self::WITH)->findOrFail($employee);
        $employee->setRelation('user', $employee->user_id ? User::query()->find($employee->user_id) : null);
        Access::requireFor($user, 'wf:staff:view', $employee);
        $sees = fn (string $permission) => Access::reaches($user, $permission, $employee);
        $today = now()->toDateString();
        $card = ['person' => $this->present($employee), 'can' => ['edit' => $sees('wf:staff:edit'), 'check_in' => $sees('wf:attendance:create') && $employee->status !== 'inactive']];

        if ($sees('wf:attendance:view')) {
            $days = AttendanceRules::days(collect([$employee]), now()->startOfMonth()->toDateString(), $today)[$employee->id];
            $attendance = app(AttendanceController::class);
            $unfinished = Attendance::query()->where('employee_id', $employee->id)->where('state', 'open')->whereNotNull('check_in')->with('shift')->latest('check_in')->get();
            $on = $unfinished->first(fn (Attendance $a) => AttendanceRules::stillOn($a));
            $card['today'] = [
                'status' => $days[$today]['status'], 'shift' => $days[$today]['shift'], 'note' => $days[$today]['note'] ?? null, 'late_minutes' => $days[$today]['late_minutes'], 'worked_minutes' => $days[$today]['worked_minutes'],
                // The duty they are on right now. Last night's night duty is still this one until it is over.
                'on' => $on ? $attendance->record($on) : null,
                'records' => Attendance::query()->where('employee_id', $employee->id)->whereDate('work_date', $today)->orderBy('check_in')->get()->map(fn (Attendance $a) => $attendance->record($a))->values(),
            ];
            $card['month'] = ['name' => now()->format('F Y')] + array_intersect_key(AttendanceRules::totals($days), array_flip(['present', 'leave', 'absent', 'late_days', 'worked_minutes']));
        }

        if ($sees('wf:leave:view')) {
            $year = LeaveBook::yearOf($today);
            $card['leave'] = [
                'left' => LeaveType::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])->map(fn (LeaveType $t) => ['code' => $t->code, 'name' => $t->name, 'left' => LeaveBook::balance($employee->id, $t->id, $year)])->values(),
                'coming' => LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->whereDate('to_date', '>=', $today)->with('type:id,name')->orderBy('from_date')->limit(3)->get()
                    ->map(fn (LeaveRequest $r) => ['from' => $r->from_date->format('Y-m-d'), 'to' => $r->to_date->format('Y-m-d'), 'days' => (float) $r->days, 'status' => $r->status, 'type' => $r->type?->name])->values(),
            ];
        }

        // A doctor's own hours for seeing patients, and how many are booked with them today.
        if ($employee->user?->role === Role::Doctor) {
            $card['doctor'] = ['availability' => $employee->user->availability, 'slot_minutes' => $employee->user->slot_minutes]
                + ($user->allows('canWorkFrontDesk') || $user->allows('canConsult') || $user->allows('canViewReports')
                    ? ['booked_today' => Appointment::query()->where('doctor_id', $employee->user->id)->whereDate('scheduled_at', $today)->where('status', '!=', 'cancelled')->count()] : []);
        }

        return response()->json($card);
    }

    public function store(Request $request): JsonResponse
    {
        // Adding to the directory is for those who reach all of it: a department manager does not add staff.
        abort_unless(Access::require($request->user(), 'wf:staff:create') === Permissions::ALL, 403, 'You are not allowed to add staff.');
        $data = $this->checked($request, null);
        $data['employee_no'] = filled($data['employee_no'] ?? null) ? $data['employee_no'] : $this->nextNumber();
        $employee = Employee::create($data + ['created_by' => $request->user()->id]);
        Audit::record('staff.added', $employee, null, $employee->only(array_keys($data)), null, $employee->id);
        StaffLogin::fromEmployee($employee);

        return response()->json($this->present($employee->load(self::WITH)), 201);
    }

    public function update(Request $request, int $employee): JsonResponse
    {
        $employee = Employee::query()->findOrFail($employee);
        Access::requireFor($request->user(), 'wf:staff:edit', $employee);
        $data = $this->checked($request, $employee);
        // Someone marked as left is given a leaving date; nobody is ever removed.
        if (($data['status'] ?? null) === 'inactive' && empty($data['separated_on'])) {
            $data['separated_on'] = now()->toDateString();
        }
        $plain = fn (Employee $e) => array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $e->only(array_keys($data)));
        $was = $plain($employee);
        $employee->update($data);
        [$before, $after] = Audit::difference($was, $plain($employee->fresh()));
        if ($after !== []) {
            Audit::record('staff.changed', $employee, $before, $after, $request->input('reason'), $employee->id);
        }
        // The person's login follows: the same name and mobile, and closed if they have left.
        StaffLogin::fromEmployee($employee->fresh(), array_keys($after));

        return response()->json($this->present($employee->fresh()->load(self::WITH)));
    }

    /**
     * Bank, tax and identity details. Shown with all but the last characters hidden; the full numbers only to
     * someone who has just typed their password again. Either way the look is written to the record.
     */
    public function private(Request $request, int $employee): JsonResponse
    {
        $employee = Employee::query()->findOrFail($employee);
        $user = $request->user();
        Access::require($user, 'wf:staff_private:view');
        Access::requireFor($user, 'wf:staff:view', $employee);
        $reveal = $request->boolean('reveal');
        abort_if($reveal && ! Reauth::fresh($user), 428, 'Type your password again to see the full numbers.');

        $private = EmployeePrivate::query()->firstOrNew(['employee_id' => $employee->id]);
        Audit::record($reveal ? 'staff.private.revealed' : 'staff.private.viewed', $employee, null, null, null, $employee->id);

        return response()->json([
            'bank_name' => $private->bank_name, 'tax_regime' => $private->tax_regime, 'revealed' => $reveal,
            ...collect(EmployeePrivate::SECRET)->mapWithKeys(fn (string $field) => [$field => $reveal ? $private->readable($field) : EmployeePrivate::masked($private->readable($field))])->all(),
            // Entered before the live site kept its key from one deploy to the next: these must be typed again.
            'unreadable' => $private->exists ? $private->unreadable() : [],
            'can_edit' => $user->allows('wf:staff_private:edit'),
        ]);
    }

    public function savePrivate(Request $request, int $employee): JsonResponse
    {
        $employee = Employee::query()->findOrFail($employee);
        $user = $request->user();
        Access::require($user, 'wf:staff_private:edit');
        Access::requireFor($user, 'wf:staff:view', $employee);
        abort_unless(Reauth::fresh($user), 428, 'Type your password again to change these details.');
        // A box left exactly as it was shown (masked) means "no change", not "save the dots": it is set aside before checking.
        $typed = array_filter($request->all(), fn ($value) => ! is_string($value) || ! str_contains($value, '•'));
        $data = Validator::make($typed, [
            'bank_name' => ['nullable', 'string', 'max:120'], 'bank_account_no' => ['nullable', 'string', 'regex:/^[0-9A-Za-z ]{4,34}$/'], 'bank_ifsc' => ['nullable', 'string', 'regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/'],
            'upi_id' => ['nullable', 'string', 'max:100'], 'pan' => ['nullable', 'string', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'], 'aadhaar' => ['nullable', 'string', 'regex:/^[0-9 ]{12,14}$/'],
            'uan' => ['nullable', 'string', 'regex:/^[0-9]{12}$/'], 'esi_no' => ['nullable', 'string', 'regex:/^[0-9]{10,17}$/'], 'tax_regime' => ['nullable', Rule::in(['old', 'new'])],
            'reason' => ['required', 'string', 'max:300'],
        ], ['bank_ifsc.regex' => 'An IFSC is 11 characters, such as ABCD0123456.', 'pan.regex' => 'A PAN is 10 characters, such as ABCDE1234F.', 'aadhaar.regex' => 'An Aadhaar number is 12 digits.', 'uan.regex' => 'A UAN is 12 digits.', 'reason.required' => 'Say why these details are being changed.'])->validate();
        $reason = $data['reason'];
        unset($data['reason']);

        $private = EmployeePrivate::query()->firstOrNew(['employee_id' => $employee->id]);
        // A value that can no longer be read counts as empty here, so typing it again replaces it cleanly.
        $lost = $private->exists ? $private->unreadable() : [];
        if ($lost !== []) {
            $private->setRawAttributes(array_merge($private->getAttributes(), array_fill_keys($lost, null)), true);
        }
        $was = collect(array_keys($data))->mapWithKeys(fn (string $field) => [$field => in_array($field, EmployeePrivate::SECRET, true) ? $private->readable($field) : $private->{$field}])->all();
        $private->fill($data + ['updated_by' => $user->id])->save();
        [$before, $after] = Audit::difference($was, $private->only(array_keys($data)));
        Audit::record('staff.private.changed', $employee, $before, $after, $reason, $employee->id);

        return response()->json(['saved' => true]);
    }

    /** The directory as a file. Never includes private details or pay. */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:staff:export');
        $staff = Access::employees($user, 'wf:staff:view')->with(self::WITH)->orderBy('name')->get();
        Audit::record('staff.exported', null, null, ['rows' => $staff->count()]);

        return $this->csv('staff-'.now()->format('Y-m-d').'.csv', ['Employee no.', 'Name', 'Status', 'Department', 'Designation', 'Type', 'Phone', 'Email', 'Joined', 'Left', 'Shift', 'Reports to'],
            $staff->map(fn (Employee $e) => [$e->employee_no, $e->name, $e->status, $e->department?->name, $e->designation, $e->employment_type, $e->phone, $e->email, $e->joined_on?->format('Y-m-d'), $e->separated_on?->format('Y-m-d'), $e->shift?->name, $e->manager?->name]));
    }

    /** Who looks after a department's staff. */
    public function managers(Request $request, int $department): JsonResponse
    {
        Access::require($request->user(), 'wf:settings:admin');
        $department = AdminEntry::query()->where('kind', 'department')->findOrFail($department);
        if ($request->isMethod('GET')) {
            return response()->json(DepartmentManager::query()->where('department_id', $department->id)->pluck('user_id'));
        }
        $ids = $request->validate(['user_ids' => ['present', 'array'], 'user_ids.*' => ['integer', Rule::exists('users', 'id')->where('branch_id', $request->user()->branch_id)]])['user_ids'];
        $was = DepartmentManager::query()->where('department_id', $department->id)->pluck('user_id')->all();
        DepartmentManager::query()->where('department_id', $department->id)->whereNotIn('user_id', $ids)->delete();
        foreach (array_diff($ids, $was) as $id) {
            DepartmentManager::create(['department_id' => $department->id, 'user_id' => $id]);
        }
        Audit::record('department.managers', $department, ['user_ids' => $was], ['user_ids' => array_values($ids)]);

        return response()->json(array_values($ids));
    }

    /** @return array<string, mixed> */
    private function checked(Request $request, ?Employee $employee): array
    {
        $data = $request->validate([
            'employee_no' => [$employee ? 'required' : 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in(Employee::STATUSES)],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+\- ]{7,20}$/'], 'email' => ['nullable', 'email', 'max:150'], 'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'], 'emergency_contact_phone' => ['nullable', 'string', 'regex:/^[0-9+\- ]{7,20}$/'],
            'department_id' => ['nullable', 'integer', Rule::exists('admin_entries', 'id')->where('kind', 'department')],
            'designation' => ['nullable', 'string', 'max:100'], 'manager_id' => ['nullable', 'integer'], 'work_location' => ['nullable', 'string', 'max:100'],
            'joined_on' => ['nullable', 'date'], 'confirmed_on' => ['nullable', 'date', 'after_or_equal:joined_on'], 'separated_on' => ['nullable', 'date', 'after_or_equal:joined_on'],
            'separation_reason' => ['nullable', 'string', 'max:255'], 'employment_type' => ['sometimes', Rule::in(Employee::TYPES)],
            'shift_id' => ['nullable', 'integer'], 'weekly_off' => ['nullable', 'array', 'max:7'], 'weekly_off.*' => [Rule::in(['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'])],
            'holiday_calendar_id' => ['nullable', 'integer'], 'payment_mode' => ['nullable', Rule::in(['bank', 'cash', 'upi', 'cheque'])], 'user_id' => ['nullable', 'integer'],
            // In the daily attendance and on the payroll. Left out, the usual rule decides: doctors and consultants are not.
            'regular' => ['sometimes', 'nullable', 'boolean'],
        ], ['employee_no.regex' => 'Use letters, numbers, - and / only.', 'separated_on.after_or_equal' => 'The leaving date cannot be before the joining date.']);

        // Everything named by an id must be this hospital's own.
        $this->owned(Shift::class, $data['shift_id'] ?? null, 'shift_id');
        $this->owned(HolidayCalendar::class, $data['holiday_calendar_id'] ?? null, 'holiday_calendar_id');
        $manager = $this->owned(Employee::class, $data['manager_id'] ?? null, 'manager_id');
        if ($manager && $employee && $manager->id === $employee->id) {
            throw ValidationException::withMessages(['manager_id' => 'A person cannot report to themself.']);
        }
        if (filled($data['user_id'] ?? null)) {
            $login = User::query()->where('branch_id', $request->user()->branch_id)->find($data['user_id']);
            $taken = Employee::query()->where('user_id', $data['user_id'])->when($employee, fn ($q) => $q->where('id', '!=', $employee->id))->exists();
            if (! $login || $taken) {
                throw ValidationException::withMessages(['user_id' => $taken ? 'That login already belongs to another member of staff.' : 'Choose a login from the list.']);
            }
        }
        if (filled($data['employee_no'] ?? null) && Employee::query()->where('employee_no', $data['employee_no'])->when($employee, fn ($q) => $q->where('id', '!=', $employee->id))->exists()) {
            throw ValidationException::withMessages(['employee_no' => 'Another member of staff already has this number.']);
        }

        return $data;
    }

    /** The next unused number in the hospital's own pattern. */
    private function nextNumber(): string
    {
        ['employee_prefix' => $prefix, 'employee_digits' => $digits] = Settings::get('numbering');
        $highest = Employee::query()->where('employee_no', 'like', $prefix.'%')->pluck('employee_no')
            ->map(fn (string $no) => (int) preg_replace('/\D/', '', substr($no, strlen($prefix))))->max() ?? 0;

        return $prefix.str_pad((string) ($highest + 1), (int) $digits, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function present(Employee $e): array
    {
        return $this->brief($e) + [
            'phone' => $e->phone, 'email' => $e->email, 'gender' => $e->gender, 'emergency_contact_name' => $e->emergency_contact_name, 'emergency_contact_phone' => $e->emergency_contact_phone,
            'department_id' => $e->department_id, 'manager_id' => $e->manager_id, 'manager' => $e->relationLoaded('manager') && $e->manager ? ['id' => $e->manager->id, 'name' => $e->manager->name] : null,
            'work_location' => $e->work_location, 'joined_on' => $e->joined_on?->format('Y-m-d'), 'confirmed_on' => $e->confirmed_on?->format('Y-m-d'), 'separated_on' => $e->separated_on?->format('Y-m-d'),
            'separation_reason' => $e->separation_reason, 'employment_type' => $e->employment_type, 'shift_id' => $e->shift_id, 'shift' => $e->relationLoaded('shift') ? $e->shift?->name : null,
            'weekly_off' => $e->weekly_off, 'holiday_calendar_id' => $e->holiday_calendar_id, 'payment_mode' => $e->payment_mode, 'user_id' => $e->user_id,
            'regular' => $e->isRegular(), 'regular_set' => $e->regular,
            // Whether the person signs in and as what. What they may do there is for those who manage logins.
            'login' => $e->relationLoaded('user') && $e->user ? ['id' => $e->user->id, 'role' => $e->user->role->value, 'role_label' => $e->user->role->label(), 'is_active' => (bool) $e->user->is_active, 'sign_in_with' => $e->user->sign_in_with ?? 'password', 'email' => $e->user->email, 'waiting_for_own_pin' => (bool) $e->user->must_change_secret,
                // A doctor is on the hospital's lists whether or not they sign in.
                'signs_in' => $e->user->signsIn(), 'is_doctor' => $e->user->role === Role::Doctor && (bool) $e->user->is_active, 'qualification' => $e->user->signature_text, 'opd_fee' => $e->user->opd_fee, 'book_online' => (bool) $e->user->book_online] : null,
        ];
    }
}
