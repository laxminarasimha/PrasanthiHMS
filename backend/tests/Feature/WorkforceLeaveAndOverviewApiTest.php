<?php

namespace Tests\Feature;

use App\Models\AdminEntry;
use App\Models\Branch;
use App\Models\User;
use App\Workforce\Models\Attendance;
use App\Workforce\Models\AuditEvent;
use App\Workforce\Models\DepartmentManager;
use App\Workforce\Models\Employee;
use App\Workforce\Models\LeaveLedgerLine;
use App\Workforce\Models\LeaveType;
use App\Workforce\Models\Shift;
use App\Workforce\Support\LeaveBook;
use App\Workforce\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Leave, the day's overview and a person's own record, as the screens use them. Made-up staff throughout. */
class WorkforceLeaveAndOverviewApiTest extends TestCase
{
    use RefreshDatabase;

    private Branch $here;

    private LeaveType $casual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-03-11 10:00:00'); // a Wednesday
        $this->here = Branch::create(['name' => 'Sample Hospital']);
        $this->casual = Tenant::run($this->here->id, fn () => LeaveType::create(['code' => 'CL', 'name' => 'Casual leave', 'annual_allowance' => 12]));
    }

    private function user(string $role, array $more = []): User
    {
        return User::factory()->create($more + ['role' => $role, 'branch_id' => $this->here->id]);
    }

    private function employee(string $name, array $more = []): Employee
    {
        return Tenant::run($this->here->id, fn () => Employee::create($more + ['employee_no' => 'E'.random_int(10000, 99999), 'name' => $name, 'joined_on' => '2025-01-01', 'weekly_off' => ['sun']]));
    }

    public function test_setup_gives_out_leave_and_a_person_asks_for_it_from_their_own_record(): void
    {
        $nurseLogin = $this->user('nurse');
        $nurse = $this->employee('Nurse One', ['user_id' => $nurseLogin->id]);

        // HR sets up a second kind of leave and gives everyone this year's allowance. Doing it twice gives nothing more.
        Sanctum::actingAs($this->user('hr'));
        $this->postJson('/api/workforce/setup/leave-types', ['code' => 'lop', 'name' => 'Leave without pay', 'is_paid' => false, 'accrual' => 'none', 'allow_half_day' => false])->assertCreated()->assertJsonPath('code', 'LOP');
        $this->postJson('/api/workforce/setup/leave-types', ['code' => 'CL', 'name' => 'Another casual', 'accrual' => 'yearly'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/workforce/leave/housekeeping', ['step' => 'accrue', 'year' => 2026])->assertOk()->assertJsonPath('lines_written', 1);
        $this->postJson('/api/workforce/leave/housekeeping', ['step' => 'accrue', 'year' => 2026])->assertOk()->assertJsonPath('lines_written', 0);

        // The nurse sees her own balance and asks for Friday afternoon to Monday: Fri half, Sat, (Sunday off), Mon = 2.5 days.
        Sanctum::actingAs($nurseLogin);
        $me = $this->getJson('/api/workforce/me')->assertOk()->json();
        $this->assertSame(['Nurse One', 12], [$me['employee']['name'], collect($me['leave_types'])->firstWhere('code', 'CL')['left']]);
        $asked = $this->postJson('/api/workforce/me/leave', ['leave_type_id' => $this->casual->id, 'from_date' => '2026-03-13', 'to_date' => '2026-03-16', 'from_part' => 'second_half', 'reason' => 'Family function'])
            ->assertCreated()->assertJsonPath('days', 2.5)->assertJsonPath('status', 'pending')->json();
        // Asking again over the same dates, a half day of leave that has none, or dates with no working day, is refused.
        $this->postJson('/api/workforce/me/leave', ['leave_type_id' => $this->casual->id, 'from_date' => '2026-03-16', 'to_date' => '2026-03-17'])->assertStatus(422)->assertJsonValidationErrors('from_date');
        $lop = LeaveType::withoutGlobalScopes()->where('code', 'LOP')->value('id');
        $this->postJson('/api/workforce/me/leave', ['leave_type_id' => $lop, 'from_date' => '2026-04-01', 'to_date' => '2026-04-01', 'from_part' => 'first_half'])->assertStatus(422)->assertJsonValidationErrors('from_part');
        $this->postJson('/api/workforce/me/leave', ['leave_type_id' => $this->casual->id, 'from_date' => '2026-03-22', 'to_date' => '2026-03-22'])->assertStatus(422)->assertJsonValidationErrors('from_date');
        // She cannot approve her own leave, nor see the leave list.
        $this->postJson("/api/workforce/leave/{$asked['id']}/decide", ['approve' => true])->assertForbidden();
        $this->getJson('/api/workforce/leave')->assertForbidden();

        // The manager approves; the days come off the account; attendance shows her on leave on those days.
        Sanctum::actingAs($this->user('manager'));
        $this->assertSame([true], array_column($this->getJson('/api/workforce/leave')->assertOk()->json(), 'can_decide'));
        $this->postJson("/api/workforce/leave/{$asked['id']}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame(9.5, LeaveBook::balance($nurse->id, $this->casual->id, 2026));
        $this->travelTo('2026-03-17 10:00:00');
        $days = collect($this->getJson('/api/workforce/attendance/month?month=2026-03')->json('rows.0.days'))->keyBy('date');
        $this->assertSame(['half_leave', 'on_leave', 'weekly_off', 'on_leave'], [$days['2026-03-13']['status'], $days['2026-03-14']['status'], $days['2026-03-15']['status'], $days['2026-03-16']['status']]);

        // Leave that has started is not hers to withdraw; whoever approves leave can cancel it, and the days come back.
        Sanctum::actingAs($nurseLogin);
        $this->postJson("/api/workforce/me/leave/{$asked['id']}/cancel")->assertStatus(422);
        Sanctum::actingAs($this->user('manager'));
        $this->postJson("/api/workforce/leave/{$asked['id']}/cancel", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/workforce/leave/{$asked['id']}/cancel", ['reason' => 'Recalled to duty'])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(12.0, LeaveBook::balance($nurse->id, $this->casual->id, 2026));
        $this->assertSame(['leave.accrue', 'leave.accrue', 'leave.asked', 'leave.approved', 'leave.cancelled'], AuditEvent::withoutGlobalScopes()->where('action', 'like', 'leave.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_leave_is_decided_only_within_reach_and_balances_are_corrected_by_a_line_with_a_reason(): void
    {
        $nursing = AdminEntry::create(['kind' => 'department', 'name' => 'Nursing']);
        $lab = AdminEntry::create(['kind' => 'department', 'name' => 'Laboratory']);
        $sister = $this->user('dept_manager');
        Tenant::run($this->here->id, fn () => DepartmentManager::create(['department_id' => $nursing->id, 'user_id' => $sister->id]));
        $nurse = $this->employee('Nurse One', ['department_id' => $nursing->id]);
        $tech = $this->employee('Lab Person', ['department_id' => $lab->id]);

        Sanctum::actingAs($this->user('hr'));
        $this->postJson('/api/workforce/leave/housekeeping', ['step' => 'accrue', 'year' => 2026])->assertOk();
        $forNurse = $this->postJson('/api/workforce/leave', ['employee_id' => $nurse->id, 'leave_type_id' => $this->casual->id, 'from_date' => '2026-03-18', 'to_date' => '2026-03-18'])->assertCreated()->json();
        $forTech = $this->postJson('/api/workforce/leave', ['employee_id' => $tech->id, 'leave_type_id' => $this->casual->id, 'from_date' => '2026-03-18', 'to_date' => '2026-03-19'])->assertCreated()->json();

        // The nursing manager sees and decides her own department's leave only.
        Sanctum::actingAs($sister);
        $this->assertSame(['Nurse One'], array_map(fn ($r) => $r['employee']['name'], $this->getJson('/api/workforce/leave')->json()));
        $this->postJson("/api/workforce/leave/{$forTech['id']}/decide", ['approve' => true])->assertForbidden();
        $this->postJson("/api/workforce/leave/{$forNurse['id']}/decide", ['approve' => false])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/workforce/leave/{$forNurse['id']}/decide", ['approve' => false, 'note' => 'Ward is short that day'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame(['Nurse One'], array_column($this->getJson('/api/workforce/leave/balances')->json('rows'), 'name'));
        $this->getJson("/api/workforce/leave/ledger/{$tech->id}")->assertForbidden();
        // Changing a balance is not hers to do.
        $this->postJson('/api/workforce/leave/adjust', ['employee_id' => $nurse->id, 'leave_type_id' => $this->casual->id, 'year' => 2026, 'days' => 5, 'kind' => 'adjustment', 'reason' => 'Extra'])->assertForbidden();

        // HR corrects a balance with a line and a reason; the earlier lines stay.
        Sanctum::actingAs($this->user('hr'));
        $this->postJson('/api/workforce/leave/adjust', ['employee_id' => $tech->id, 'leave_type_id' => $this->casual->id, 'year' => 2026, 'days' => -2, 'kind' => 'adjustment'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $ledger = $this->postJson('/api/workforce/leave/adjust', ['employee_id' => $tech->id, 'leave_type_id' => $this->casual->id, 'year' => 2026, 'days' => -2, 'kind' => 'adjustment', 'reason' => 'Two days taken in January before the system'])->assertOk()->json();
        $this->assertSame([['accrual', 12, 12], ['adjustment', -2, 10]], array_map(fn ($l) => [$l['kind'], $l['days'], $l['balance']], $ledger['lines']));
        $this->assertEquals(['balance' => 12], AuditEvent::where('action', 'leave.balance.adjusted')->first()->before);
        $this->assertSame(2, LeaveLedgerLine::where('employee_id', $tech->id)->count());
        $balances = collect($this->getJson('/api/workforce/leave/balances?year=2026')->json('rows'))->keyBy('name');
        $this->assertEquals(10, $balances['Lab Person']['balances'][$this->casual->id]['left']);
    }

    public function test_the_overview_shows_the_day_and_what_is_waiting_within_reach(): void
    {
        $shift = Tenant::run($this->here->id, fn () => Shift::create(['name' => 'Day', 'starts_at' => '09:00', 'ends_at' => '17:30', 'grace_minutes' => 10]));
        $nursing = AdminEntry::create(['kind' => 'department', 'name' => 'Nursing']);
        $lab = AdminEntry::create(['kind' => 'department', 'name' => 'Laboratory']);
        $onTime = $this->employee('On Time', ['shift_id' => $shift->id, 'department_id' => $nursing->id]);
        $late = $this->employee('Came Late', ['shift_id' => $shift->id, 'department_id' => $nursing->id]);
        $away = $this->employee('On Leave', ['department_id' => $nursing->id]);
        $missing = $this->employee('Not In', ['department_id' => $lab->id]);
        $this->employee('Left Last Year', ['status' => 'inactive', 'separated_on' => '2025-12-31']);
        Tenant::run($this->here->id, function () use ($onTime, $late, $missing) {
            foreach ([[$onTime, '2026-03-11 09:00:00', '2026-03-11'], [$late, '2026-03-11 09:40:00', '2026-03-11'], [$missing, '2026-03-09 09:00:00', '2026-03-09']] as [$person, $in, $date]) {
                (new Attendance(['employee_id' => $person->id, 'work_date' => $date, 'shift_id' => $person->shift_id, 'check_in' => $in]))->measured()->save();
            }
            LeaveBook::accrue(2026);
        });
        Sanctum::actingAs($this->user('hr'));
        $leave = $this->postJson('/api/workforce/leave', ['employee_id' => $away->id, 'leave_type_id' => $this->casual->id, 'from_date' => '2026-03-11', 'to_date' => '2026-03-11'])->assertCreated()->json();

        Sanctum::actingAs($this->user('manager'));
        $before = $this->getJson('/api/workforce/dashboard')->assertOk()->json();
        $this->assertSame([4, 1], [$before['staff'], $before['leave_waiting']]);
        $this->postJson("/api/workforce/leave/{$leave['id']}/decide", ['approve' => true])->assertOk();

        $day = $this->getJson('/api/workforce/dashboard')->assertOk()->json();
        $this->assertSame(['Came Late', 'On Time'], array_column($day['in'], 'name'));
        $this->assertSame([['Came Late', 40]], array_map(fn ($p) => [$p['name'], $p['late_minutes']], $day['late']));
        $this->assertSame(['On Leave'], array_column($day['on_leave'], 'name'));
        $this->assertSame(['Not In'], array_column($day['not_in'], 'name'));
        // Monday's check-out that was never made is still waiting for someone.
        $this->assertSame([['Not In', '2026-03-09']], array_map(fn ($m) => [$m['employee']['name'], $m['work_date']], $day['missing_check_outs']));
        $this->assertSame([0, 0], [$day['leave_waiting'], $day['corrections_waiting']]);

        // A department manager's overview is her department only; ordinary staff have none.
        $sister = $this->user('dept_manager');
        Tenant::run($this->here->id, fn () => DepartmentManager::create(['department_id' => $lab->id, 'user_id' => $sister->id]));
        Sanctum::actingAs($sister);
        $hers = $this->getJson('/api/workforce/dashboard')->assertOk()->json();
        $this->assertSame([1, ['Not In'], []], [$hers['staff'], array_column($hers['not_in'], 'name'), $hers['in']]);
        Sanctum::actingAs($this->user('frontdesk'));
        $this->getJson('/api/workforce/dashboard')->assertForbidden();
    }

    public function test_a_persons_own_record_is_theirs_alone_and_self_check_in_is_off_until_switched_on(): void
    {
        $login = $this->user('frontdesk');
        $me = $this->employee('Desk Person', ['user_id' => $login->id]);
        $this->employee('Someone Else');

        // A login with no staff record joined to it is told so, not shown an empty record.
        Sanctum::actingAs($this->user('pharmacist'));
        $this->getJson('/api/workforce/me')->assertOk()->assertJsonPath('employee', null);
        $this->postJson('/api/workforce/me/leave', ['leave_type_id' => $this->casual->id, 'from_date' => '2026-03-18', 'to_date' => '2026-03-18'])->assertStatus(422);

        Sanctum::actingAs($login);
        $record = $this->getJson('/api/workforce/me')->assertOk()->json();
        $this->assertSame(['Desk Person', false, 31], [$record['employee']['name'], $record['self_check_in'], count($record['days'])]);
        // Marking oneself in is refused until the hospital switches it on.
        $this->postJson('/api/workforce/me/punch')->assertForbidden();
        $this->assertSame(0, Attendance::withoutGlobalScopes()->count());

        Sanctum::actingAs($this->user('admin'));
        $this->putJson('/api/workforce/setup/settings', ['attendance' => ['self_check_in' => true]])->assertOk()->assertJsonPath('attendance.self_check_in', true);
        Sanctum::actingAs($login);
        $this->postJson('/api/workforce/me/punch')->assertOk()->assertJsonPath('state', 'open')->assertJsonPath('origin', 'self')->assertJsonPath('employee_id', $me->id);
        $this->travelTo('2026-03-11 18:00:00');
        $this->postJson('/api/workforce/me/punch')->assertOk()->assertJsonPath('state', 'completed')->assertJsonPath('worked_minutes', 480);
        $this->assertSame([$me->id], Attendance::withoutGlobalScopes()->pluck('employee_id')->all());
        $this->assertSame(['present', 480], [collect($this->getJson('/api/workforce/me')->json('days'))->firstWhere('date', '2026-03-11')['status'], $this->getJson('/api/workforce/me')->json('totals.worked_minutes')]);
    }
}
