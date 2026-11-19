<?php

namespace App\Workforce\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Workforce\Models\Attendance;
use App\Workforce\Models\AttendanceCorrection;
use App\Workforce\Models\AttendanceImport;
use App\Workforce\Models\AttendanceImportRow;
use App\Workforce\Models\Employee;
use App\Workforce\Support\Access;
use App\Workforce\Support\AttendanceRules;
use App\Workforce\Support\Audit;
use App\Workforce\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attendance: who came in and went out, a day and a month at a glance, corrections that are always explained, and
 * sheets brought in from a machine or a spreadsheet after being looked over.
 */
class AttendanceController extends Controller
{
    use Helpers;

    /** One day for everyone the person may see: each member of staff with what the day came to and their spells of work. */
    public function day(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:attendance:view');
        $filters = $request->validate(['date' => ['nullable', 'date'], 'department_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100']]);
        $date = CarbonImmutable::parse($filters['date'] ?? now())->toDateString();
        $staff = $this->staff($user, $date, $date, $filters);
        $days = AttendanceRules::days($staff, $date, $date);
        $records = Attendance::query()->whereIn('employee_id', $staff->pluck('id'))->whereDate('work_date', $date)->orderBy('check_in')->get()->groupBy('employee_id');
        // A check-out still missing from an earlier day is shown wherever the person is looked at, until it is put right.
        $stale = Attendance::query()->whereIn('employee_id', $staff->pluck('id'))->where('state', 'open')->whereDate('work_date', '<', $date)->get()->groupBy('employee_id');

        $rows = $staff->map(fn (Employee $e) => $this->brief($e) + [
            'shift' => $e->shift?->name,
            'day' => $days[$e->id][$date],
            'records' => ($records->get($e->id) ?? collect())->map(fn (Attendance $a) => $this->record($a))->values(),
            'open_earlier' => ($stale->get($e->id) ?? collect())->map(fn (Attendance $a) => $this->record($a))->values(),
        ])->filter(fn ($row) => $row['day']['status'] !== 'not_employed')->values();

        return response()->json(['date' => $date, 'rows' => $rows, 'counts' => $this->counts($rows->pluck('day')->all())]);
    }

    /** A month as a grid: one line a person, one box a day, and what the month adds up to. */
    public function month(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:attendance:view');
        $filters = $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'department_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100'], 'employee_id' => ['nullable', 'integer']]);
        [$from, $to, $month] = $this->span($filters['month'] ?? null);
        $staff = $this->staff($user, $from, $to, $filters)->when($filters['employee_id'] ?? null, fn ($list, $id) => $list->where('id', (int) $id)->values());
        $days = AttendanceRules::days($staff, $from, $to);

        return response()->json([
            'month' => $month, 'from' => $from, 'to' => $to,
            'rows' => $staff->map(fn (Employee $e) => $this->brief($e) + [
                'days' => array_map(fn ($d) => array_intersect_key($d, array_flip(['date', 'status', 'present', 'leave', 'absent', 'worked_minutes', 'late_minutes', 'open', 'leave_type', 'holiday', 'note'])), array_values($days[$e->id])),
                'totals' => AttendanceRules::totals($days[$e->id]),
            ])->values(),
        ]);
    }

    /** The month's totals for each person as a file. */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:attendance:export');
        $filters = $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'department_id' => ['nullable', 'integer']]);
        [$from, $to, $month] = $this->span($filters['month'] ?? null);
        $staff = $this->staff($user, $from, $to, $filters);
        $days = AttendanceRules::days($staff, $from, $to);
        Audit::record('attendance.exported', null, null, ['month' => $month, 'rows' => $staff->count()]);

        return $this->csv("attendance-{$month}.csv", ['Employee no.', 'Name', 'Department', 'Present days', 'Leave days', 'Paid leave days', 'Absent days', 'Holidays', 'Weekly offs', 'Late days', 'Missing check-outs', 'Hours worked', 'Overtime hours'],
            $staff->map(function (Employee $e) use ($days) {
                $t = AttendanceRules::totals($days[$e->id]);

                return [$e->employee_no, $e->name, $e->department?->name, $t['present'], $t['leave'], $t['paid_leave'], $t['absent'], $t['holidays'], $t['weekly_offs'], $t['late_days'], $t['open'], round($t['worked_minutes'] / 60, 2), round($t['overtime_minutes'] / 60, 2)];
            }));
    }

    /** Marks someone as in, now. */
    public function checkIn(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'integer'], 'note' => ['nullable', 'string', 'max:500']]);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        Access::requireFor($request->user(), 'wf:attendance:create', $employee);

        return response()->json($this->record($this->open($employee, now()->format('Y-m-d H:i:s'), 'manual', $data['note'] ?? null, $request->user())), 201);
    }

    /** Marks an open spell as ended, now. */
    public function checkOut(Request $request, int $attendance): JsonResponse
    {
        $record = Attendance::query()->findOrFail($attendance);
        Access::requireFor($request->user(), 'wf:attendance:create', $record->employee);

        return response()->json($this->record($this->close($record, now()->format('Y-m-d H:i:s'), $request->user())));
    }

    /** A spell of work entered by hand, with its times. For a day other than today the reason must be given. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->times($request, ['employee_id' => ['required', 'integer']]);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        Access::requireFor($request->user(), 'wf:attendance:create', $employee);
        if ($data['work_date'] !== now()->toDateString() && blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'Say why attendance is being entered for a day other than today.']);
        }
        $record = DB::transaction(function () use ($data, $employee, $request) {
            $this->refuseClash($employee, $data['check_in'], null);
            $record = new Attendance([
                'employee_id' => $employee->id, 'work_date' => $data['work_date'], 'shift_id' => $employee->shift_id, 'check_in' => $data['check_in'], 'check_out' => $data['check_out'] ?? null,
                'day_status' => $data['day_status'] ?? null, 'origin' => 'manual', 'note' => $data['reason'] ?? null, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $record->measured()->save();
            Audit::record('attendance.entered', $record, null, $record->asRecorded(), $data['reason'] ?? null, $employee->id);

            return $record;
        });

        return response()->json($this->record($record), 201);
    }

    /** Changes a saved spell directly. Always with the reason, and with what it was and what it became kept. */
    public function update(Request $request, int $attendance): JsonResponse
    {
        $record = Attendance::query()->findOrFail($attendance);
        Access::requireFor($request->user(), 'wf:attendance:edit', $record->employee);
        $data = $this->times($request, ['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Say why this attendance is being changed.']);
        $record = DB::transaction(function () use ($record, $data, $request) {
            $after = $this->apply($record, $data, $request->user());
            AttendanceCorrection::create([
                'employee_id' => $record->employee_id, 'attendance_id' => $record->id, 'work_date' => $data['work_date'], 'reason' => $data['reason'], 'before' => $after['before'], 'after' => $after['after'],
                'status' => 'applied', 'requested_by' => $request->user()->id, 'decided_by' => $request->user()->id, 'decided_at' => now(),
            ]);

            return $record->fresh();
        });

        return response()->json($this->record($record));
    }

    /** Corrections asked for, newest first, within what the person may see. */
    public function corrections(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:attendance:view');
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled', 'applied', 'all'])]])['status'] ?? 'pending';
        $rows = Access::narrow(AttendanceCorrection::query(), $user, 'wf:attendance:view')->when($status !== 'all', fn ($q) => $q->where('status', $status))->latest('id')->limit(200)->get();
        $staff = Employee::query()->whereIn('id', $rows->pluck('employee_id'))->with('department:id,name')->get()->keyBy('id');
        $names = User::query()->whereIn('id', $rows->pluck('requested_by')->merge($rows->pluck('decided_by'))->filter())->pluck('name', 'id');

        return response()->json($rows->map(fn (AttendanceCorrection $c) => $this->correction($c, $staff, $names) + [
            'can_decide' => $c->status === 'pending' && $user->allows('wf:attendance:approve') && Access::reaches($user, 'wf:attendance:approve', $staff[$c->employee_id]) && ! $this->ownRequest($user, $c),
        ]));
    }

    /** Asks for a spell to be changed, or a missing one added. It changes nothing until someone approves it. */
    public function requestCorrection(Request $request): JsonResponse
    {
        $data = $this->times($request, ['employee_id' => ['required', 'integer'], 'attendance_id' => ['nullable', 'integer'], 'reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Say what is wrong and why.']);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        Access::requireFor($request->user(), 'wf:attendance:create', $employee);

        return response()->json($this->ask($employee, $data, $request->user()), 201);
    }

    /** Approves a correction, which applies it, or refuses it. Nobody decides a correction to their own attendance. */
    public function decideCorrection(Request $request, int $correction): JsonResponse
    {
        $user = $request->user();
        $correction = AttendanceCorrection::query()->findOrFail($correction);
        $employee = Employee::query()->findOrFail($correction->employee_id);
        Access::requireFor($user, 'wf:attendance:approve', $employee);
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'required_if:approve,false', 'string', 'max:500']], ['note.required_if' => 'Say why it is refused.']);
        abort_if($this->ownRequest($user, $correction), 403, 'A correction to your own attendance must be decided by someone else.');

        DB::transaction(function () use ($correction, $employee, $data, $user) {
            $locked = AttendanceCorrection::query()->lockForUpdate()->findOrFail($correction->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['approve' => 'This request has already been '.$locked->status.'.']);
            }
            if ($data['approve']) {
                $after = $locked->after;
                if ($locked->attendance_id && ($record = Attendance::query()->find($locked->attendance_id))) {
                    $this->apply($record, $after + ['reason' => $locked->reason], $user);
                } else {
                    $this->refuseClash($employee, $after['check_in'], null);
                    $record = new Attendance(['employee_id' => $employee->id, 'shift_id' => $employee->shift_id, 'origin' => 'manual', 'created_by' => $user->id, 'updated_by' => $user->id, 'note' => $locked->reason] + array_intersect_key($after, array_flip(['work_date', 'check_in', 'check_out', 'day_status'])));
                    $record->measured()->save();
                    Audit::record('attendance.entered', $record, null, $record->asRecorded(), $locked->reason, $employee->id);
                    $locked->attendance_id = $record->id;
                }
            }
            $locked->fill(['status' => $data['approve'] ? 'approved' : 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null])->save();
            Audit::record($data['approve'] ? 'attendance.correction.approved' : 'attendance.correction.refused', $locked, null, ['after' => $locked->after], $data['note'] ?? $locked->reason, $employee->id);
        });

        return response()->json(['status' => $correction->fresh()->status]);
    }

    /**
     * Looks over a sheet of attendance without saving any attendance: each line is matched to a member of staff
     * and checked, and comes back marked ready, already here, unmatched, incomplete or invalid.
     */
    public function previewImport(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(Access::require($user, 'wf:attendance:create') === Permissions::ALL, 403, 'You are not allowed to bring in attendance sheets.');
        $data = $request->validate([
            'file_name' => ['nullable', 'string', 'max:191'], 'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*.employee_no' => ['nullable', 'string', 'max:64'], 'rows.*.name' => ['nullable', 'string', 'max:191'], 'rows.*.date' => ['nullable', 'string', 'max:20'],
            'rows.*.check_in' => ['nullable', 'string', 'max:25'], 'rows.*.check_out' => ['nullable', 'string', 'max:25'],
        ], ['rows.max' => 'A sheet can have at most 5,000 lines. Split it and bring it in in parts.']);

        $staff = Employee::query()->get(['id', 'employee_no', 'name'])->keyBy(fn (Employee $e) => mb_strtolower($e->employee_no));
        $import = DB::transaction(function () use ($data, $staff, $user) {
            $import = AttendanceImport::create(['file_name' => $data['file_name'] ?? null, 'sha256' => hash('sha256', json_encode($data['rows'])), 'status' => 'preview', 'created_by' => $user->id]);
            $seen = [];
            $alreadyHere = 0;
            foreach ($data['rows'] as $line) {
                $row = $this->judge($line, $staff, $seen);
                $seen[$row['source_key']] = true;
                // A line whose key is already kept, from this sheet or an earlier one, is counted and not kept twice.
                if ($row['state'] === 'duplicate' && AttendanceImportRow::query()->where('source_key', $row['source_key'])->exists()) {
                    $alreadyHere++;

                    continue;
                }
                AttendanceImportRow::create($row + ['import_id' => $import->id]);
            }
            $import->update(['summary' => $this->summary($import) + ['already_here' => $alreadyHere]]);

            return $import;
        });

        return response()->json($this->import($import), 201);
    }

    public function showImport(Request $request, int $import): JsonResponse
    {
        Access::require($request->user(), 'wf:attendance:view');

        return response()->json($this->import(AttendanceImport::query()->findOrFail($import)));
    }

    /** Saves the lines that were ready as attendance. Lines that were not ready stay as they are, for a person to deal with. */
    public function applyImport(Request $request, int $import): JsonResponse
    {
        $user = $request->user();
        abort_unless(Access::require($user, 'wf:attendance:create') === Permissions::ALL, 403, 'You are not allowed to bring in attendance sheets.');
        $import = DB::transaction(function () use ($import, $user) {
            $import = AttendanceImport::query()->lockForUpdate()->findOrFail($import);
            if ($import->status !== 'preview') {
                throw ValidationException::withMessages(['import' => 'This sheet has already been '.$import->status.'.']);
            }
            $staff = Employee::query()->whereIn('id', $import->rows()->where('state', 'ready')->pluck('employee_id'))->get()->keyBy('id');
            foreach ($import->rows()->where('state', 'ready')->orderBy('id')->get() as $row) {
                // Checked again at the moment of saving: someone may have entered the same spell by hand meanwhile.
                if (Attendance::query()->where('employee_id', $row->employee_id)->where('check_in', $row->check_in)->exists()) {
                    $row->update(['state' => 'duplicate', 'note' => 'Already entered.']);

                    continue;
                }
                $record = new Attendance(['employee_id' => $row->employee_id, 'work_date' => $row->work_date, 'shift_id' => $staff[$row->employee_id]->shift_id, 'check_in' => $row->check_in, 'check_out' => $row->check_out, 'origin' => 'import', 'created_by' => $user->id, 'updated_by' => $user->id]);
                $record->measured()->save();
                $row->update(['state' => 'applied', 'attendance_id' => $record->id]);
            }
            $import->update(['status' => 'applied', 'applied_at' => now(), 'summary' => $this->summary($import) + ['already_here' => $import->summary['already_here'] ?? 0]]);
            Audit::record('attendance.sheet.applied', $import, null, $import->summary);

            return $import;
        });

        return response()->json($this->import($import));
    }

    // ---- What the endpoints above share ----

    /** Opens a spell for someone. Refused while they already have one open, as it always was. */
    public function open(Employee $employee, string $at, string $origin, ?string $note, User $by): Attendance
    {
        if ($employee->status === 'inactive') {
            throw ValidationException::withMessages(['employee_id' => "{$employee->name} is no longer on the staff."]);
        }

        return DB::transaction(function () use ($employee, $at, $origin, $note, $by) {
            if (Attendance::query()->where('employee_id', $employee->id)->where('state', 'open')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['employee_id' => "{$employee->name} is already checked in. Check them out first, or correct the earlier entry."]);
            }
            $record = new Attendance(['employee_id' => $employee->id, 'work_date' => substr($at, 0, 10), 'shift_id' => $employee->shift_id, 'check_in' => $at, 'origin' => $origin, 'note' => $note, 'created_by' => $by->id, 'updated_by' => $by->id]);
            $record->measured()->save();
            Audit::record('attendance.checked_in', $record, null, ['check_in' => $at], null, $employee->id);

            return $record;
        });
    }

    public function close(Attendance $record, string $at, User $by): Attendance
    {
        if ($record->state !== 'open') {
            throw ValidationException::withMessages(['attendance' => 'This entry already has a check-out.']);
        }
        if ($at < $record->check_in->format('Y-m-d H:i:s')) {
            throw ValidationException::withMessages(['attendance' => 'The check-out cannot be before the check-in.']);
        }
        $record->fill(['check_out' => $at, 'updated_by' => $by->id])->measured()->save();
        Audit::record('attendance.checked_out', $record, null, ['check_out' => $at], null, $record->employee_id);

        return $record;
    }

    /** Writes a correction request for someone's attendance. Used by staff for others and by a person for themself. */
    public function ask(Employee $employee, array $data, User $by): array
    {
        $record = filled($data['attendance_id'] ?? null) ? Attendance::query()->where('employee_id', $employee->id)->findOrFail($data['attendance_id']) : null;
        $correction = AttendanceCorrection::create([
            'employee_id' => $employee->id, 'attendance_id' => $record?->id, 'work_date' => $data['work_date'], 'reason' => $data['reason'], 'before' => $record?->asRecorded(),
            'after' => array_intersect_key($data, array_flip(['work_date', 'check_in', 'check_out', 'day_status'])), 'status' => 'pending', 'requested_by' => $by->id,
        ]);
        Audit::record('attendance.correction.asked', $correction, $correction->before, $correction->after, $data['reason'], $employee->id);

        return ['id' => $correction->id, 'status' => 'pending'];
    }

    /**
     * Validates the times of a spell: a real day, a check-in on that day or the next (for a night shift), a
     * check-out after it and within two days, and nothing in the future.
     *
     * @return array<string, mixed>
     */
    public function times(Request $request, array $more = [], array $messages = []): array
    {
        $data = $request->validate($more + [
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'check_in' => ['required', 'date'], 'check_out' => ['nullable', 'date', 'after_or_equal:check_in'],
            'day_status' => ['nullable', Rule::in(['present', 'half_day', 'absent'])], 'reason' => ['nullable', 'string', 'max:500'],
        ], $messages + ['check_out.after_or_equal' => 'The check-out cannot be before the check-in.', 'work_date.before_or_equal' => 'Attendance cannot be entered for a day that has not come yet.']);
        $data['check_in'] = CarbonImmutable::parse($data['check_in'])->format('Y-m-d H:i:s');
        $data['check_out'] = filled($data['check_out'] ?? null) ? CarbonImmutable::parse($data['check_out'])->format('Y-m-d H:i:s') : null;
        $day = CarbonImmutable::parse($data['work_date']);
        if (CarbonImmutable::parse($data['check_in'])->lt($day) || CarbonImmutable::parse($data['check_in'])->gte($day->addDays(2))) {
            throw ValidationException::withMessages(['check_in' => 'The check-in must be on the day the shift started.']);
        }
        if ($data['check_in'] > now()->format('Y-m-d H:i:s') || ($data['check_out'] && $data['check_out'] > now()->format('Y-m-d H:i:s'))) {
            throw ValidationException::withMessages(['check_in' => 'A time that has not come yet cannot be entered.']);
        }
        if ($data['check_out'] && CarbonImmutable::parse($data['check_in'])->diffInHours(CarbonImmutable::parse($data['check_out'])) > 36) {
            throw ValidationException::withMessages(['check_out' => 'That is more than 36 hours after the check-in. Check the dates.']);
        }

        return $data;
    }

    /**
     * Applies new times to a spell and writes down the change.
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function apply(Attendance $record, array $data, User $by): array
    {
        $before = $record->asRecorded();
        $this->refuseClash($record->employee, $data['check_in'], $record->id);
        $record->fill(array_intersect_key($data, array_flip(['work_date', 'check_in', 'check_out', 'day_status'])) + ['updated_by' => $by->id])->measured()->save();
        [$was, $now] = Audit::difference($before, $record->asRecorded());
        Audit::record('attendance.corrected', $record, $was, $now, $data['reason'] ?? null, $record->employee_id);

        return ['before' => $before, 'after' => $record->asRecorded()];
    }

    private function refuseClash(Employee $employee, string $checkIn, ?int $except): void
    {
        if (Attendance::query()->where('employee_id', $employee->id)->where('check_in', $checkIn)->when($except, fn ($q) => $q->where('id', '!=', $except))->exists()) {
            throw ValidationException::withMessages(['check_in' => "{$employee->name} already has an entry starting at that time."]);
        }
    }

    private function ownRequest(User $user, AttendanceCorrection $correction): bool
    {
        return $user->role->value !== 'admin' && Employee::query()->where('user_id', $user->id)->whereKey($correction->employee_id)->exists();
    }

    /**
     * One line of a sheet, matched and checked.
     *
     * @param  array<string, string|null>  $line
     * @param  array<string, bool>  $seen
     * @return array<string, mixed>
     */
    private function judge(array $line, $staff, array $seen): array
    {
        $ref = trim((string) ($line['employee_no'] ?? ''));
        $date = $this->moment($line['date'] ?? null, 'Y-m-d');
        $in = $this->moment($line['check_in'] ?? null, 'Y-m-d H:i:s', $date);
        $out = $this->moment($line['check_out'] ?? null, 'Y-m-d H:i:s', $date);
        // A night shift's check-out given as a clock time alone belongs to the next morning.
        if ($in && $out && $out < $in && strlen(trim((string) $line['check_out'])) <= 8) {
            $out = CarbonImmutable::parse($out)->addDay()->format('Y-m-d H:i:s');
        }
        $employee = $staff->get(mb_strtolower($ref));
        $payload = [$ref, $date, $in, $out];
        $row = [
            'source_key' => hash('sha256', json_encode([mb_strtolower($ref), $date, $in, $out])), 'payload_hash' => hash('sha256', json_encode($payload + [4 => $line['name'] ?? null])),
            'employee_ref' => $ref ?: null, 'staff_name' => filled($line['name'] ?? null) ? trim((string) $line['name']) : $employee?->name, 'employee_id' => $employee?->id,
            'work_date' => $date, 'check_in' => $in, 'check_out' => $out, 'note' => null,
        ];
        [$row['state'], $row['note']] = match (true) {
            isset($seen[$row['source_key']]) => ['duplicate', 'The same line appears twice in this sheet.'],
            AttendanceImportRow::query()->where('source_key', $row['source_key'])->exists() => ['duplicate', 'Brought in before.'],
            ! $date => ['invalid', 'The date could not be read.'],
            ! $employee => ['unmatched', $ref === '' ? 'No employee number.' : "No member of staff has the number {$ref}."],
            ! $in && ! $out => ['invalid', 'Neither a check-in nor a check-out could be read.'],
            ! $in => ['missing_in', 'No check-in.'],
            $in > now()->format('Y-m-d H:i:s') => ['invalid', 'The time has not come yet.'],
            ! $out => ['missing_out', 'No check-out.'],
            $out < $in => ['invalid', 'The check-out is before the check-in.'],
            Attendance::query()->where('employee_id', $employee->id)->where('check_in', $in)->exists() => ['duplicate', 'Already entered.'],
            default => ['ready', null],
        };

        return $row;
    }

    /** A date or time as written in a sheet, in one fixed form, or null if it cannot be read. */
    private function moment(?string $value, string $format, ?string $date = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        // A clock time on its own is a time on the line's date.
        if ($date && preg_match('/^\d{1,2}:\d{2}(:\d{2})?(\s*[AaPp][Mm])?$/', $value)) {
            $value = $date.' '.$value;
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d h:i A', 'Y-m-d h:i:s A', 'd-m-Y H:i', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd/m/Y H:i:s', 'Y-m-d', 'd-m-Y', 'd/m/Y'] as $known) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$known, $value);
                if ($parsed && $parsed->format($known) === $value || ($parsed && strtoupper($parsed->format($known)) === strtoupper($value))) {
                    return $parsed->format($format);
                }
            } catch (\Throwable) {
                // not this form; try the next
            }
        }

        return null;
    }

    /** @return array<string, int|float> */
    private function summary(AttendanceImport $import): array
    {
        $rows = $import->rows()->get(['state', 'check_in', 'check_out']);
        $hours = $rows->whereIn('state', ['ready', 'applied'])->sum(fn ($r) => $r->check_in && $r->check_out ? $r->check_in->diffInMinutes($r->check_out) : 0);

        return ['lines' => $rows->count(), 'hours' => round($hours / 60, 2)] + $rows->countBy('state')->all();
    }

    /** @return array<string, mixed> */
    private function import(AttendanceImport $import): array
    {
        return [
            'id' => $import->id, 'file_name' => $import->file_name, 'status' => $import->status, 'summary' => $import->summary, 'applied_at' => $import->applied_at?->format('Y-m-d H:i'),
            'rows' => $import->rows()->orderBy('id')->limit(1000)->get()->map(fn (AttendanceImportRow $r) => [
                'id' => $r->id, 'employee_ref' => $r->employee_ref, 'staff_name' => $r->staff_name, 'work_date' => $r->work_date?->format('Y-m-d'),
                'check_in' => $r->check_in?->format('Y-m-d H:i'), 'check_out' => $r->check_out?->format('Y-m-d H:i'), 'state' => $r->state, 'note' => $r->note,
            ]),
        ];
    }

    /** The staff a person may see for attendance in a stretch: those on the staff during it, by name. */
    private function staff(User $user, string $from, string $to, array $filters)
    {
        $term = trim((string) ($filters['q'] ?? ''));

        return Access::employees($user, 'wf:attendance:view')->with(['department:id,name', 'shift:id,name'])
            ->where(fn ($q) => $q->whereNull('joined_on')->orWhereDate('joined_on', '<=', $to))
            ->where(fn ($q) => $q->where('status', '!=', 'inactive')->orWhereDate('separated_on', '>=', $from))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('employee_no', 'like', "%{$term}%")))
            ->orderBy('name')->limit(1000)->get();
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function span(?string $month): array
    {
        $start = CarbonImmutable::parse(($month ?? now()->format('Y-m')).'-01');

        return [$start->toDateString(), $start->endOfMonth()->toDateString(), $start->format('Y-m')];
    }

    /** @param  list<array<string, mixed>>  $days */
    private function counts(array $days): array
    {
        $is = fn (array $statuses) => count(array_filter($days, fn ($d) => in_array($d['status'], $statuses, true)));

        return [
            'staff' => count($days), 'in' => $is(['present', 'half_day', 'half_leave', 'open']), 'not_in' => $is(['not_in', 'absent']), 'on_leave' => $is(['on_leave']),
            'off' => $is(['weekly_off', 'holiday']), 'late' => count(array_filter($days, fn ($d) => $d['late_minutes'] > 0)), 'still_in' => count(array_filter($days, fn ($d) => $d['open'])),
        ];
    }

    /** @return array<string, mixed> */
    public function record(Attendance $a): array
    {
        return [
            'id' => $a->id, 'employee_id' => $a->employee_id, 'work_date' => $a->work_date->format('Y-m-d'), 'check_in' => $a->check_in?->format('Y-m-d H:i'), 'check_out' => $a->check_out?->format('Y-m-d H:i'),
            'state' => $a->state, 'day_status' => $a->day_status, 'worked_minutes' => $a->worked_minutes, 'late_minutes' => $a->late_minutes, 'early_minutes' => $a->early_minutes,
            'overtime_minutes' => $a->overtime_minutes, 'origin' => $a->origin, 'note' => $a->note,
        ];
    }

    /** @return array<string, mixed> */
    public function correction(AttendanceCorrection $c, $staff, $names): array
    {
        return [
            'id' => $c->id, 'employee' => isset($staff[$c->employee_id]) ? $this->brief($staff[$c->employee_id]) : null, 'work_date' => $c->work_date->format('Y-m-d'), 'reason' => $c->reason,
            'before' => $c->before, 'after' => $c->after, 'status' => $c->status, 'asked_by' => $names[$c->requested_by] ?? null, 'asked_at' => $c->created_at?->format('Y-m-d H:i'),
            'decided_by' => $names[$c->decided_by] ?? null, 'decided_at' => $c->decided_at?->format('Y-m-d H:i'), 'decision_note' => $c->decision_note,
        ];
    }
}
