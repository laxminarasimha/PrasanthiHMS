<?php

namespace Tests\Feature;

use App\Models\AdminEntry;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DayClose;
use App\Models\Expense;
use App\Models\User;
use App\Support\PaidOut;
use App\Workforce\Models\Advance;
use App\Workforce\Models\Attendance;
use App\Workforce\Models\Employee;
use App\Workforce\Models\ExpenseCategory;
use App\Workforce\Models\SalaryComponent;
use App\Workforce\Models\SalaryStructure;
use App\Workforce\Support\ExpenseBook;
use App\Workforce\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Expenses with stages and approval, and salaries and advances posted into them once. Made-up staff, headings and
 * amounts throughout.
 */
class WorkforceExpensesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $here;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-05 10:00:00');
        $this->here = Branch::create(['name' => 'Sample Hospital']);
    }

    private function user(string $role, array $more = []): User
    {
        return User::factory()->create($more + ['role' => $role, 'branch_id' => $this->here->id, 'password' => bcrypt('Sample-Passw0rd')]);
    }

    private function heading(string $name, ?float $above, ?float $limit = null): ExpenseCategory
    {
        return Tenant::run($this->here->id, fn () => ExpenseCategory::create(['name' => $name, 'approval_above' => $above, 'approver_limit' => $limit]));
    }

    private function expense(array $more = [])
    {
        return $this->postJson('/api/expenses', $more + ['spent_on' => today()->toDateString(), 'category' => 'Tea and refreshments', 'amount' => 50, 'mode' => 'cash']);
    }

    private function paidOut(): array
    {
        return PaidOut::between(Carbon::today(), Carbon::today());
    }

    public function test_a_bigger_expense_is_approved_by_someone_else_before_it_is_paid_and_only_then_counts(): void
    {
        $repairs = $this->heading('Repairs and maintenance', 5000, 50000);
        $cashier = $this->user('frontdesk');
        $manager = $this->user('manager');
        $accountant = $this->user('accountant');
        $works = AdminEntry::create(['kind' => 'department', 'name' => 'Maintenance']);

        // The cashier's usual entry still goes straight in as paid, and counts at once.
        Sanctum::actingAs($cashier);
        $this->expense()->assertCreated()->assertJsonPath('status', 'paid')->assertJsonPath('paid_on', '2026-05-05');
        $this->assertSame(50.0, $this->paidOut()['cash']);

        // Above the heading's limit it cannot be entered as already paid; it is sent for approval and is not money out yet.
        $this->expense(['category_id' => $repairs->id, 'amount' => 12000, 'tax' => 1830.51, 'department_id' => $works->id, 'paid_to' => 'Sample Electricals', 'bill_no' => 'SE/221'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->expense(['category_id' => $repairs->id, 'amount' => 12000, 'tax' => 13000])->assertStatus(422)->assertJsonValidationErrors('tax');
        $id = $this->expense(['category_id' => $repairs->id, 'amount' => 12000, 'tax' => 1830.51, 'department_id' => $works->id, 'paid_to' => 'Sample Electricals', 'bill_no' => 'SE/221', 'stage' => 'submitted'])
            ->assertCreated()->assertJsonPath('status', 'submitted')->assertJsonPath('category', 'Repairs and maintenance')->assertJsonPath('department.name', 'Maintenance')->json('id');
        $this->assertSame(50.0, $this->paidOut()['total']);
        $this->postJson("/api/expenses/{$id}/pay", ['paid_on' => '2026-05-05', 'mode' => 'cash'])->assertStatus(422);
        $this->postJson("/api/expenses/{$id}/decide", ['approve' => true])->assertForbidden(); // the cashier does not approve

        // The bill is attached and kept privately.
        Storage::fake('local');
        $this->post("/api/expenses/{$id}/receipt", ['file' => UploadedFile::fake()->image('bill.jpg')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('has_receipt', true);
        $this->post("/api/expenses/{$id}/receipt", ['file' => UploadedFile::fake()->create('bill.exe', 10)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->get("/api/expenses/{$id}/receipt")->assertOk();

        // The manager approves; nobody approves their own; the waiting list shows it whatever its date.
        Sanctum::actingAs($manager);
        $this->getJson('/api/expenses?show=waiting')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.can.decide', true)->assertJsonPath('counts.waiting', 1);
        $this->postJson("/api/expenses/{$id}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson("/api/expenses/{$id}/decide", ['approve' => true])->assertStatus(422);

        // Approved but not paid: still not money out. The cashier pays it from the drawer; now it counts, on that day.
        $this->assertSame(50.0, $this->paidOut()['total']);
        Sanctum::actingAs($cashier);
        $this->putJson("/api/expenses/{$id}", ['spent_on' => '2026-05-05', 'category_id' => $repairs->id, 'amount' => 11000, 'mode' => 'cash'])->assertStatus(422); // approved: not changed
        $this->postJson("/api/expenses/{$id}/pay", ['paid_on' => '2026-05-05', 'mode' => 'cash', 'reference' => 'Voucher 7'])->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('payer.id', $cashier->id);
        $this->assertSame(12050.0, $this->paidOut()['cash']);
        $this->assertSame(12000.0, $this->paidOut()['by_category']['Repairs and maintenance']);
        $this->assertSame(['expense.added', 'expense.receipt', 'expense.approved', 'expense.paid'], AuditLog::where('action', 'like', 'expense.%')->where('summary', 'like', '%Repairs%')->pluck('action')->unique()->values()->all());

        // Above the approver's own limit it needs the higher approval: the manager cannot, the accountant can.
        $big = $this->expense(['category_id' => $repairs->id, 'amount' => 80000, 'mode' => 'bank_transfer', 'stage' => 'submitted'])->assertCreated()->assertJsonPath('needs_large_approval', true)->json('id');
        Sanctum::actingAs($manager);
        $this->postJson("/api/expenses/{$big}/decide", ['approve' => true])->assertForbidden();
        Sanctum::actingAs($accountant);
        $this->postJson("/api/expenses/{$big}/decide", ['approve' => false])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/expenses/{$big}/decide", ['approve' => false, 'note' => 'Get a second quotation'])->assertOk()->assertJsonPath('status', 'rejected');

        // A refused one is put right by its author and sent again; it waits once more.
        Sanctum::actingAs($cashier);
        $this->putJson("/api/expenses/{$big}", ['spent_on' => '2026-05-05', 'category_id' => $repairs->id, 'amount' => 62000, 'mode' => 'bank_transfer', 'description' => 'Second quotation'])->assertOk()->assertJsonPath('status', 'draft');
        $this->postJson("/api/expenses/{$big}/submit")->assertOk()->assertJsonPath('status', 'submitted');
    }

    public function test_a_paid_expense_from_an_earlier_day_is_undone_only_with_the_permission_and_never_in_a_closed_day(): void
    {
        $cashier = $this->user('frontdesk');
        Sanctum::actingAs($cashier);
        $old = $this->expense(['spent_on' => '2026-05-04', 'amount' => 300])->assertCreated()->json('id');
        $today = $this->expense(['amount' => 40])->assertCreated()->json('id');

        // Today's mistakes are the cashier's to put right, as before. Yesterday's money is not.
        $this->putJson("/api/expenses/{$today}", ['spent_on' => '2026-05-05', 'category' => 'Tea and refreshments', 'amount' => 45, 'mode' => 'cash'])->assertOk();
        $this->postJson("/api/expenses/{$old}/cancel", ['reason' => 'Entered twice'])->assertForbidden();
        $this->putJson("/api/expenses/{$old}", ['spent_on' => '2026-05-04', 'category' => 'Tea and refreshments', 'amount' => 30, 'mode' => 'cash'])->assertForbidden();
        // Its heading can still be put right: that moves no money.
        $this->putJson("/api/expenses/{$old}", ['spent_on' => '2026-05-04', 'category' => 'Housekeeping and laundry', 'amount' => 300, 'mode' => 'cash'])->assertOk();

        // The admin may reverse it, with a reason, until the day is closed.
        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/expenses/{$old}/cancel", ['reason' => 'Entered twice'])->assertOk();
        $closed = $this->expense(['spent_on' => '2026-05-03', 'amount' => 20])->assertCreated()->json('id');
        DayClose::create(['day' => '2026-05-03', 'closed_by_name' => 'Admin', 'closed_at' => now(), 'expected_cash' => 0, 'counted_cash' => 0, 'variance' => 0, 'snapshot' => []]);
        $this->postJson("/api/expenses/{$closed}/cancel", ['reason' => 'Late'])->assertStatus(423);

        // An approved expense cannot be paid into a closed day either.
        $draft = $this->expense(['spent_on' => '2026-05-03', 'amount' => 60, 'stage' => 'draft'])->assertCreated()->assertJsonPath('status', 'draft')->json('id');
        $this->postJson("/api/expenses/{$draft}/submit")->assertOk()->assertJsonPath('status', 'approved'); // a heading with no approval rule: ready to pay
        $this->postJson("/api/expenses/{$draft}/pay", ['paid_on' => '2026-05-03', 'mode' => 'cash'])->assertStatus(423);
        $this->postJson("/api/expenses/{$draft}/pay", ['paid_on' => '2026-05-05', 'mode' => 'cash'])->assertOk();
    }

    public function test_salaries_and_advances_are_posted_into_expenses_once_and_the_drawer_follows_the_cash(): void
    {
        $admin = $this->user('admin');
        Sanctum::actingAs($admin);
        [$asha, $ravi] = Tenant::run($this->here->id, function () {
            $basic = SalaryComponent::create(['code' => 'BASIC', 'name' => 'Basic pay', 'kind' => 'earning', 'basis' => 'fixed']);
            $people = [];
            foreach (['Asha Sample' => 30000, 'Ravi Sample' => 18000] as $name => $pay) {
                $e = Employee::create(['employee_no' => 'E'.random_int(10000, 99999), 'name' => $name, 'joined_on' => '2025-01-01', 'weekly_off' => ['sun']]);
                SalaryStructure::create(['employee_id' => $e->id, 'effective_from' => '2026-01-01', 'pay_basis' => 'monthly', 'approved_at' => now()])->lines()->create(['component_id' => $basic->id, 'amount' => $pay]);
                for ($d = CarbonImmutable::parse('2026-04-01'); $d->format('Y-m') === '2026-04'; $d = $d->addDay()) {
                    if (! $d->isSunday()) {
                        Attendance::create(['employee_id' => $e->id, 'work_date' => $d->toDateString(), 'check_in' => $d->toDateString().' 09:00:00', 'check_out' => $d->toDateString().' 17:00:00', 'state' => 'completed', 'day_status' => 'present', 'worked_minutes' => 480, 'origin' => 'manual']);
                    }
                }
                $people[] = $e;
            }

            return $people;
        });
        $this->postJson('/api/workforce/confirm-password', ['password' => 'Sample-Passw0rd'])->assertOk();

        // An advance given in cash leaves the drawer the day it is given, under its own heading.
        $advance = $this->postJson('/api/workforce/advances', ['employee_id' => $ravi->id, 'kind' => 'advance', 'amount' => 4000, 'reason' => 'Festival', 'instalments' => 2, 'first_period' => '2026-05'])->assertCreated()->json('id');
        $this->postJson("/api/workforce/advances/{$advance}/decide", ['approve' => true])->assertOk();
        $this->postJson("/api/workforce/advances/{$advance}/issue", ['issued_on' => '2026-05-05', 'payment_mode' => 'cash'])->assertOk();
        $this->assertSame(['cash' => 4000.0, 'advances' => 4000.0], ['cash' => $this->paidOut()['cash'], 'advances' => $this->paidOut()['by_category'][ExpenseBook::ADVANCES]]);

        // April's payroll, finalised, then paid: Asha by bank, Ravi in cash. One posting per payment, never typed again.
        $run = $this->postJson('/api/workforce/payroll/runs', ['period' => '2026-04'])->assertCreated()->json();
        $slip = fn (string $name) => collect($run['payslips'])->firstWhere('employee.name', $name)['id'];
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/step", ['to' => 'reviewed'])->assertOk();
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/step", ['to' => 'approved'])->assertOk();
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/finalise", ['checked' => true])->assertOk();
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/pay", ['payslip_ids' => [$slip('Asha Sample')], 'status' => 'paid', 'paid_on' => '2026-05-05', 'payment_mode' => 'bank', 'payment_reference' => 'NEFT 9'])->assertOk();
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/pay", ['payslip_ids' => [$slip('Ravi Sample')], 'status' => 'paid', 'paid_on' => '2026-05-05', 'payment_mode' => 'cash'])->assertOk()->assertJsonPath('status', 'paid');
        $out = $this->paidOut();
        $this->assertSame([48000.0, 22000.0, 52000.0], [$out['by_category'][ExpenseBook::SALARIES], $out['cash'], $out['expenses']]);
        $this->assertSame(['bank_transfer', 'cash'], Expense::where('source_type', 'payroll_payment')->orderBy('id')->pluck('mode')->all());

        // A paid salary stays paid: it is not recorded twice, and not quietly taken back.
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/pay", ['payslip_ids' => [$slip('Ravi Sample')], 'status' => 'paid', 'paid_on' => '2026-05-05', 'payment_mode' => 'cash'])->assertStatus(422);
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/pay", ['payslip_ids' => [$slip('Ravi Sample')], 'status' => 'held', 'reason' => 'Oops'])->assertStatus(422);

        // Nothing posted by payroll is changed or cancelled from the expenses list, and salaries can no longer be typed in.
        $posted = Expense::where('source_type', 'payroll_payment')->value('id');
        $this->postJson("/api/expenses/{$posted}/cancel", ['reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->putJson("/api/expenses/{$posted}", ['spent_on' => '2026-05-05', 'category' => 'Rent', 'amount' => 1, 'mode' => 'cash'])->assertStatus(422);
        $this->expense(['category' => ExpenseBook::SALARIES, 'amount' => 18000])->assertStatus(422)->assertJsonValidationErrors('category');
        $this->expense(['category' => Expense::OLD_SALARY_HEADING, 'amount' => 18000])->assertStatus(422)->assertJsonValidationErrors('category');
        $this->assertNotContains(Expense::OLD_SALARY_HEADING, $this->getJson('/api/expenses')->assertOk()->json('categories'));

        // Reversed and run again: only what had not been paid already is posted. Asha's pay rose by 500 in the meantime.
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/reverse", ['reason' => 'An incentive was missed'])->assertOk();
        $again = $this->postJson('/api/workforce/payroll/runs', ['period' => '2026-04'])->assertCreated()->json();
        $ashaAgain = collect($again['payslips'])->firstWhere('employee.name', 'Asha Sample')['id'];
        $this->postJson("/api/workforce/payroll/runs/{$again['id']}/payslips/{$ashaAgain}/lines", ['kind' => 'earning', 'name' => 'Incentive', 'amount' => 500, 'note' => 'Missed in the first run'])->assertOk();
        $this->postJson("/api/workforce/payroll/runs/{$again['id']}/step", ['to' => 'reviewed'])->assertOk();
        $this->postJson("/api/workforce/payroll/runs/{$again['id']}/step", ['to' => 'approved'])->assertOk();
        $this->postJson("/api/workforce/payroll/runs/{$again['id']}/finalise", ['checked' => true])->assertOk();
        $ids = collect($again['payslips'])->pluck('id')->all();
        $this->postJson("/api/workforce/payroll/runs/{$again['id']}/pay", ['payslip_ids' => $ids, 'status' => 'paid', 'paid_on' => '2026-05-05', 'payment_mode' => 'bank'])->assertOk();
        $this->assertSame(48500.0, $this->paidOut()['by_category'][ExpenseBook::SALARIES]);
        $this->getJson("/api/workforce/payroll/payslips/{$ashaAgain}")->assertOk()->assertJsonPath('paid_amount', '500.00')->assertJsonPath('already_paid', '30000.00');

        // Ravi hands 1,000 of his advance back in cash: it goes back into the drawer's expected cash.
        $this->postJson("/api/workforce/advances/{$advance}/repay", ['amount' => 1000, 'paid_on' => '2026-05-05', 'mode' => 'cash', 'note' => 'Receipt 3'])->assertOk();
        $this->assertSame(1000.0, $this->paidOut()['staff_repaid_cash']);
        // Cash expected in the drawer: nothing collected from patients, 4,000 advance and 18,000 salary paid out in cash, 1,000 handed back.
        $this->getJson('/api/day-close?day=2026-05-05')->assertOk()->assertJsonPath('expected_cash', -21000)->assertJsonPath('paid_out.staff_repaid_cash', 1000);

        // The summary shows salaries and advances once, apart from the hospital's other spending.
        $this->getJson('/api/expenses/summary?from=2026-05-01&to=2026-05-31')->assertOk()->assertJsonPath('payroll', 52500)->assertJsonPath('total', 52500);
    }

    public function test_headings_repeating_expenses_and_the_export_are_kept_to_those_who_may(): void
    {
        $cashier = $this->user('frontdesk');
        $accountant = $this->user('accountant');

        Sanctum::actingAs($cashier);
        $this->postJson('/api/expenses/headings', ['name' => 'Rent', 'approval_above' => 0])->assertForbidden();

        Sanctum::actingAs($accountant);
        $this->postJson('/api/expenses/headings', ['name' => 'Rent', 'approval_above' => 1000, 'approver_limit' => 500])->assertStatus(422)->assertJsonValidationErrors('approver_limit');
        $rent = $this->postJson('/api/expenses/headings', ['name' => 'Rent', 'approval_above' => 0])->assertCreated()->json('id');
        $this->postJson('/api/expenses/headings', ['name' => ExpenseBook::SALARIES])->assertStatus(422);

        // A repeating expense makes a draft for the month when asked, once, and never pays or counts by itself.
        $this->postJson('/api/expenses/repeating', ['name' => 'Building rent', 'category_id' => $rent, 'paid_to' => 'Sample Landlord', 'mode' => 'bank_transfer', 'day_of_month' => 5])->assertStatus(422)->assertJsonValidationErrors('amount');
        $tpl = $this->postJson('/api/expenses/repeating', ['name' => 'Building rent', 'category_id' => $rent, 'paid_to' => 'Sample Landlord', 'amount' => 45000, 'mode' => 'bank_transfer', 'day_of_month' => 5])->assertCreated()->json('id');
        $this->getJson('/api/expenses/repeating')->assertOk()->assertJsonPath('0.due', true);
        $draft = $this->postJson("/api/expenses/repeating/{$tpl}/use")->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('amount', 45000)->json('id');
        $this->postJson("/api/expenses/repeating/{$tpl}/use")->assertStatus(422);
        $this->getJson('/api/expenses/repeating')->assertJsonPath('0.due', false);
        $this->assertSame(0.0, $this->paidOut()['total']);
        // Under a heading where everything is approved, its own author cannot approve it.
        $this->postJson("/api/expenses/{$draft}/submit")->assertOk()->assertJsonPath('status', 'submitted');
        $this->postJson("/api/expenses/{$draft}/decide", ['approve' => true])->assertForbidden();

        // The export is written down when taken.
        $file = $this->get('/api/expenses/export?from=2026-05-01&to=2026-05-31')->assertOk()->streamedContent();
        $this->assertStringContainsString('Sample Landlord', $file);
        $this->assertSame(1, AuditLog::where('action', 'expense.exported')->count());
    }
}
