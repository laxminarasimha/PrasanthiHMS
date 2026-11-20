<?php

namespace Tests\Feature;

use App\Models\AdminEntry;
use App\Models\Branch;
use App\Models\User;
use App\Workforce\Models\Attendance;
use App\Workforce\Models\AttendanceCorrection;
use App\Workforce\Models\AuditEvent;
use App\Workforce\Models\DepartmentManager;
use App\Workforce\Models\Employee;
use App\Workforce\Models\EmployeePrivate;
use App\Workforce\Models\Shift;
use App\Workforce\Support\Tenant;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The staff directory and attendance as the screens use them, with made-up staff. */
class WorkforceStaffAndAttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    private Branch $here;

    private Branch $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-03-11 10:00:00'); // a Wednesday
        $this->here = Branch::create(['name' => 'Sample Hospital']);
        $this->elsewhere = Branch::create(['name' => 'Another Hospital']);
    }

    private function user(string $role, array $more = [], ?Branch $branch = null): User
    {
        return User::factory()->create($more + ['role' => $role, 'branch_id' => ($branch ?? $this->here)->id]);
    }

    private function employee(string $name, array $more = [], ?Branch $branch = null): Employee
    {
        return Tenant::run(($branch ?? $this->here)->id, fn () => Employee::create($more + ['employee_no' => 'E'.random_int(10000, 99999), 'name' => $name, 'joined_on' => '2025-01-01']));
    }

    public function test_staff_are_added_numbered_changed_and_kept_when_they_leave(): void
    {
        $nursing = AdminEntry::create(['kind' => 'department', 'name' => 'Nursing']);
        $login = $this->user('nurse', ['name' => 'Asha Login']);
        Sanctum::actingAs($this->user('hr'));

        // The first number follows the hospital's own pattern, which it can change.
        $this->getJson('/api/workforce/options')->assertOk()->assertJsonPath('next_employee_no', 'EMP-000001')->assertJsonPath('departments.0.name', 'Nursing');
        $this->putJson('/api/workforce/setup/settings', ['numbering' => ['employee_prefix' => 'SH-EMP-', 'employee_digits' => 4]])->assertOk();
        $asha = $this->postJson('/api/workforce/employees', ['name' => 'Asha Sample', 'phone' => '9000000001', 'department_id' => $nursing->id, 'designation' => 'Staff Nurse', 'joined_on' => '2025-06-01', 'employment_type' => 'permanent', 'weekly_off' => ['sun'], 'user_id' => $login->id])
            ->assertCreated()->assertJsonPath('employee_no', 'SH-EMP-0001')->assertJsonPath('department.name', 'Nursing')->json();
        $this->postJson('/api/workforce/employees', ['name' => 'Bhanu Sample'])->assertCreated()->assertJsonPath('employee_no', 'SH-EMP-0002');

        // The same login cannot be joined to two people, nor a number used twice, nor a person report to themself.
        $this->postJson('/api/workforce/employees', ['name' => 'Chitra Sample', 'user_id' => $login->id])->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->postJson('/api/workforce/employees', ['name' => 'Chitra Sample', 'employee_no' => 'SH-EMP-0001'])->assertStatus(422)->assertJsonValidationErrors('employee_no');
        $this->putJson("/api/workforce/employees/{$asha['id']}", ['employee_no' => 'SH-EMP-0001', 'name' => 'Asha Sample', 'manager_id' => $asha['id']])->assertStatus(422)->assertJsonValidationErrors('manager_id');

        // A change is written down with what it was and became. Leaving marks her inactive, with a date, and keeps her.
        $this->putJson("/api/workforce/employees/{$asha['id']}", ['employee_no' => 'SH-EMP-0001', 'name' => 'Asha Sample', 'designation' => 'Senior Staff Nurse', 'status' => 'inactive', 'reason' => 'Resigned'])
            ->assertOk()->assertJsonPath('status', 'inactive')->assertJsonPath('separated_on', '2026-03-11');
        $change = AuditEvent::where('action', 'staff.changed')->latest('id')->first();
        $this->assertSame(['Staff Nurse', 'Senior Staff Nurse', 'Resigned'], [$change->before['designation'], $change->after['designation'], $change->reason]);
        $this->assertSame(['Bhanu Sample'], array_column($this->getJson('/api/workforce/employees')->json('data'), 'name'));
        $this->assertSame(['Asha Sample', 'Bhanu Sample'], array_column($this->getJson('/api/workforce/employees?status=all')->json('data'), 'name'));
        $this->assertSame(1, Employee::where('status', 'inactive')->count());
    }

    public function test_the_directory_is_reached_only_as_far_as_the_permission_goes(): void
    {
        $nursing = AdminEntry::create(['kind' => 'department', 'name' => 'Nursing']);
        $lab = AdminEntry::create(['kind' => 'department', 'name' => 'Laboratory']);
        $nurse = $this->employee('Nurse One', ['department_id' => $nursing->id]);
        $tech = $this->employee('Lab Person', ['department_id' => $lab->id]);
        $other = $this->employee('Other Hospital Person', ['department_id' => $nursing->id], $this->elsewhere);
        $sister = $this->user('dept_manager');
        Tenant::run($this->here->id, fn () => DepartmentManager::create(['department_id' => $nursing->id, 'user_id' => $sister->id]));

        // A department manager sees her own department, cannot open anyone else, and cannot add or change staff.
        Sanctum::actingAs($sister);
        $this->assertSame(['Nurse One'], array_column($this->getJson('/api/workforce/employees')->assertOk()->json('data'), 'name'));
        $this->getJson("/api/workforce/employees/{$nurse->id}")->assertOk();
        $this->getJson("/api/workforce/employees/{$tech->id}")->assertForbidden();
        $this->postJson('/api/workforce/employees', ['name' => 'New Person'])->assertForbidden();
        $this->putJson("/api/workforce/employees/{$nurse->id}", ['employee_no' => $nurse->employee_no, 'name' => 'Renamed'])->assertForbidden();
        $this->getJson('/api/workforce/employees/export')->assertForbidden();

        // Another hospital's member of staff does not exist as far as this one is concerned.
        Sanctum::actingAs($this->user('hr'));
        $this->getJson("/api/workforce/employees/{$other->id}")->assertNotFound();
        $this->putJson("/api/workforce/employees/{$other->id}", ['employee_no' => 'X1', 'name' => 'Taken Over'])->assertNotFound();
        $this->assertSame('Other Hospital Person', DB::table('wf_employees')->where('id', $other->id)->value('name'));

        // Ordinary staff have no directory at all.
        Sanctum::actingAs($this->user('frontdesk'));
        $this->getJson('/api/workforce/employees')->assertForbidden();
        $this->getJson('/api/workforce/options')->assertForbidden();
    }

    public function test_bank_and_identity_details_need_their_permission_the_password_and_leave_a_trace(): void
    {
        $asha = $this->employee('Asha Sample');
        $hr = $this->user('hr', ['password' => bcrypt('Sample-Passw0rd')]);

        // The manager sees the directory but not these details.
        Sanctum::actingAs($this->user('manager'));
        $this->getJson("/api/workforce/employees/{$asha->id}")->assertOk();
        $this->getJson("/api/workforce/employees/{$asha->id}/private")->assertForbidden();

        // HR may, but changing them needs the password typed again.
        Sanctum::actingAs($hr);
        $details = ['bank_name' => 'Sample Bank', 'bank_account_no' => '123456789012', 'bank_ifsc' => 'SMPL0001234', 'pan' => 'ABCDE1234F', 'reason' => 'Joining papers'];
        $this->putJson("/api/workforce/employees/{$asha->id}/private", $details)->assertStatus(428);
        $this->postJson('/api/workforce/confirm-password', ['password' => 'Sample-Passw0rd'])->assertOk();
        $this->putJson("/api/workforce/employees/{$asha->id}/private", ['pan' => 'not-a-pan'] + $details)->assertStatus(422)->assertJsonValidationErrors('pan');
        $this->putJson("/api/workforce/employees/{$asha->id}/private", $details)->assertOk();

        // Shown masked by default; in full only on asking, with the password still fresh. Each look is recorded.
        $this->getJson("/api/workforce/employees/{$asha->id}/private")->assertOk()->assertJsonPath('bank_account_no', '••••••••9012')->assertJsonPath('pan', '••••••234F')->assertJsonPath('bank_name', 'Sample Bank');
        $this->getJson("/api/workforce/employees/{$asha->id}/private?reveal=1")->assertOk()->assertJsonPath('bank_account_no', '123456789012');
        $this->travel(11)->minutes();
        $this->getJson("/api/workforce/employees/{$asha->id}/private?reveal=1")->assertStatus(428);

        $this->assertSame(['staff.private.changed', 'staff.private.viewed', 'staff.private.revealed'], AuditEvent::where('action', 'like', 'staff.private.%')->orderBy('id')->pluck('action')->all());
        // Nothing in the record of changes, nor in the directory, carries a full number.
        $trail = json_encode(AuditEvent::all()->toArray()).$this->getJson("/api/workforce/employees/{$asha->id}")->getContent().$this->getJson('/api/workforce/employees')->getContent();
        foreach (['123456789012', 'ABCDE1234F', 'SMPL0001234'] as $secret) {
            $this->assertStringNotContainsString($secret, $trail);
        }
        // Saving the form with the masked values still in it changes nothing.
        $this->postJson('/api/workforce/confirm-password', ['password' => 'Sample-Passw0rd'])->assertOk();
        $this->putJson("/api/workforce/employees/{$asha->id}/private", ['bank_account_no' => '••••••••9012', 'bank_name' => 'Sample Bank', 'reason' => 'No change'])->assertOk();
        $this->getJson("/api/workforce/employees/{$asha->id}/private?reveal=1")->assertJsonPath('bank_account_no', '123456789012');
    }

    public function test_checking_in_and_out_follows_the_rules_the_old_system_kept(): void
    {
        $shift = Tenant::run($this->here->id, fn () => Shift::create(['name' => 'Day', 'starts_at' => '09:00', 'ends_at' => '17:30', 'grace_minutes' => 10, 'break_minutes' => 30, 'half_day_minutes' => 240, 'full_day_minutes' => 420]));
        $asha = $this->employee('Asha Sample', ['shift_id' => $shift->id]);
        $gone = $this->employee('Left Already', ['status' => 'inactive', 'separated_on' => '2026-01-31']);
        Sanctum::actingAs($this->user('manager'));

        // In at 10:00 against a 9:00 shift: an hour late. A second check-in while she is in is refused.
        $in = $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $asha->id])->assertCreated()->assertJsonPath('state', 'open')->assertJsonPath('late_minutes', 60)->json();
        $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $asha->id])->assertStatus(422)->assertJsonValidationErrors('employee_id');
        $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $gone->id])->assertStatus(422);

        $day = $this->getJson('/api/workforce/attendance/day')->assertOk()->json();
        $this->assertSame(['open', 1, 1, 1], [$day['rows'][0]['day']['status'], $day['counts']['in'], $day['counts']['late'], $day['counts']['still_in']]);
        $this->assertCount(1, $day['rows']); // the one who left is not on today's list

        $this->travelTo('2026-03-11 17:35:00');
        $this->postJson("/api/workforce/attendance/{$in['id']}/check-out")->assertOk()->assertJsonPath('state', 'completed')->assertJsonPath('worked_minutes', 425);
        $this->postJson("/api/workforce/attendance/{$in['id']}/check-out")->assertStatus(422);
        $this->assertSame('present', $this->getJson('/api/workforce/attendance/day')->json('rows.0.day.status'));

        // Entering an earlier day by hand needs the reason; a time that has not come is refused; so is the same start twice.
        $yesterday = ['employee_id' => $asha->id, 'work_date' => '2026-03-10', 'check_in' => '2026-03-10 09:05', 'check_out' => '2026-03-10 17:30'];
        $this->postJson('/api/workforce/attendance', $yesterday)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson('/api/workforce/attendance', $yesterday + ['reason' => 'Machine was down'])->assertCreated()->assertJsonPath('worked_minutes', 475);
        $this->postJson('/api/workforce/attendance', $yesterday + ['reason' => 'Again'])->assertStatus(422)->assertJsonValidationErrors('check_in');
        $this->postJson('/api/workforce/attendance', ['employee_id' => $asha->id, 'work_date' => '2026-03-12', 'check_in' => '2026-03-12 09:00', 'reason' => 'Tomorrow'])->assertStatus(422);
        $this->postJson('/api/workforce/attendance', ['employee_id' => $asha->id, 'work_date' => '2026-03-09', 'check_in' => '2026-03-09 09:00', 'check_out' => '2026-03-09 08:00', 'reason' => 'Backwards'])->assertStatus(422)->assertJsonValidationErrors('check_out');

        // The month adds it up, and the export is for those allowed to take it away.
        $month = $this->getJson('/api/workforce/attendance/month?month=2026-03')->assertOk()->json('rows.0');
        $this->assertEquals([2.0, 1, 900], [$month['totals']['present'], $month['totals']['late_days'], $month['totals']['worked_minutes']]);
        $this->assertCount(31, $month['days']);
        $this->get('/api/workforce/attendance/export?month=2026-03')->assertForbidden();
        Sanctum::actingAs($this->user('hr'));
        $this->assertStringContainsString('Asha Sample', $this->get('/api/workforce/attendance/export?month=2026-03')->assertOk()->streamedContent());
        $this->assertSame(['month' => '2026-03', 'rows' => 1], AuditEvent::where('action', 'attendance.exported')->first()->after);
    }

    public function test_a_correction_always_has_a_reason_and_keeps_what_it_was_and_became(): void
    {
        $asha = $this->employee('Asha Sample');
        $record = Tenant::run($this->here->id, function () use ($asha) {
            $r = new Attendance(['employee_id' => $asha->id, 'work_date' => '2026-03-10', 'check_in' => '2026-03-10 09:00:00', 'origin' => 'manual']);
            $r->measured()->save();

            return $r;
        });
        Sanctum::actingAs($this->user('hr', ['name' => 'HR Sample']));

        // Yesterday's check-out was never made. Putting it right directly needs the reason.
        $fix = ['work_date' => '2026-03-10', 'check_in' => '2026-03-10 09:00', 'check_out' => '2026-03-10 17:00'];
        $this->putJson("/api/workforce/attendance/{$record->id}", $fix)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->putJson("/api/workforce/attendance/{$record->id}", $fix + ['reason' => 'Forgot to check out; confirmed with the ward'])->assertOk()->assertJsonPath('state', 'completed')->assertJsonPath('worked_minutes', 480);

        $kept = AttendanceCorrection::first();
        $this->assertSame(['applied', null, '2026-03-10 17:00:00', 'Forgot to check out; confirmed with the ward'], [$kept->status, $kept->before['check_out'], $kept->after['check_out'], $kept->reason]);
        $audit = AuditEvent::where('action', 'attendance.corrected')->first();
        $this->assertSame([['check_out' => null], ['check_out' => '2026-03-10 17:00:00'], 'HR Sample'], [$audit->before, $audit->after, $audit->actor_name]);
        $this->assertSame(['applied'], array_column($this->getJson('/api/workforce/attendance/corrections?status=all')->json(), 'status'));
    }

    public function test_a_requested_correction_changes_nothing_until_someone_else_approves_it(): void
    {
        $nursing = AdminEntry::create(['kind' => 'department', 'name' => 'Nursing']);
        $lab = AdminEntry::create(['kind' => 'department', 'name' => 'Laboratory']);
        $sisterLogin = $this->user('dept_manager', ['name' => 'Sister Sample']);
        $nurseLogin = $this->user('nurse', ['name' => 'Nurse Login']);
        $sister = $this->employee('Sister Sample', ['department_id' => $nursing->id, 'user_id' => $sisterLogin->id]);
        $nurse = $this->employee('Nurse One', ['department_id' => $nursing->id, 'user_id' => $nurseLogin->id]);
        $tech = $this->employee('Lab Person', ['department_id' => $lab->id]);
        Tenant::run($this->here->id, fn () => DepartmentManager::create(['department_id' => $nursing->id, 'user_id' => $sisterLogin->id]));

        // The nurse asks, from her own record, for a day that was missed. Nothing is entered yet.
        Sanctum::actingAs($nurseLogin);
        $asked = $this->postJson('/api/workforce/me/corrections', ['work_date' => '2026-03-09', 'check_in' => '2026-03-09 09:00', 'check_out' => '2026-03-09 17:00', 'reason' => 'I was on duty; the register was not filled'])->assertCreated()->json();
        $this->assertSame(0, Attendance::withoutGlobalScopes()->count());
        // She cannot decide it herself, and has no way to see anyone else's.
        $this->postJson("/api/workforce/attendance/corrections/{$asked['id']}/decide", ['approve' => true])->assertForbidden();
        $this->getJson('/api/workforce/attendance/corrections')->assertForbidden();

        // Her department's manager sees it and approves it: now the day exists, and both steps are on record.
        Sanctum::actingAs($sisterLogin);
        $list = $this->getJson('/api/workforce/attendance/corrections')->assertOk()->json();
        $this->assertSame([['Nurse One', true]], array_map(fn ($c) => [$c['employee']['name'], $c['can_decide']], $list));
        $this->postJson("/api/workforce/attendance/corrections/{$asked['id']}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame(480, Attendance::withoutGlobalScopes()->where('employee_id', $nurse->id)->value('worked_minutes'));
        $this->postJson("/api/workforce/attendance/corrections/{$asked['id']}/decide", ['approve' => true])->assertStatus(422);

        // The manager's own correction cannot be approved by the manager; and she cannot touch another department's.
        $own = $this->postJson('/api/workforce/me/corrections', ['work_date' => '2026-03-09', 'check_in' => '2026-03-09 08:00', 'reason' => 'Mine'])->assertCreated()->json();
        $this->postJson("/api/workforce/attendance/corrections/{$own['id']}/decide", ['approve' => true])->assertForbidden();
        $this->postJson('/api/workforce/attendance/corrections', ['employee_id' => $tech->id, 'work_date' => '2026-03-09', 'check_in' => '2026-03-09 09:00', 'reason' => 'Not my department'])->assertForbidden();
        $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $tech->id])->assertForbidden();

        // A refusal needs its reason, and enters nothing.
        Sanctum::actingAs($this->user('hr'));
        $this->postJson("/api/workforce/attendance/corrections/{$own['id']}/decide", ['approve' => false])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/workforce/attendance/corrections/{$own['id']}/decide", ['approve' => false, 'note' => 'The roster shows a day off'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame(0, Attendance::withoutGlobalScopes()->where('employee_id', $sister->id)->count());
        $this->assertSame(['attendance.correction.asked', 'attendance.entered', 'attendance.correction.approved', 'attendance.correction.asked', 'attendance.correction.refused'], AuditEvent::withoutGlobalScopes()->where('action', 'like', 'attendance.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_an_attendance_sheet_is_looked_over_first_and_never_brought_in_twice(): void
    {
        $night = Tenant::run($this->here->id, fn () => Shift::create(['name' => 'Night', 'starts_at' => '20:00', 'ends_at' => '08:00']));
        $asha = $this->employee('Asha Sample', ['employee_no' => 'SH-1']);
        $bhanu = $this->employee('Bhanu Sample', ['employee_no' => 'SH-2', 'shift_id' => $night->id]);
        Sanctum::actingAs($this->user('hr'));

        $sheet = ['file_name' => 'march-week-2.csv', 'rows' => [
            ['employee_no' => 'SH-1', 'date' => '2026-03-09', 'check_in' => '09:00', 'check_out' => '17:30'],
            ['employee_no' => 'sh-1', 'date' => '10/03/2026', 'check_in' => '10/03/2026 09:10', 'check_out' => '10/03/2026 17:00'],
            ['employee_no' => 'SH-2', 'date' => '2026-03-09', 'check_in' => '20:00', 'check_out' => '08:00'],   // a night shift: out the next morning
            ['employee_no' => 'SH-1', 'date' => '2026-03-09', 'check_in' => '09:00', 'check_out' => '17:30'],   // the same line again
            ['employee_no' => 'SH-9', 'date' => '2026-03-09', 'check_in' => '09:00', 'check_out' => '17:00'],   // nobody has this number
            ['employee_no' => 'SH-1', 'date' => '2026-03-08', 'check_in' => '09:00', 'check_out' => ''],        // no check-out
            ['employee_no' => 'SH-1', 'date' => 'ninth of March', 'check_in' => '09:00', 'check_out' => '17:00'],
            ['employee_no' => 'SH-2', 'date' => '2026-03-07', 'check_in' => '2026-03-07 20:00', 'check_out' => '2026-03-07 19:00'],
        ]];
        $preview = $this->postJson('/api/workforce/attendance/imports', $sheet)->assertCreated()->json();
        $this->assertSame(['ready', 'ready', 'ready', 'unmatched', 'missing_out', 'invalid', 'invalid'], array_column($preview['rows'], 'state'));
        // 8h30 + 7h50 + 12h = 28.33 hours ready to come in.
        $this->assertSame([3, 1, 28.33], [$preview['summary']['ready'], $preview['summary']['already_here'], $preview['summary']['hours']]);
        $this->assertSame('2026-03-10 08:00', $preview['rows'][2]['check_out']);
        // Looking it over has entered nothing.
        $this->assertSame(0, Attendance::count());

        $applied = $this->postJson("/api/workforce/attendance/imports/{$preview['id']}/apply")->assertOk()->json();
        $this->assertSame(['applied', 3], [$applied['status'], $applied['summary']['applied']]);
        $this->assertSame([[$asha->id, 510], [$asha->id, 470], [$bhanu->id, 720]], Attendance::orderBy('id')->get()->map(fn ($a) => [$a->employee_id, $a->worked_minutes])->all());
        $this->assertSame(['import', 'import', 'import'], Attendance::pluck('origin')->all());
        $this->postJson("/api/workforce/attendance/imports/{$preview['id']}/apply")->assertStatus(422);

        // The same sheet again: all eight lines are recognised as seen before, and nothing is entered a second time.
        $again = $this->postJson('/api/workforce/attendance/imports', $sheet)->assertCreated()->json();
        $this->assertArrayNotHasKey('ready', $again['summary']);
        $this->assertSame([8, 0], [$again['summary']['already_here'], $again['summary']['lines']]);
        $this->postJson("/api/workforce/attendance/imports/{$again['id']}/apply")->assertOk();
        $this->assertSame(3, Attendance::count());

        // Bringing in sheets is for those who reach all staff.
        Sanctum::actingAs($this->user('dept_manager'));
        $this->postJson('/api/workforce/attendance/imports', $sheet)->assertForbidden();
    }

    public function test_details_written_under_an_earlier_key_are_named_for_typing_again_not_an_error(): void
    {
        $asha = $this->employee('Asha Sample');
        Tenant::run($asha->branch_id, fn () => EmployeePrivate::create(['employee_id' => $asha->id, 'bank_name' => 'Sample Bank', 'pan' => 'ABCDE1234F', 'uan' => '100200300400']));
        // As if the site's key had been changed since: the stored PAN no longer opens.
        DB::table('wf_employee_private')->update(['pan' => (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString('ABCDE1234F')]);
        $admin = $this->user('admin', ['password' => bcrypt('Sample-Passw0rd')]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/workforce/employees/{$asha->id}/private")->assertOk()->assertJsonPath('pan', null)->assertJsonPath('unreadable', ['pan'])->assertJsonPath('uan', '••••••••0400');
        $this->postJson('/api/workforce/confirm-password', ['password' => 'Sample-Passw0rd'])->assertOk();
        $this->putJson("/api/workforce/employees/{$asha->id}/private", ['pan' => 'ABCDE1234F', 'reason' => 'Typed again after the key change'])->assertOk();
        $this->getJson("/api/workforce/employees/{$asha->id}/private")->assertJsonPath('unreadable', [])->assertJsonPath('pan', '••••••234F');
    }
}
