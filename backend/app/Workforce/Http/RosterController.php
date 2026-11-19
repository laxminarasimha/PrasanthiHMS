<?php

namespace App\Workforce\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Workforce\Models\Attendance;
use App\Workforce\Models\Employee;
use App\Workforce\Models\Payslip;
use App\Workforce\Models\RosterDay;
use App\Workforce\Models\Shift;
use App\Workforce\Support\Access;
use App\Workforce\Support\Audit;
use App\Workforce\Support\Roster;
use App\Workforce\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The duty roster: who is on which duty (a morning, a long day, a night) on which day, and whose day off it is.
 * Seen by those who see attendance, within their reach; set by those who enter attendance, within theirs.
 */
class RosterController extends Controller
{
    use Helpers;

    /** Up to five weeks at a time, so a month's roster can be seen and printed whole. */
    private const MOST_DAYS = 35;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:attendance:view');
        $filters = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'days' => ['nullable', 'integer', 'between:1,'.self::MOST_DAYS], 'department_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100']]);
        $from = CarbonImmutable::parse($filters['from'] ?? now()->startOfWeek(CarbonImmutable::MONDAY)->toDateString());
        $to = $from->addDays((int) ($filters['days'] ?? 7) - 1);
        $staff = $this->staff($user, $from->toDateString(), $to->toDateString(), $filters);
        $lines = Roster::lines($staff->pluck('id'), $from->toDateString(), $to->toDateString());
        $usualOff = Settings::get('attendance')['default_weekly_off'];
        $dates = collect(range(0, $from->diffInDays($to)))->map(fn ($i) => $from->addDays((int) $i)->toDateString());
        $shifts = Shift::query()->orderBy('starts_at')->get();

        return response()->json([
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'dates' => $dates,
            'shifts' => $shifts->map(fn (Shift $s) => ['id' => $s->id, 'name' => $s->name, 'starts_at' => substr((string) $s->starts_at, 0, 5), 'ends_at' => substr((string) $s->ends_at, 0, 5), 'night' => $s->crossesMidnight(), 'is_active' => $s->is_active]),
            'can_edit' => $user->allows('wf:attendance:create'),
            'rows' => $staff->map(fn (Employee $e) => $this->brief($e) + [
                'usual_shift_id' => $e->shift_id,
                'can_edit' => Access::reaches($user, 'wf:attendance:create', $e),
                'days' => $dates->mapWithKeys(function (string $date) use ($e, $lines, $usualOff) {
                    $duty = Roster::duty($e, $date, $lines->get($e->id.'|'.$date), $usualOff);
                    $employed = (! $e->joined_on || $date >= $e->joined_on->format('Y-m-d')) && (! $e->separated_on || $date <= $e->separated_on->format('Y-m-d'));

                    return [$date => $employed ? $duty : null];
                }),
            ])->values(),
            // How many are on each duty each day, so a short-handed night shows at a glance.
            'cover' => $dates->mapWithKeys(fn (string $date) => [$date => $staff->map(fn (Employee $e) => Roster::duty($e, $date, $lines->get($e->id.'|'.$date), $usualOff))
                ->reject(fn ($d) => $d['off'] || ! $d['shift_id'])->countBy('shift_id')]),
        ]);
    }

    /**
     * Sets duties. Each line names a person, a day (or a run of days) and what it is: a shift, "off", or "usual" to
     * take the roster line away so their usual shift and weekly off apply again.
     */
    public function save(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:attendance:create');
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.employee_id' => ['required', 'integer'], 'lines.*.from' => ['required', 'date_format:Y-m-d'],
            'lines.*.to' => ['nullable', 'date_format:Y-m-d'], 'lines.*.duty' => ['required', 'string', 'max:20'],
        ]);
        $shifts = Shift::query()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $written = 0;
        DB::transaction(function () use ($data, $user, $shifts, &$written) {
            foreach ($data['lines'] as $i => $line) {
                $employee = Employee::query()->find($line['employee_id']);
                abort_unless($employee && Access::reaches($user, 'wf:attendance:create', $employee), 403, 'You are not allowed to set duties for this member of staff.');
                if (! in_array($line['duty'], ['usual', 'off', ...$shifts], true)) {
                    throw ValidationException::withMessages(["lines.{$i}.duty" => 'Choose a shift, a day off, or the usual duty.']);
                }
                $from = CarbonImmutable::parse($line['from']);
                $to = CarbonImmutable::parse($line['to'] ?? $line['from']);
                if ($to->lt($from) || $from->diffInDays($to) > 92) {
                    throw ValidationException::withMessages(["lines.{$i}.to" => 'Choose a stretch of up to three months, ending on or after it starts.']);
                }
                $this->refuseFinalised($employee, $from, $to);
                for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                    $written += $this->put($employee, $day->toDateString(), $line['duty'], $user);
                }
            }
        });
        Audit::record('roster.changed', null, null, ['lines' => count($data['lines']), 'days' => $written]);

        return response()->json(['days_changed' => $written]);
    }

    /** Copies one stretch's roster onto the same days of the stretch after it: last week onto this week, say. */
    public function copy(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:attendance:create');
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d'], 'days' => ['required', 'integer', 'between:1,'.self::MOST_DAYS], 'department_id' => ['nullable', 'integer']]);
        $source = CarbonImmutable::parse($data['from']);
        $target = CarbonImmutable::parse($data['to']);
        $span = (int) $data['days'];
        $staff = $this->staff($user, $target->toDateString(), $target->addDays($span - 1)->toDateString(), $data)->filter(fn (Employee $e) => Access::reaches($user, 'wf:attendance:create', $e));
        $lines = Roster::lines($staff->pluck('id'), $source->toDateString(), $source->addDays($span - 1)->toDateString());
        $written = 0;
        DB::transaction(function () use ($staff, $lines, $source, $target, $span, $user, &$written) {
            foreach ($staff as $employee) {
                $this->refuseFinalised($employee, $target, $target->addDays($span - 1));
                for ($i = 0; $i < $span; $i++) {
                    $line = $lines->get($employee->id.'|'.$source->addDays($i)->toDateString());
                    $duty = $line ? ($line->is_off ? 'off' : (string) $line->shift_id) : 'usual';
                    $written += $this->put($employee, $target->addDays($i)->toDateString(), $duty, $user);
                }
            }
        });
        Audit::record('roster.copied', null, null, ['from' => $source->toDateString(), 'to' => $target->toDateString(), 'days' => $span, 'staff' => $staff->count()]);

        return response()->json(['days_changed' => $written]);
    }

    /**
     * Writes one day. Attendance already recorded for that day is measured again against the new duty, so lateness
     * and overtime follow the roster. Returns 1 when something changed.
     */
    private function put(Employee $employee, string $date, string $duty, User $by): int
    {
        $line = RosterDay::query()->where('employee_id', $employee->id)->whereDate('work_date', $date)->first();
        $was = $line ? ($line->is_off ? 'off' : (string) $line->shift_id) : 'usual';
        if ($was === $duty) {
            return 0;
        }
        if ($duty === 'usual') {
            $line?->delete();
        } else {
            $values = ['shift_id' => $duty === 'off' ? null : (int) $duty, 'is_off' => $duty === 'off', 'updated_by' => $by->id];
            $line ? $line->update($values) : RosterDay::create($values + ['employee_id' => $employee->id, 'work_date' => $date]);
        }
        $shiftId = Roster::shiftOn($employee, $date);
        foreach (Attendance::query()->where('employee_id', $employee->id)->whereDate('work_date', $date)->get() as $record) {
            if ($record->shift_id !== $shiftId) {
                $record->fill(['shift_id' => $shiftId, 'updated_by' => $by->id])->measured()->save();
            }
        }

        return 1;
    }

    /** A month whose payroll is finalised for this person keeps the duties it was worked out with. */
    private function refuseFinalised(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $locked = Payslip::query()->where('employee_id', $employee->id)->whereBetween('live_period', [$from->format('Y-m'), $to->format('Y-m')])
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['finalised', 'paid']))->value('period');
        if ($locked) {
            throw ValidationException::withMessages(['lines' => "Payroll for {$locked} is finalised for {$employee->name}, so the duties of that month stay as they were."]);
        }
    }

    /** @return Collection<int, Employee> */
    private function staff(User $user, string $from, string $to, array $filters): Collection
    {
        $term = trim((string) ($filters['q'] ?? ''));

        return Access::employees($user, 'wf:attendance:view')->with('department:id,name')
            ->where(fn ($q) => $q->whereNull('joined_on')->orWhereDate('joined_on', '<=', $to))
            ->where(fn ($q) => $q->where('status', '!=', 'inactive')->orWhereDate('separated_on', '>=', $from))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('employee_no', 'like', "%{$term}%")))
            ->orderBy('name')->limit(1000)->get();
    }
}
