<?php

namespace Tests\Feature;

use App\Models\AdminEntry;
use App\Models\Branch;
use App\Models\User;
use App\Workforce\Http\RequireFreshPassword;
use App\Workforce\Models\AdvanceLedgerLine;
use App\Workforce\Models\AuditEvent;
use App\Workforce\Models\DepartmentManager;
use App\Workforce\Models\Employee;
use App\Workforce\Models\EmployeePrivate;
use App\Workforce\Models\LeaveLedgerLine;
use App\Workforce\Models\LeaveType;
use App\Workforce\Models\PayrollRun;
use App\Workforce\Models\Payslip;
use App\Workforce\Models\PayslipLine;
use App\Workforce\Models\SalaryComponent;
use App\Workforce\Models\SourceLink;
use App\Workforce\Support\Access;
use App\Workforce\Support\Audit;
use App\Workforce\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * What the workforce module stands on: the wall between hospitals, the reach of a permission, private details kept
 * encrypted, the record of changes that cannot be quietly altered, and a payroll that is fixed once finalised.
 * Everyone and every number here is made up.
 */
class WorkforceFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $here;

    private Branch $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->here = Branch::create(['name' => 'Sample Hospital']);
        $this->elsewhere = Branch::create(['name' => 'Another Hospital']);
    }

    private function user(string $role, ?Branch $branch = null, array $more = []): User
    {
        return User::factory()->create($more + ['role' => $role, 'branch_id' => ($branch ?? $this->here)->id]);
    }

    private function employee(string $name, array $more = [], ?Branch $branch = null): Employee
    {
        return Tenant::run(($branch ?? $this->here)->id, fn () => Employee::create($more + ['employee_no' => 'E'.random_int(10000, 99999), 'name' => $name]));
    }

    private function department(string $name): AdminEntry
    {
        return AdminEntry::create(['kind' => 'department', 'name' => $name]);
    }

    public function test_one_hospital_never_reads_or_writes_another_hospitals_staff(): void
    {
        $ours = $this->employee('Asha Sample');
        $theirs = $this->employee('Bhanu Sample', [], $this->elsewhere);

        Sanctum::actingAs($this->user('admin'));
        $this->assertSame(['Asha Sample'], Employee::pluck('name')->all());
        $this->assertNull(Employee::find($theirs->id));
        // Made here, it is stamped as ours without being told; it cannot then be moved.
        $made = Employee::create(['employee_no' => 'E1', 'name' => 'Chitra Sample']);
        $this->assertSame($this->here->id, (int) $made->branch_id);
        $this->expectException(LogicException::class);
        $ours->update(['branch_id' => $this->elsewhere->id]);
    }

    public function test_someone_signed_in_without_a_branch_sees_nothing_rather_than_everything(): void
    {
        $this->employee('Asha Sample');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'branch_id' => null]));

        $this->assertSame(0, Employee::count());
        $this->expectException(LogicException::class);
        Employee::create(['employee_no' => 'E2', 'name' => 'No Branch']);
    }

    public function test_a_permission_reaches_all_staff_a_department_or_only_oneself(): void
    {
        $nursing = $this->department('Nursing');
        $lab = $this->department('Laboratory');
        $sister = $this->user('dept_manager');
        Tenant::run($this->here->id, fn () => DepartmentManager::create(['department_id' => $nursing->id, 'user_id' => $sister->id]));
        $this->employee('Nurse One', ['department_id' => $nursing->id]);
        $this->employee('Nurse Two', ['department_id' => $nursing->id]);
        $this->employee('Lab Person', ['department_id' => $lab->id]);
        $this->employee('Sister Herself', ['department_id' => $lab->id, 'user_id' => $sister->id]);
        $this->employee('Other Hospital Nurse', ['department_id' => $nursing->id], $this->elsewhere);

        Sanctum::actingAs($sister);
        // Her department's staff for attendance; only herself for her own records; nobody for pay.
        $this->assertSame(['Nurse One', 'Nurse Two'], Access::employees($sister, 'wf:attendance:view')->orderBy('name')->pluck('name')->all());
        $this->assertSame(['Sister Herself'], Access::employees($sister, 'wf:self:view')->pluck('name')->all());
        $this->assertSame(0, Access::employees($sister, 'wf:salary:view')->count());
        $this->assertFalse(Access::reaches($sister, 'wf:leave:approve', Employee::where('name', 'Lab Person')->first()));

        // HR reaches everyone in the hospital, and still nobody in the other one.
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);
        $this->assertSame(4, Access::employees($hr, 'wf:staff:view')->count());

        // The same is true over the wire: what she is told she may do, and how far.
        Sanctum::actingAs($sister);
        $access = $this->getJson('/api/workforce/access')->assertOk()->json();
        $this->assertSame('department', $access['permissions']['wf:leave:approve']);
        $this->assertSame('self', $access['permissions']['wf:self:view']);
        $this->assertArrayNotHasKey('wf:salary:view', $access['permissions']);
        $this->assertSame([$nursing->id], $access['departments']);
    }

    public function test_bank_and_identity_numbers_are_stored_encrypted_and_never_listed(): void
    {
        $person = $this->employee('Asha Sample');
        Sanctum::actingAs($this->user('admin'));
        $private = EmployeePrivate::create(['employee_id' => $person->id, 'bank_name' => 'Sample Bank', 'bank_account_no' => '123456789012', 'pan' => 'ABCDE1234F', 'aadhaar' => '999988887777']);

        $stored = DB::table('wf_employee_private')->where('id', $private->id)->first();
        foreach (['bank_account_no' => '123456789012', 'pan' => 'ABCDE1234F', 'aadhaar' => '999988887777'] as $field => $plain) {
            $this->assertNotSame($plain, $stored->{$field});
            $this->assertStringNotContainsString($plain, (string) $stored->{$field});
        }
        // Read back through the system it is whole again, but it is left out of anything turned into a list.
        $this->assertSame('123456789012', $private->fresh()->bank_account_no);
        $this->assertArrayNotHasKey('bank_account_no', $private->fresh()->toArray());
        $this->assertArrayNotHasKey('private', $person->fresh()->toArray());
        $this->assertSame('••••••••9012', EmployeePrivate::masked('123456789012'));
    }

    public function test_the_record_of_changes_is_only_added_to_and_shows_if_it_was_tampered_with(): void
    {
        $person = $this->employee('Asha Sample');
        $admin = $this->user('admin', null, ['name' => 'Owner Sample']);
        Sanctum::actingAs($admin);

        [$before, $after] = Audit::difference(['designation' => 'Nurse', 'phone' => '9000000001'], ['designation' => 'Senior Nurse', 'phone' => '9000000001']);
        Audit::record('staff.updated', $person, $before, $after, 'Promotion', $person->id);
        Audit::record('staff.bank.updated', $person, ['bank_account_no' => '111122223333'], ['bank_account_no' => '444455556666', 'bank' => ['pan' => 'ABCDE1234F']], 'New account', $person->id);
        $first = AuditEvent::orderBy('id')->first();

        $this->assertSame(['designation' => 'Nurse'], $first->before);
        $this->assertSame(['Owner Sample', 'Promotion', $this->here->id], [$first->actor_name, $first->reason, (int) $first->branch_id]);
        // A bank or identity number is never written out in full, however deep it sits.
        $second = AuditEvent::orderByDesc('id')->first();
        $this->assertSame('••••••••6666', $second->after['bank_account_no']);
        $this->assertSame('••••••234F', $second->after['bank']['pan']);
        $this->assertStringNotContainsString('444455556666', json_encode($second->toArray()));
        $this->assertSame(['ok' => true, 'lines' => 2, 'broken_at' => null], Audit::verify($this->here->id));

        // Through the system a line cannot be changed or removed.
        foreach ([fn () => $first->update(['reason' => 'Something else']), fn () => $first->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('A line of the record was changed or removed.');
            } catch (LogicException) {
                $this->assertSame('Promotion', $first->fresh()->reason);
            }
        }
        // Changed behind the system's back, the check says which line no longer matches.
        DB::table('wf_audit_events')->where('id', $first->id)->update(['reason' => 'Edited in the database']);
        $this->assertSame(['ok' => false, 'lines' => 1, 'broken_at' => $first->id], Audit::verify($this->here->id));

        // Seeing the record needs its own permission, which HR does not have.
        $this->getJson('/api/workforce/audit')->assertOk()->assertJsonPath('total', 2);
        Sanctum::actingAs($this->user('hr'));
        $this->getJson('/api/workforce/audit')->assertForbidden();
    }

    public function test_a_person_has_one_standing_payslip_a_month_and_a_finalised_payroll_is_fixed(): void
    {
        $person = $this->employee('Asha Sample');
        Sanctum::actingAs($this->user('admin'));
        $run = PayrollRun::create(['period' => '2026-10', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
        $slip = Payslip::create(['run_id' => $run->id, 'employee_id' => $person->id, 'period' => '2026-10', 'gross' => 20000, 'deductions' => 1500, 'net' => 18500]);
        PayslipLine::create(['payslip_id' => $slip->id, 'code' => 'BASIC', 'name' => 'Basic pay', 'kind' => 'earning', 'amount' => 20000, 'explanation' => 'Monthly basic pay of 20,000.00 for 31 of 31 days.']);

        // A second payslip for the same person and month is refused by the database itself, whatever run it is in.
        $again = PayrollRun::create(['period' => '2026-10', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
        try {
            Payslip::create(['run_id' => $again->id, 'employee_id' => $person->id, 'period' => '2026-10', 'net' => 18500]);
            $this->fail('A second payslip was made for the same month.');
        } catch (QueryException) {
            $this->assertSame(1, Payslip::where('employee_id', $person->id)->count());
        }

        // Until it is finalised a draft can be corrected. After that, no figure and no line changes.
        $slip->update(['net' => 18400]);
        $run->update(['status' => 'finalised', 'finalised_at' => now()]);
        $refused = [
            fn () => $run->fresh()->update(['net' => 1]),
            fn () => $run->fresh()->update(['status' => 'draft']),
            fn () => $run->fresh()->delete(),
            fn () => $slip->fresh()->update(['net' => 1]),
            fn () => $slip->fresh()->delete(),
            fn () => $slip->lines()->first()->update(['amount' => 1]),
            fn () => PayslipLine::create(['payslip_id' => $slip->id, 'code' => 'BONUS', 'name' => 'Bonus', 'kind' => 'earning', 'amount' => 500, 'explanation' => 'Added later.']),
        ];
        foreach ($refused as $attempt) {
            try {
                $attempt();
                $this->fail('A finalised payroll was changed.');
            } catch (LogicException) {
                $this->assertSame('18400.00', $slip->fresh()->net);
            }
        }
        $this->assertSame(1, $slip->lines()->count());

        // What is still recorded on it: that the salary was paid, and by what means. Nothing is sent to any bank.
        $slip->fresh()->update(['payment_status' => 'paid', 'paid_on' => '2026-11-01', 'payment_mode' => 'bank', 'payment_reference' => 'SAMPLE-REF-1']);
        $run->fresh()->update(['status' => 'paid']);

        // Put right by reversing: the old payslip stays on record, and the month is free for a corrected run.
        $run->fresh()->update(['status' => 'reversed', 'reverse_reason' => 'Wrong number of days', 'reversed_at' => now()]);
        $slip->fresh()->update(['live_period' => null]);
        $corrected = Payslip::create(['run_id' => $again->id, 'employee_id' => $person->id, 'period' => '2026-10', 'net' => 17900]);
        $this->assertSame(['2026-10', null], [$corrected->live_period, $slip->fresh()->live_period]);
        $this->assertSame(2, Payslip::where('employee_id', $person->id)->count());
    }

    public function test_ledgers_are_only_added_to_and_nothing_is_brought_in_twice(): void
    {
        $person = $this->employee('Asha Sample');
        Sanctum::actingAs($this->user('admin'));
        $casual = LeaveType::create(['code' => 'CL', 'name' => 'Casual leave', 'annual_allowance' => 12]);
        $line = LeaveLedgerLine::create(['employee_id' => $person->id, 'leave_type_id' => $casual->id, 'entry_date' => '2026-01-01', 'leave_year' => 2026, 'kind' => 'accrual', 'days' => 12, 'once_key' => 'accrual:CL:2026', 'created_at' => now()]);
        LeaveLedgerLine::create(['employee_id' => $person->id, 'leave_type_id' => $casual->id, 'entry_date' => '2026-02-10', 'leave_year' => 2026, 'kind' => 'taken', 'days' => -1.5, 'created_at' => now()]);

        // The balance is the sum of the lines; a line cannot be typed over.
        $this->assertSame(10.5, (float) LeaveLedgerLine::where('employee_id', $person->id)->sum('days'));
        try {
            $line->update(['days' => 20]);
            $this->fail('A ledger line was changed.');
        } catch (LogicException) {
            $this->assertSame(12.0, $line->fresh()->days);
        }
        // The same yearly allowance cannot be written twice.
        $this->expectException(QueryException::class);
        LeaveLedgerLine::create(['employee_id' => $person->id, 'leave_type_id' => $casual->id, 'entry_date' => '2026-01-01', 'leave_year' => 2026, 'kind' => 'accrual', 'days' => 12, 'once_key' => 'accrual:CL:2026', 'created_at' => now()]);
    }

    public function test_a_source_record_links_to_one_record_here_only(): void
    {
        $person = $this->employee('Asha Sample');
        Sanctum::actingAs($this->user('admin'));
        SourceLink::create(['source' => 'wordpress', 'entity' => 'staff', 'source_id' => 'PH-EMP-000001', 'target_type' => 'Employee', 'target_id' => $person->id]);

        $this->assertSame($person->id, (int) SourceLink::where(['source' => 'wordpress', 'entity' => 'staff', 'source_id' => 'PH-EMP-000001'])->value('target_id'));
        // The other hospital may bring in a record with the same number of its own.
        Tenant::run($this->elsewhere->id, fn () => SourceLink::create(['source' => 'wordpress', 'entity' => 'staff', 'source_id' => 'PH-EMP-000001', 'target_type' => 'Employee', 'target_id' => 999]));
        $this->expectException(QueryException::class);
        SourceLink::create(['source' => 'wordpress', 'entity' => 'staff', 'source_id' => 'PH-EMP-000001', 'target_type' => 'Employee', 'target_id' => $person->id]);
    }

    public function test_a_sensitive_step_needs_the_password_typed_again(): void
    {
        Route::middleware(['api', 'auth:sanctum', RequireFreshPassword::class])->post('/api/_test/sensitive-step', fn () => response()->json(['done' => true]));
        $user = $this->user('admin', null, ['password' => bcrypt('Sample-Passw0rd')]);
        Sanctum::actingAs($user);

        $this->postJson('/api/_test/sensitive-step')->assertStatus(428);
        $this->postJson('/api/workforce/confirm-password', ['password' => 'not-the-password'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/_test/sensitive-step')->assertStatus(428);
        $this->assertSame(1, AuditEvent::where('action', 'security.password_refused')->count());

        $this->postJson('/api/workforce/confirm-password', ['password' => 'Sample-Passw0rd'])->assertOk()->assertJsonPath('fresh_for_minutes', 10);
        $this->postJson('/api/_test/sensitive-step')->assertOk();
        // It lapses by itself.
        $this->travel(11)->minutes();
        $this->postJson('/api/_test/sensitive-step')->assertStatus(428);
    }

    public function test_no_statutory_rate_or_salary_part_is_invented_by_the_system(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $this->assertSame(0, SalaryComponent::count());
        $this->assertSame(0, AdvanceLedgerLine::count());
        // A statutory part, when the accountant adds one, starts with no rule until they enter it.
        $pf = SalaryComponent::create(['code' => 'PF', 'name' => 'Provident fund', 'kind' => 'deduction', 'is_statutory' => true, 'is_enabled' => false]);
        $this->assertNull($pf->fresh()->rule);
        $this->assertFalse($pf->fresh()->is_enabled);
    }
}
