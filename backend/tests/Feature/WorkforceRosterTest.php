<?php

namespace Tests\Feature;

use App\Models\AdminEntry;
use App\Models\Branch;
use App\Models\User;
use App\Workforce\Models\Attendance;
use App\Workforce\Models\AuditEvent;
use App\Workforce\Models\DepartmentManager;
use App\Workforce\Models\Employee;
use App\Workforce\Models\RosterDay;
use App\Workforce\Support\AttendanceRules;
use App\Workforce\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Morning, long-day and night duties, and the roster that says who works which on which day. The three shifts are
 * the ones the hospital described: 8 AM to 6 PM, 10 AM to 9 PM, and 8 PM to 8 AM the next morning. Made-up staff.
 */
class WorkforceRosterTest extends TestCase
{
    use RefreshDatabase;

    private Branch $here;

    private User $hr;

    /** @var array<string, int> */
    private array $shift = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-01 09:00:00'); // a Monday
        $this->here = Branch::create(['name' => 'Sample Hospital']);
        $this->hr = User::factory()->create(['role' => 'hr', 'branch_id' => $this->here->id]);
        Sanctum::actingAs($this->hr);
        foreach ([
            'morning' => ['Morning duty', '08:00', '18:00', 540, 270],
            'long' => ['Long day', '10:00', '21:00', 600, 300],
            'night' => ['Night duty', '20:00', '08:00', 660, 330],
        ] as $key => [$name, $starts, $ends, $full, $half]) {
            $this->shift[$key] = $this->postJson('/api/workforce/setup/shifts', ['name' => $name, 'starts_at' => $starts, 'ends_at' => $ends, 'grace_minutes' => 10, 'full_day_minutes' => $full, 'half_day_minutes' => $half])
                ->assertCreated()->json('id');
        }
    }

    private function employee(string $name, array $more = []): Employee
    {
        return Tenant::run($this->here->id, fn () => Employee::create($more + ['employee_no' => 'E'.random_int(10000, 99999), 'name' => $name, 'joined_on' => '2025-01-01', 'weekly_off' => ['sun'], 'shift_id' => $this->shift['morning']]));
    }

    private function day(Employee $e, string $date): array
    {
        return Tenant::run($this->here->id, fn () => AttendanceRules::days(collect([$e->fresh()]), $date, $date)[$e->id][$date]);
    }

    public function test_a_nurse_moved_to_night_duty_is_measured_against_night_duty_that_week(): void
    {
        $asha = $this->employee('Asha Sample');

        // Nights Monday to Wednesday; her usual morning duty the rest of the week.
        $this->putJson('/api/workforce/attendance/roster', ['lines' => [['employee_id' => $asha->id, 'from' => '2026-06-01', 'to' => '2026-06-03', 'duty' => (string) $this->shift['night']]]])
            ->assertOk()->assertJsonPath('days_changed', 3);
        $week = $this->getJson('/api/workforce/attendance/roster?from=2026-06-01')->assertOk();
        $this->assertSame([$this->shift['night'], $this->shift['night'], $this->shift['night'], $this->shift['morning']], array_map(fn ($d) => $d['shift_id'], array_slice(array_values($week->json('rows.0.days')), 0, 4)));
        $this->assertTrue($week->json('rows.0.days.2026-06-01.rostered'));
        $this->assertTrue($week->json('rows.0.days.2026-06-07.off')); // Sunday, her usual weekly off
        $this->assertSame([(string) $this->shift['night'] => 1], $week->json('cover.2026-06-01'));

        // At 9 AM on Monday she is not "not in": her duty starts tonight.
        $monday = $this->day($asha, '2026-06-01');
        $this->assertSame(['later', 'Night duty', 'On duty from 20:00', 0.0], [$monday['status'], $monday['shift'], $monday['note'], $monday['absent']]);
        $this->getJson('/api/workforce/dashboard')->assertOk()->assertJsonCount(0, 'not_in')->assertJsonPath('later.0.name', 'Asha Sample')->assertJsonPath('later.0.shift', 'Night duty');

        // She checks in at 8:25 PM (late, past the 10-minute grace) and out at 8:05 the next morning.
        $this->travelTo('2026-06-01 20:25:00');
        $id = $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $asha->id])->assertCreated()->assertJsonPath('work_date', '2026-06-01')->assertJsonPath('late_minutes', 25)->json('id');
        $this->travelTo('2026-06-02 08:05:00');
        $this->postJson("/api/workforce/attendance/{$id}/check-out")->assertOk()->assertJsonPath('worked_minutes', 700)->assertJsonPath('state', 'completed');
        $this->assertSame('present', $this->day($asha, '2026-06-01')['status']);
        $this->assertSame($this->shift['night'], Attendance::find($id)->shift_id);

        // On Tuesday night she arrives after midnight: the check-in belongs to Tuesday's night duty, not to Wednesday.
        $this->travelTo('2026-06-03 00:40:00');
        $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $asha->id])->assertCreated()->assertJsonPath('work_date', '2026-06-02')->assertJsonPath('late_minutes', 280);
    }

    public function test_staff_today_shows_as_in_only_those_in_the_hospital_right_now(): void
    {
        $asha = $this->employee('Asha Sample', ['shift_id' => $this->shift['night']]);
        $ravi = $this->employee('Ravi Sample');
        $in = fn () => array_column($this->getJson('/api/workforce/dashboard')->assertOk()->json('in'), 'name');

        // Asha's night duty begins at 8 PM; Ravi works the morning duty.
        $this->travelTo('2026-06-01 20:00:00');
        $night = $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $asha->id])->assertCreated()->json('id');
        $this->travelTo('2026-06-02 02:00:00');
        // After midnight she is still in the hospital, and her open night duty is not a forgotten check-out.
        $this->getJson('/api/workforce/dashboard')->assertOk()->assertJsonPath('in.0.name', 'Asha Sample')->assertJsonPath('in.0.note', 'since yesterday 8:00 PM')
            ->assertJsonCount(0, 'missing_check_outs')->assertJsonCount(0, 'not_in');

        $this->travelTo('2026-06-02 08:00:00');
        $morning = $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $ravi->id])->assertCreated()->json('id');
        $this->assertSame(['Asha Sample', 'Ravi Sample'], $in());
        $this->travelTo('2026-06-02 08:10:00');
        $this->postJson("/api/workforce/attendance/{$night}/check-out")->assertOk();
        $this->assertSame(['Ravi Sample'], $in());

        // Once Ravi has gone home he is no longer "in": he shows among those who came and left.
        $this->travelTo('2026-06-02 18:05:00');
        $this->postJson("/api/workforce/attendance/{$morning}/check-out")->assertOk();
        $this->travelTo('2026-06-02 19:00:00');
        $this->getJson('/api/workforce/dashboard')->assertOk()->assertJsonCount(0, 'in')->assertJsonPath('left.0.name', 'Ravi Sample');
        // Looking back at the day, everyone who came is still listed.
        $this->getJson('/api/workforce/dashboard?date=2026-06-01')->assertOk()->assertJsonPath('in.0.name', 'Asha Sample')->assertJsonPath('left', null);

        // A spell left open from an earlier day is a forgotten check-out, not someone in the hospital.
        $this->travelTo('2026-06-03 09:00:00');
        $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $ravi->id])->assertCreated();
        $this->travelTo('2026-06-04 09:30:00');
        $this->getJson('/api/workforce/dashboard')->assertOk()->assertJsonCount(0, 'in')->assertJsonPath('missing_check_outs.0.employee.name', 'Ravi Sample');
    }

    public function test_the_roster_moves_a_day_off_and_changing_it_measures_recorded_attendance_again(): void
    {
        $ravi = $this->employee('Ravi Sample');
        // His day off moves from Sunday to Wednesday; he works the Sunday on the long day.
        $this->putJson('/api/workforce/attendance/roster', ['lines' => [
            ['employee_id' => $ravi->id, 'from' => '2026-06-03', 'duty' => 'off'],
            ['employee_id' => $ravi->id, 'from' => '2026-06-07', 'duty' => (string) $this->shift['long']],
        ]])->assertOk()->assertJsonPath('days_changed', 2);
        $this->travelTo('2026-06-08 09:00:00');
        $this->assertSame('weekly_off', $this->day($ravi, '2026-06-03')['status']);
        $this->assertSame(['absent', 1.0], [$this->day($ravi, '2026-06-07')['status'], $this->day($ravi, '2026-06-07')['absent']]);

        // Attendance entered for a rostered day is measured against that day's duty: 10 AM is on time for the long day.
        $entered = $this->postJson('/api/workforce/attendance', ['employee_id' => $ravi->id, 'work_date' => '2026-06-07', 'check_in' => '2026-06-07 10:05', 'check_out' => '2026-06-07 21:00', 'reason' => 'Entered from the register'])
            ->assertCreated()->assertJsonPath('late_minutes', 0)->json('id');
        // Put back to his usual morning duty, the same times are now two hours late and the record follows.
        $this->putJson('/api/workforce/attendance/roster', ['lines' => [['employee_id' => $ravi->id, 'from' => '2026-06-07', 'duty' => 'usual']]])->assertOk();
        $this->assertSame([$this->shift['morning'], 125], [Attendance::find($entered)->shift_id, Attendance::find($entered)->late_minutes]);
        $this->assertSame(1, Tenant::run($this->here->id, fn () => RosterDay::count()));
        $this->putJson('/api/workforce/attendance/roster', ['lines' => [['employee_id' => $ravi->id, 'from' => '2026-06-07', 'duty' => 'usual']]])->assertOk()->assertJsonPath('days_changed', 0);
        $this->putJson('/api/workforce/attendance/roster', ['lines' => [['employee_id' => $ravi->id, 'from' => '2026-06-07', 'duty' => 'afternoon']]])->assertStatus(422);
        $this->assertGreaterThan(0, AuditEvent::where('action', 'roster.changed')->count());
    }

    public function test_last_weeks_roster_is_copied_onto_this_week_and_staff_see_their_own_duties(): void
    {
        $nurseLogin = User::factory()->create(['role' => 'nurse', 'branch_id' => $this->here->id]);
        $asha = $this->employee('Asha Sample', ['user_id' => $nurseLogin->id]);
        $this->putJson('/api/workforce/attendance/roster', ['lines' => [
            ['employee_id' => $asha->id, 'from' => '2026-06-01', 'to' => '2026-06-02', 'duty' => (string) $this->shift['night']],
            ['employee_id' => $asha->id, 'from' => '2026-06-03', 'duty' => 'off'],
            ['employee_id' => $asha->id, 'from' => '2026-06-09', 'duty' => (string) $this->shift['long']], // overwritten by the copy
        ]])->assertOk();
        $this->postJson('/api/workforce/attendance/roster/copy', ['from' => '2026-06-01', 'to' => '2026-06-08', 'days' => 7])->assertOk()->assertJsonPath('days_changed', 3);
        $next = $this->getJson('/api/workforce/attendance/roster?from=2026-06-08')->json('rows.0.days');
        $this->assertSame([$this->shift['night'], $this->shift['night'], null, $this->shift['morning']], [$next['2026-06-08']['shift_id'], $next['2026-06-09']['shift_id'], $next['2026-06-10']['shift_id'], $next['2026-06-11']['shift_id']]);
        $this->assertTrue($next['2026-06-10']['off']);

        // She sees her next two weeks in her own record, and cannot change the roster.
        Sanctum::actingAs($nurseLogin);
        $mine = $this->getJson('/api/workforce/me')->assertOk()->json('duties');
        $this->assertCount(14, $mine);
        $this->assertSame(['Night duty', '20:00', '08:00', true], [$mine[0]['shift'], $mine[0]['starts_at'], $mine[0]['ends_at'], $mine[0]['rostered']]);
        $this->assertTrue($mine[2]['off']);
        $this->assertSame('Morning duty', $mine[3]['shift']);
        $this->putJson('/api/workforce/attendance/roster', ['lines' => [['employee_id' => $asha->id, 'from' => '2026-06-04', 'duty' => 'off']]])->assertForbidden();
        $this->getJson('/api/workforce/attendance/roster')->assertForbidden();
    }

    public function test_a_department_manager_sets_duties_only_for_their_own_department(): void
    {
        $wards = AdminEntry::create(['kind' => 'department', 'name' => 'Wards']);
        $lab = AdminEntry::create(['kind' => 'department', 'name' => 'Laboratory']);
        $asha = $this->employee('Asha Sample', ['department_id' => $wards->id]);
        $ravi = $this->employee('Ravi Sample', ['department_id' => $lab->id]);
        $sister = User::factory()->create(['role' => 'dept_manager', 'branch_id' => $this->here->id]);
        Tenant::run($this->here->id, fn () => DepartmentManager::create(['department_id' => $wards->id, 'user_id' => $sister->id]));

        Sanctum::actingAs($sister);
        $rows = $this->getJson('/api/workforce/attendance/roster')->assertOk()->json('rows');
        $this->assertSame(['Asha Sample'], array_column($rows, 'name'));
        $this->putJson('/api/workforce/attendance/roster', ['lines' => [['employee_id' => $asha->id, 'from' => '2026-06-05', 'duty' => (string) $this->shift['night']]]])->assertOk();
        $this->putJson('/api/workforce/attendance/roster', ['lines' => [['employee_id' => $ravi->id, 'from' => '2026-06-05', 'duty' => (string) $this->shift['night']]]])->assertForbidden();
    }
}
