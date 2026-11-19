<?php

namespace App\Workforce\Http;

use App\Http\Controllers\Controller;
use App\Workforce\Models\Holiday;
use App\Workforce\Models\HolidayCalendar;
use App\Workforce\Models\LeaveType;
use App\Workforce\Models\Shift;
use App\Workforce\Support\Access;
use App\Workforce\Support\Audit;
use App\Workforce\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The lists and rules the module runs on: shifts, holiday calendars, kinds of leave, and the hospital's own choices. */
class SetupController extends Controller
{
    use Helpers;

    public function show(Request $request): JsonResponse
    {
        Access::require($request->user(), 'wf:settings:admin');

        return response()->json([
            'shifts' => Shift::query()->orderBy('name')->get(),
            'calendars' => HolidayCalendar::query()->with('holidays')->orderBy('name')->get(),
            'leave_types' => LeaveType::query()->orderBy('name')->get(),
            'settings' => collect(array_keys(Settings::DEFAULTS))->mapWithKeys(fn ($group) => [$group => Settings::get($group)]),
        ]);
    }

    public function saveShift(Request $request, ?int $shift = null): JsonResponse
    {
        Access::require($request->user(), 'wf:settings:admin');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'starts_at' => ['required', 'date_format:H:i'], 'ends_at' => ['required', 'date_format:H:i', 'different:starts_at'],
            'grace_minutes' => ['nullable', 'integer', 'between:0,240'], 'early_leave_minutes' => ['nullable', 'integer', 'between:0,240'], 'break_minutes' => ['nullable', 'integer', 'between:0,240'],
            'half_day_minutes' => ['nullable', 'integer', 'between:30,1440'], 'full_day_minutes' => ['nullable', 'integer', 'between:30,1440', 'gte:half_day_minutes'],
            'overtime_after_minutes' => ['nullable', 'integer', 'between:30,1440'], 'is_active' => ['sometimes', 'boolean'],
        ], ['ends_at.different' => 'A shift cannot start and end at the same time.', 'full_day_minutes.gte' => 'A full day cannot be shorter than a half day.']);
        foreach (['grace_minutes', 'early_leave_minutes', 'break_minutes'] as $zero) {
            $data[$zero] = (int) ($data[$zero] ?? 0);
        }

        return $this->save(Shift::class, $shift, $data, 'shift', 'name');
    }

    public function saveCalendar(Request $request, ?int $calendar = null): JsonResponse
    {
        Access::require($request->user(), 'wf:settings:admin');
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'is_default' => ['sometimes', 'boolean']]);
        if ($data['is_default'] ?? false) {
            HolidayCalendar::query()->when($calendar, fn ($q) => $q->where('id', '!=', $calendar))->update(['is_default' => false]);
        }

        return $this->save(HolidayCalendar::class, $calendar, $data, 'holiday_calendar', 'name');
    }

    public function saveHoliday(Request $request, int $calendar, ?int $holiday = null): JsonResponse
    {
        Access::require($request->user(), 'wf:settings:admin');
        $calendar = HolidayCalendar::query()->findOrFail($calendar);
        $data = $request->validate(['on' => ['required', 'date'], 'name' => ['required', 'string', 'max:120'], 'is_optional' => ['sometimes', 'boolean']]);
        $row = $holiday ? Holiday::query()->where('calendar_id', $calendar->id)->findOrFail($holiday) : new Holiday(['calendar_id' => $calendar->id]);
        if (Holiday::query()->where('calendar_id', $calendar->id)->whereDate('on', $data['on'])->when($holiday, fn ($q) => $q->where('id', '!=', $holiday))->exists()) {
            throw ValidationException::withMessages(['on' => 'This calendar already has a holiday on that day.']);
        }
        $was = $row->exists ? $row->only(['on', 'name', 'is_optional']) : null;
        $row->fill($data)->save();
        Audit::record($was ? 'holiday.changed' : 'holiday.added', $calendar, $was ? ['on' => $was['on']?->format('Y-m-d'), 'name' => $was['name']] : null, ['on' => $row->on->format('Y-m-d'), 'name' => $row->name]);

        return response()->json($calendar->load('holidays'), $was ? 200 : 201);
    }

    public function removeHoliday(Request $request, int $calendar, int $holiday): JsonResponse
    {
        Access::require($request->user(), 'wf:settings:admin');
        $calendar = HolidayCalendar::query()->findOrFail($calendar);
        $row = Holiday::query()->where('calendar_id', $calendar->id)->findOrFail($holiday);
        Audit::record('holiday.removed', $calendar, ['on' => $row->on->format('Y-m-d'), 'name' => $row->name], null);
        $row->delete();

        return response()->json($calendar->load('holidays'));
    }

    public function saveLeaveType(Request $request, ?int $type = null): JsonResponse
    {
        Access::require($request->user(), 'wf:settings:admin');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'], 'name' => ['required', 'string', 'max:80'], 'is_paid' => ['sometimes', 'boolean'],
            'annual_allowance' => ['nullable', 'numeric', 'between:0,366'], 'accrual' => ['required', Rule::in(['yearly', 'monthly', 'none'])],
            'carry_forward_max' => ['nullable', 'numeric', 'between:0,366'], 'carry_forward_expires_months' => ['nullable', 'integer', 'between:1,12'],
            'allow_half_day' => ['sometimes', 'boolean'], 'needs_approval' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['code'] = strtoupper($data['code']);
        $data['annual_allowance'] = (float) ($data['annual_allowance'] ?? 0);
        $data['carry_forward_max'] = (float) ($data['carry_forward_max'] ?? 0);
        if (LeaveType::query()->where('code', $data['code'])->when($type, fn ($q) => $q->where('id', '!=', $type))->exists()) {
            throw ValidationException::withMessages(['code' => 'Another kind of leave already has this short name.']);
        }

        return $this->save(LeaveType::class, $type, $data, 'leave_type', 'name');
    }

    public function saveSettings(Request $request): JsonResponse
    {
        Access::require($request->user(), 'wf:settings:admin');
        $data = $request->validate([
            'numbering.employee_prefix' => ['sometimes', 'string', 'max:12', 'regex:/^[A-Za-z0-9\-\/]*$/'], 'numbering.employee_digits' => ['sometimes', 'integer', 'between:1,10'],
            'attendance.self_check_in' => ['sometimes', 'boolean'], 'attendance.default_weekly_off' => ['sometimes', 'array', 'max:7'], 'attendance.default_weekly_off.*' => [Rule::in(['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'])],
            'leave.year_starts_month' => ['sometimes', 'integer', 'between:1,12'], 'leave.count_days_off' => ['sometimes', 'boolean'], 'leave.allow_negative' => ['sometimes', 'boolean'],
        ]);
        $out = [];
        foreach (array_keys(Settings::DEFAULTS) as $group) {
            $was = Settings::get($group);
            $out[$group] = isset($data[$group]) ? Settings::put($group, $data[$group]) : $was;
            [$before, $after] = Audit::difference($was, $out[$group]);
            if ($after !== []) {
                Audit::record("settings.{$group}", null, $before, $after, $request->input('reason'));
            }
        }

        return response()->json($out);
    }

    /**
     * Saves one row of a list and writes down what changed.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $data
     */
    private function save(string $model, ?int $id, array $data, string $what, string $unique): JsonResponse
    {
        $row = $id ? $model::query()->findOrFail($id) : new $model;
        if ($model::query()->where($unique, $data[$unique])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            throw ValidationException::withMessages([$unique => 'There is already one with this name.']);
        }
        $was = $row->exists ? $row->only(array_keys($data)) : null;
        $row->fill($data)->save();
        [$before, $after] = $was === null ? [null, $row->only(array_keys($data))] : Audit::difference($was, $row->fresh()->only(array_keys($data)));
        if ($after !== []) {
            Audit::record($was === null ? "{$what}.added" : "{$what}.changed", $row, $before, $after);
        }

        return response()->json($row->fresh(), $was === null ? 201 : 200);
    }
}
