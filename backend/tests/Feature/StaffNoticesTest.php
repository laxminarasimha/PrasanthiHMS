<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PushSubscription;
use App\Models\Stockist;
use App\Models\User;
use App\Support\PhoneNotice;
use App\Support\WebPush;
use App\Workforce\Models\Employee;
use App\Workforce\Models\ExpenseCategory;
use App\Workforce\Models\LeaveType;
use App\Workforce\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakePhone;
use Tests\TestCase;

/**
 * The phone notices for staff other than doctors: a task given, leave decided, an expense decided or ready to pay, a
 * stock count handed in and a discharge summary to approve. Each reaches only the person it is for, never the one who did it,
 * and carries no amount, no reason or note and nothing clinical. Made-up staff, patients and figures throughout.
 */
class StaffNoticesTest extends TestCase
{
    use FakePhone, RefreshDatabase;

    private Branch $here;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-03-11 10:00:00'); // a Wednesday
        $this->here = Branch::create(['name' => 'Sample Hospital']);
        Http::fake(['*' => Http::response('', 201)]);
    }

    private function user(string $role, array $more = []): User
    {
        return User::factory()->create($more + ['role' => $role, 'branch_id' => $this->here->id]);
    }

    /** The person, with notifications switched on for a phone of their own. They are left signed in. */
    private function withPhone(User $user): User
    {
        $this->switchOn($user, $this->phoneOf($user));

        return $user;
    }

    private function phoneOf(User $user): string
    {
        return 'https://fcm.googleapis.com/fcm/send/sample-phone-'.$user->id;
    }

    /** @return list<array<string, mixed>> */
    private function told(User $user): array
    {
        return $this->sentTo($this->phoneOf($user));
    }

    private function employee(string $name, array $more = []): Employee
    {
        return Tenant::run($this->here->id, fn () => Employee::create($more + ['employee_no' => 'E'.random_int(10000, 99999), 'name' => $name, 'joined_on' => '2025-01-01', 'weekly_off' => ['sun']]));
    }

    private function assertNothingPrivate(array $notices, array $words): void
    {
        foreach ($notices as $notice) {
            foreach ($words as $word) {
                $this->assertStringNotContainsString($word, $notice['title'].' '.$notice['body']);
            }
        }
    }

    public function test_each_login_is_offered_only_the_notices_that_can_reach_it(): void
    {
        $kinds = fn (string $role, array $more = []) => PhoneNotice::kindsFor($this->user($role, $more));

        $this->assertSame(['task', 'leave', 'expense', 'stock_count', 'summary', 'test'], $kinds('admin'));
        $this->assertSame(['appointment', 'lab', 'rep', 'task', 'leave', 'expense', 'summary', 'test'], $kinds('doctor'));
        $this->assertSame(['task', 'leave', 'test'], $kinds('nurse'));
        $this->assertSame(['task', 'leave', 'expense', 'test'], $kinds('frontdesk'));
        $this->assertSame(['task', 'leave', 'expense', 'test'], $kinds('accountant'));
        $this->assertSame(['task', 'leave', 'expense', 'test'], $kinds('manager'));
        // Stock counts reach those given the audit by name who can open the pharmacy, never through a role alone.
        $this->assertSame(['task', 'leave', 'expense', 'stock_count', 'test'], $kinds('manager', ['permissions' => ['canAuditPharmacy' => true]]));
        $this->assertSame(['task', 'leave', 'stock_count', 'test'], $kinds('pharmacist', ['permissions' => ['canAuditPharmacy' => true]]));
        $this->assertSame(['task', 'leave', 'test'], $kinds('nurse', ['permissions' => ['canAuditPharmacy' => true]]));
        // Summaries reach whoever may approve them and work in the IPD.
        $this->assertSame(['task', 'leave', 'expense', 'summary', 'test'], $kinds('manager', ['permissions' => ['canApproveIpd' => true]]));
        $this->assertSame(['appointment', 'lab', 'rep', 'task', 'leave', 'expense', 'test'], $kinds('doctor', ['permissions' => ['canWorkIpd' => false]]));
        // Someone who may not ask for leave is not told about it either, nor someone who may not open their own record,
        // where the notice would take them.
        $this->assertSame(['task', 'test'], $kinds('nurse', ['permissions' => ['wf:self:create' => false]]));
        $this->assertSame(['task', 'test'], $kinds('nurse', ['permissions' => ['wf:self:view' => false]]));

        // Never offered anything: an own-record login, a doctor only on the lists, a wall screen, a closed login.
        $this->assertSame([], $kinds('staff'));
        $this->assertSame([], $kinds('doctor', ['sign_in_with' => 'none']));
        $this->assertSame([], $kinds('frontdesk', ['is_shared_screen' => true]));
        $this->assertSame([], $kinds('nurse', ['is_active' => false]));

        // Every kind offered is one the screens know.
        foreach (['admin', 'doctor', 'nurse', 'frontdesk', 'pharmacist', 'lab_tech', 'hr', 'dept_manager'] as $role) {
            $this->assertSame([], array_diff($kinds($role), PhoneNotice::KINDS));
        }
    }

    public function test_the_signed_in_person_is_told_which_notices_they_can_get_and_an_own_record_login_gets_none(): void
    {
        Sanctum::actingAs($this->user('nurse'));
        $this->getJson('/api/me')->assertOk()->assertJsonPath('notices', ['task', 'leave', 'test']);

        $ward = $this->user('staff', ['email' => null, 'phone' => '9000000001', 'password' => Hash::make('482915'), 'sign_in_with' => 'pin']);
        Sanctum::actingAs($ward);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('notices', []);
        // Their login still reaches nothing of the notices.
        $this->getJson('/api/me/push/key')->assertForbidden();
        $this->postJson('/api/me/push', ['endpoint' => $this->phoneOf($ward), 'keys' => ['p256dh' => $this->phoneKey, 'auth' => $this->phoneSecret]])->assertForbidden();
        $this->assertSame(0, PushSubscription::count());

        // Even a device noted for them some other way is sent nothing.
        PushSubscription::create(['user_id' => $ward->id, 'endpoint' => $this->phoneOf($ward), 'endpoint_hash' => PushSubscription::hashOf($this->phoneOf($ward)), 'p256dh' => $this->phoneKey, 'auth' => $this->phoneSecret]);
        $this->assertSame(0, PhoneNotice::now($ward->id, ['title' => 'Sample', 'kind' => PhoneNotice::LEAVE]));
        Http::assertNothingSent();
    }

    public function test_a_task_given_by_someone_else_reaches_them_and_never_the_giver(): void
    {
        $manager = $this->withPhone($this->user('manager', ['name' => 'Sample Manager']));
        $nurse = $this->withPhone($this->user('nurse'));

        Sanctum::actingAs($manager);
        $tasks = $this->postJson('/api/tasks', ['title' => 'Count the linen', 'due_on' => today()->toDateString(), 'for' => [$nurse->id, $manager->id]])->assertCreated()->json();
        $this->assertSame([[
            'title' => 'New task from Sample Manager',
            'body' => 'Count the linen · by 11 Mar',
            'url' => '/todo',
            'tag' => 'task-'.collect($tasks)->firstWhere('assigned_to', $nurse->id)['id'],
            'kind' => 'task',
        ]], $this->told($nurse));
        $this->assertSame([], $this->told($manager));

        // A task written for oneself is silent.
        Sanctum::actingAs($nurse);
        $this->postJson('/api/tasks', ['title' => 'Check the oxygen cylinders'])->assertCreated();
        Http::assertSentCount(1);
    }

    public function test_the_doctor_is_told_a_representative_is_waiting_as_before(): void
    {
        $doctor = $this->withPhone($this->user('doctor'));
        Sanctum::actingAs($this->user('frontdesk'));
        $rep = $this->postJson('/api/reps/people', ['name' => 'Sample Rep One', 'company' => 'Sample Pharma', 'mobile' => '9000011111'])->assertCreated()->json('id');
        $visit = $this->postJson('/api/reps/visits', ['medical_rep_id' => $rep, 'doctor_id' => $doctor->id])->assertCreated()->json('id');

        $this->assertSame([['title' => 'Medical representative waiting', 'body' => 'Sample Rep One · Sample Pharma', 'url' => '/reps', 'tag' => 'rep-visit-'.$visit, 'kind' => 'rep']], $this->told($doctor));

        // The doctor logging a representative for themself already knows.
        Sanctum::actingAs($doctor);
        $second = $this->postJson('/api/reps/people', ['name' => 'Sample Rep Two', 'company' => 'Sample Pharma', 'mobile' => '9000022222'])->assertCreated()->json('id');
        $this->postJson('/api/reps/visits', ['medical_rep_id' => $second, 'doctor_id' => $doctor->id])->assertCreated();
        Http::assertSentCount(1);
    }

    public function test_leave_decided_by_someone_else_reaches_the_person_without_the_kind_the_reason_or_the_note(): void
    {
        $casual = Tenant::run($this->here->id, fn () => LeaveType::create(['code' => 'CL', 'name' => 'Casual leave', 'annual_allowance' => 12]));
        $nurseLogin = $this->withPhone($this->user('nurse'));
        $this->employee('Nurse One', ['user_id' => $nurseLogin->id]);
        $manager = $this->withPhone($this->user('manager', ['name' => 'Sample Manager']));
        Sanctum::actingAs($this->user('hr'));
        $this->postJson('/api/workforce/leave/housekeeping', ['step' => 'accrue', 'year' => 2026])->assertOk();

        // She asks for Friday afternoon to Monday. Asking tells nobody; the manager approving tells her.
        Sanctum::actingAs($nurseLogin);
        $asked = $this->postJson('/api/workforce/me/leave', ['leave_type_id' => $casual->id, 'from_date' => '2026-03-13', 'to_date' => '2026-03-16', 'from_part' => 'second_half', 'reason' => 'Family function'])
            ->assertCreated()->json('id');
        Http::assertNothingSent();
        Sanctum::actingAs($manager);
        $this->postJson("/api/workforce/leave/{$asked}/decide", ['approve' => true])->assertOk();
        $this->assertSame([[
            'title' => 'Leave approved',
            'body' => '13 Mar to 16 Mar · 2.5 days · decided by Sample Manager',
            'url' => '/workforce/me',
            'tag' => 'leave-'.$asked,
            'kind' => 'leave',
        ]], $this->told($nurseLogin));
        $this->assertSame([], $this->told($manager));

        // Cancelled by whoever may decide it: told again, under the same mark, so it takes the place of the first.
        $this->postJson("/api/workforce/leave/{$asked}/cancel", ['reason' => 'Recalled to duty'])->assertOk();
        $this->assertSame(['Leave cancelled', '13 Mar to 16 Mar · 2.5 days · cancelled by Sample Manager', 'leave-'.$asked], [$this->told($nurseLogin)[1]['title'], $this->told($nurseLogin)[1]['body'], $this->told($nurseLogin)[1]['tag']]);

        // Refused: told that it was not approved, and not why.
        Sanctum::actingAs($nurseLogin);
        $second = $this->postJson('/api/workforce/me/leave', ['leave_type_id' => $casual->id, 'from_date' => '2026-03-18', 'to_date' => '2026-03-18'])->assertCreated()->json('id');
        Sanctum::actingAs($manager);
        $this->postJson("/api/workforce/leave/{$second}/decide", ['approve' => false, 'note' => 'Ward is short that day'])->assertOk();
        $this->assertSame(['Leave not approved', '18 Mar · 1 day · decided by Sample Manager', '/workforce/me'], [$this->told($nurseLogin)[2]['title'], $this->told($nurseLogin)[2]['body'], $this->told($nurseLogin)[2]['url']]);

        $this->assertCount(3, $this->told($nurseLogin));
        $this->assertNothingPrivate($this->told($nurseLogin), ['Casual', 'CL', 'Family function', 'Recalled', 'Ward is short']);
    }

    public function test_leave_entered_already_approved_tells_the_person_and_nobody_is_told_for_staff_without_a_login(): void
    {
        $duty = Tenant::run($this->here->id, fn () => LeaveType::create(['code' => 'DL', 'name' => 'Duty leave', 'annual_allowance' => 0, 'needs_approval' => false]));
        $casual = Tenant::run($this->here->id, fn () => LeaveType::create(['code' => 'CL', 'name' => 'Casual leave', 'annual_allowance' => 12]));
        $nurseLogin = $this->withPhone($this->user('nurse'));
        $nurse = $this->employee('Nurse One', ['user_id' => $nurseLogin->id]);
        $helper = $this->employee('Sample Ward Helper');
        $hr = $this->withPhone($this->user('hr', ['name' => 'Sample HR']));
        $manager = $this->withPhone($this->user('manager'));
        Sanctum::actingAs($hr);
        $this->postJson('/api/workforce/leave/housekeeping', ['step' => 'accrue', 'year' => 2026])->assertOk();

        // HR enters a kind of leave that needs no approval: it is hers at once, and she is told.
        $entered = $this->postJson('/api/workforce/leave', ['employee_id' => $nurse->id, 'leave_type_id' => $duty->id, 'from_date' => '2026-03-18', 'to_date' => '2026-03-18'])
            ->assertCreated()->assertJsonPath('status', 'approved')->json('id');
        $this->assertSame([['title' => 'Leave approved', 'body' => '18 Mar · 1 day · decided by Sample HR', 'url' => '/workforce/me', 'tag' => 'leave-'.$entered, 'kind' => 'leave']], $this->told($nurseLogin));

        // A member of staff with no login of their own: nobody is told, not even whoever entered it.
        $this->postJson('/api/workforce/leave', ['employee_id' => $helper->id, 'leave_type_id' => $duty->id, 'from_date' => '2026-03-19', 'to_date' => '2026-03-19'])->assertCreated()->assertJsonPath('status', 'approved');
        $waiting = $this->postJson('/api/workforce/leave', ['employee_id' => $helper->id, 'leave_type_id' => $casual->id, 'from_date' => '2026-03-20', 'to_date' => '2026-03-20'])->assertCreated()->json('id');
        Sanctum::actingAs($manager);
        $this->postJson("/api/workforce/leave/{$waiting}/decide", ['approve' => true])->assertOk();

        // Her own request that needs no approval: she did it herself.
        Sanctum::actingAs($nurseLogin);
        $this->postJson('/api/workforce/me/leave', ['leave_type_id' => $duty->id, 'from_date' => '2026-03-23', 'to_date' => '2026-03-23'])->assertCreated()->assertJsonPath('status', 'approved');

        Http::assertSentCount(1);
        $this->assertSame([], $this->told($hr));
        $this->assertSame([], $this->told($manager));
    }

    public function test_an_expense_decided_reaches_whoever_entered_or_sent_it_without_the_money(): void
    {
        $repairs = Tenant::run($this->here->id, fn () => ExpenseCategory::create(['name' => 'Repairs and maintenance', 'approval_above' => 5000]));
        $cashier = $this->withPhone($this->user('frontdesk'));
        $manager = $this->withPhone($this->user('manager', ['name' => 'Sample Manager']));
        $accountant = $this->withPhone($this->user('accountant'));
        $admin = $this->withPhone($this->user('admin', ['name' => 'Sample Owner']));
        $expense = fn (array $more) => $this->postJson('/api/expenses', $more + ['spent_on' => today()->toDateString(), 'category_id' => $repairs->id, 'amount' => 12000, 'mode' => 'cash', 'paid_to' => 'Sample Electricals', 'description' => 'Generator repair']);

        // Sent for approval: nobody is told yet. Approved: the cashier who entered and sent it is told it can be paid.
        Sanctum::actingAs($cashier);
        $first = $expense(['stage' => 'submitted'])->assertCreated()->json();
        Http::assertNothingSent();
        Sanctum::actingAs($manager);
        $this->postJson("/api/expenses/{$first['id']}/decide", ['approve' => true])->assertOk();
        $this->assertSame([[
            'title' => 'Expense approved',
            'body' => $first['expense_no'].' · Repairs and maintenance · decided by Sample Manager',
            'url' => '/expenses?show=to_pay',
            'tag' => 'expense-'.$first['id'],
            'kind' => 'expense',
        ]], $this->told($cashier));

        // The cashier's draft, sent on by the accountant: both are told it was turned down, each once, and not why.
        Sanctum::actingAs($cashier);
        $draft = $expense(['stage' => 'draft', 'amount' => 8000])->assertCreated()->json();
        Sanctum::actingAs($accountant);
        $this->postJson("/api/expenses/{$draft['id']}/submit")->assertOk()->assertJsonPath('status', 'submitted');
        Sanctum::actingAs($manager);
        $this->postJson("/api/expenses/{$draft['id']}/decide", ['approve' => false, 'note' => 'Get a second quotation'])->assertOk();
        $refused = ['title' => 'Expense not approved', 'body' => $draft['expense_no'].' · Repairs and maintenance · decided by Sample Manager', 'url' => '/expenses?show=drafts', 'tag' => 'expense-'.$draft['id'], 'kind' => 'expense'];
        $this->assertSame($refused, $this->told($cashier)[1]);
        $this->assertSame([$refused], $this->told($accountant));

        // The admin sends on the cashier's draft and approves it: the cashier is told, the admin did it all himself.
        Sanctum::actingAs($cashier);
        $third = $expense(['stage' => 'draft', 'amount' => 6000])->assertCreated()->json();
        Sanctum::actingAs($admin);
        $this->postJson("/api/expenses/{$third['id']}/submit")->assertOk();
        $this->postJson("/api/expenses/{$third['id']}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame(['Expense approved', $third['expense_no'].' · Repairs and maintenance · decided by Sample Owner', 'expense-'.$third['id']], [$this->told($cashier)[2]['title'], $this->told($cashier)[2]['body'], $this->told($cashier)[2]['tag']]);

        $this->assertCount(3, $this->told($cashier));
        $this->assertSame([], $this->told($manager));
        $this->assertSame([], $this->told($admin));
        $this->assertNothingPrivate([...$this->told($cashier), ...$this->told($accountant)], ['₹', '12000', '12,000', '8000', '6000', 'Sample Electricals', 'Generator', 'quotation']);
    }

    public function test_a_draft_sent_on_that_needs_no_approval_tells_whoever_entered_it_that_it_can_be_paid(): void
    {
        $repairs = Tenant::run($this->here->id, fn () => ExpenseCategory::create(['name' => 'Repairs and maintenance', 'approval_above' => 5000]));
        $cashier = $this->withPhone($this->user('frontdesk'));
        $accountant = $this->withPhone($this->user('accountant', ['name' => 'Sample Accountant']));
        $draft = fn (array $more = []) => $this->postJson('/api/expenses', $more + ['spent_on' => today()->toDateString(), 'category_id' => $repairs->id, 'amount' => 1000, 'mode' => 'cash', 'paid_to' => 'Sample Hardware', 'description' => 'Door hinges', 'stage' => 'draft'])
            ->assertCreated()->json();

        // The cashier's draft is under the heading's limit. The accountant sends it on: it is ready to pay at once, and
        // the cashier is told so.
        Sanctum::actingAs($cashier);
        $first = $draft();
        Sanctum::actingAs($accountant);
        $this->postJson("/api/expenses/{$first['id']}/submit")->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame([[
            'title' => 'Expense ready to pay',
            'body' => $first['expense_no'].' · Repairs and maintenance · needs no approval, sent by Sample Accountant',
            'url' => '/expenses?show=to_pay',
            'tag' => 'expense-'.$first['id'],
            'kind' => 'expense',
        ]], $this->told($cashier));

        // A heading typed by hand never asks for approval: told under the words that were typed.
        Sanctum::actingAs($cashier);
        $typed = $draft(['category_id' => null, 'category' => 'Sample typed heading', 'amount' => 300]);
        Sanctum::actingAs($accountant);
        $this->postJson("/api/expenses/{$typed['id']}/submit")->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame($typed['expense_no'].' · Sample typed heading · needs no approval, sent by Sample Accountant', $this->told($cashier)[1]['body']);

        // Above the limit it waits for approval: nobody is told until it is decided.
        Sanctum::actingAs($cashier);
        $large = $draft(['amount' => 8000]);
        Sanctum::actingAs($accountant);
        $this->postJson("/api/expenses/{$large['id']}/submit")->assertOk()->assertJsonPath('status', 'submitted');

        // Whoever sends on a draft they entered themself already knows: the cashier's own, then the accountant's own.
        Sanctum::actingAs($cashier);
        $this->postJson('/api/expenses/'.$draft()['id'].'/submit')->assertOk()->assertJsonPath('status', 'approved');
        Sanctum::actingAs($accountant);
        $this->postJson('/api/expenses/'.$draft()['id'].'/submit')->assertOk()->assertJsonPath('status', 'approved');

        $this->assertCount(2, $this->told($cashier));
        $this->assertSame([], $this->told($accountant));
        $this->assertNothingPrivate($this->told($cashier), ['₹', '1000', '1,000', '300', 'Sample Hardware', 'Door hinges']);
    }

    public function test_a_notice_that_fails_never_turns_the_decision_into_an_error(): void
    {
        $cashier = $this->withPhone($this->user('frontdesk'));
        $manager = $this->user('manager', ['name' => 'Sample Manager']);

        // A heading typed by hand, with no heading set up behind it: still told, under the words that were typed.
        Sanctum::actingAs($cashier);
        $typed = $this->postJson('/api/expenses', ['spent_on' => today()->toDateString(), 'category' => 'Sample typed heading', 'amount' => 900, 'mode' => 'cash', 'stage' => 'submitted'])->assertCreated()->json();
        $this->assertNull($typed['category_id']);
        Sanctum::actingAs($manager);
        $this->postJson("/api/expenses/{$typed['id']}/decide", ['approve' => true])->assertOk();
        $this->assertSame($typed['expense_no'].' · Sample typed heading · decided by Sample Manager', $this->told($cashier)[0]['body']);

        // Something inside the notice goes wrong while the decision is being saved: it is reported, and the decision stands.
        Sanctum::actingAs($cashier);
        $next = $this->postJson('/api/expenses', ['spent_on' => today()->toDateString(), 'category' => 'Sample typed heading', 'amount' => 700, 'mode' => 'cash', 'stage' => 'submitted'])->assertCreated()->json('id');
        Exceptions::fake();
        Schema::drop('push_subscriptions');
        Sanctum::actingAs($manager);
        $this->postJson("/api/expenses/{$next}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'approved');
        Exceptions::assertReported(QueryException::class);
        $this->assertSame('approved', Expense::find($next)->status);
    }

    public function test_a_stock_count_handed_in_reaches_those_given_the_audit_by_name_and_never_the_counter(): void
    {
        $counter = $this->withPhone($this->user('pharmacist', ['name' => 'Sample Counter']));
        $admin = $this->withPhone($this->user('admin'));
        $pharmacist = $this->withPhone($this->user('pharmacist', ['permissions' => ['canAuditPharmacy' => true]]));
        $manager = $this->withPhone($this->user('manager', ['permissions' => ['canAuditPharmacy' => true]]));
        $plainManager = $this->withPhone($this->user('manager'));
        $doctor = $this->withPhone($this->user('doctor')); // holds the audit through the role only
        $nurse = $this->withPhone($this->user('nurse', ['permissions' => ['canAuditPharmacy' => true]])); // cannot open the pharmacy

        Sanctum::actingAs($counter);
        $medicine = Medicine::create(['name' => 'Paracetamol 650mg Tablet', 'gst_percent' => 12, 'units_per_pack' => 10, 'reorder_level' => 10]);
        $this->postJson('/api/pharmacy/purchases', [
            'stockist_id' => Stockist::create(['name' => 'Sample Distributors'])->id, 'invoice_no' => 'INV-P001', 'invoice_date' => now()->toDateString(),
            'items' => [['medicine_id' => $medicine->id, 'batch_no' => 'P001', 'expiry' => now()->addYear()->format('Y-m'), 'units_per_pack' => 10, 'packs' => 10, 'pack_price' => 15, 'pack_mrp' => 28, 'gst_percent' => 12, 'rack_no' => 'A']],
        ])->assertCreated();
        $count = $this->postJson('/api/pharmacy/audit/counts', ['scope' => 'all'])->assertCreated()->json();
        $this->putJson("/api/pharmacy/audit/counts/{$count['id']}/lines/{$count['rows'][0]['id']}", ['counted_units' => 97])->assertOk();
        Http::assertNothingSent();
        $this->postJson("/api/pharmacy/audit/counts/{$count['id']}/submit")->assertOk()->assertJsonPath('status', 'submitted');

        $handedIn = [[
            'title' => 'Stock count handed in',
            'body' => $count['count_no'].' · Whole pharmacy · by Sample Counter',
            'url' => '/pharmacy/audit/counts/'.$count['id'],
            'tag' => 'stock-count-'.$count['id'],
            'kind' => 'stock_count',
        ]];
        $this->assertSame($handedIn, $this->told($admin));
        $this->assertSame($handedIn, $this->told($pharmacist));
        $this->assertSame($handedIn, $this->told($manager));
        foreach ([$counter, $plainManager, $doctor, $nurse] as $quiet) {
            $this->assertSame([], $this->told($quiet));
        }
        $this->assertNothingPrivate($handedIn, ['97', '100', 'Paracetamol']);
    }

    public function test_a_discharge_summary_handed_over_reaches_the_doctor_of_the_case_once_and_no_other_doctor(): void
    {
        $nurse = $this->user('nurse', ['name' => 'Sample Nurse']);
        $doctor = $this->withPhone($this->user('doctor'));
        $otherDoctor = $this->withPhone($this->user('doctor'));
        $admin = $this->withPhone($this->user('admin'));
        Sanctum::actingAs($nurse);
        $case = $this->postJson('/api/ipd/admissions', [
            'patient_id' => Patient::factory()->create(['name' => 'Sample Mother Tester'])->id, 'doctor_id' => $doctor->id,
            'bed_id' => Bed::create(['ward' => 'General Ward', 'bed_no' => '1', 'daily_charge' => 800])->id, 'admitted_at' => now()->subDays(2)->format('Y-m-d H:i'), 'reason' => 'Fever for evaluation',
        ])->assertCreated()->json('id');
        $url = "/api/ipd/admissions/{$case}/summary";

        // Nothing to hand over yet. Saving the summary, however often, tells nobody.
        $this->postJson("{$url}/ready")->assertStatus(422)->assertJsonValidationErrors('summary');
        $this->putJson($url, ['diagnosis' => 'Enteric fever', 'course' => 'Fever settled on day 2.'])->assertOk()->assertJsonPath('ready_at', null)->assertJsonPath('ready_by', null);
        $this->putJson($url, ['diagnosis' => 'Enteric fever', 'course' => 'Fever settled on day 2. Taking orally.'])->assertOk();
        Http::assertNothingSent();

        // Handed to the doctor: the doctor of the case is told, and only that doctor.
        $this->postJson("{$url}/ready")->assertOk()->assertJsonPath('ready_at', now()->format('Y-m-d H:i'))->assertJsonPath('ready_by', ['id' => $nurse->id, 'name' => 'Sample Nurse']);
        $ready = [[
            'title' => 'Discharge summary to approve',
            'body' => Admission::find($case)->ipd_no.' · Sample Mother Tester · sent by Sample Nurse',
            'url' => "/ipd/{$case}?tab=summary",
            'tag' => "summary-{$case}",
            'kind' => 'summary',
        ]];
        $this->assertSame($ready, $this->told($doctor));
        $this->assertSame([], $this->told($otherDoctor));
        $this->assertSame([], $this->told($admin));
        $this->assertNothingPrivate($ready, ['Enteric', 'Fever', 'orally']);
        // Pressed again: nobody is told a second time.
        $this->postJson("{$url}/ready")->assertOk()->assertJsonPath('ready_by.id', $nurse->id);
        Http::assertSentCount(1);
        $this->assertSame(1, AuditLog::where('action', 'ipd.summary_ready')->count());
        $this->assertSame('Sent a discharge summary for approval', AuditLog::words(AuditLog::where('action', 'ipd.summary_ready')->first())['action']);

        // Approved, it is not handed over again; the approval withdrawn, it is no longer waiting either, and can be handed over afresh.
        Sanctum::actingAs($doctor);
        $this->postJson("{$url}/approve", ['approved' => true])->assertOk()->assertJsonPath('ready_by.id', $nurse->id);
        Sanctum::actingAs($nurse);
        $this->postJson("{$url}/ready")->assertStatus(422)->assertJsonPath('errors.summary.0', 'The summary is already approved.');
        Sanctum::actingAs($doctor);
        $this->postJson("{$url}/approve", ['approved' => false])->assertOk()->assertJsonPath('ready_at', null)->assertJsonPath('ready_by', null);
        Sanctum::actingAs($nurse);
        $this->postJson("{$url}/ready")->assertOk();
        $this->assertCount(2, $this->told($doctor));

        // Handing over needs the IPD permission.
        Sanctum::actingAs($this->user('pharmacist'));
        $this->postJson("{$url}/ready")->assertForbidden();
    }

    public function test_a_summary_for_a_doctor_who_cannot_be_told_reaches_the_approvers_who_are_not_doctors(): void
    {
        $nurse = $this->user('nurse');
        $consultant = $this->user('doctor', ['sign_in_with' => 'none']); // a visiting consultant, only on the lists
        $admin = $this->withPhone($this->user('admin'));
        $manager = $this->withPhone($this->user('manager', ['permissions' => ['canApproveIpd' => true]]));
        $plainManager = $this->withPhone($this->user('manager'));
        $doctor = $this->withPhone($this->user('doctor'));
        $otherDoctor = $this->withPhone($this->user('doctor'));
        $handOver = function (User $doctorOfCase): int {
            $case = $this->postJson('/api/ipd/admissions', [
                'patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctorOfCase->id, 'reason' => 'Observation', 'admitted_at' => now()->subDay()->format('Y-m-d H:i'),
                'bed_id' => Bed::create(['ward' => 'General Ward', 'bed_no' => 'S'.random_int(100, 999), 'daily_charge' => 800])->id,
            ])->assertCreated()->json('id');
            $this->putJson("/api/ipd/admissions/{$case}/summary", ['diagnosis' => 'Sample diagnosis'])->assertOk();
            $this->postJson("/api/ipd/admissions/{$case}/summary/ready")->assertOk();

            return $case;
        };

        // The consultant cannot be told: those who approve summaries and are not doctors are told instead.
        Sanctum::actingAs($nurse);
        $case = $handOver($consultant);
        $this->assertSame(["summary-{$case}"], array_column($this->told($admin), 'tag'));
        $this->assertSame(["summary-{$case}"], array_column($this->told($manager), 'tag'));
        foreach ([$plainManager, $doctor, $otherDoctor] as $quiet) {
            $this->assertSame([], $this->told($quiet));
        }

        // A doctor hands over their own case's summary for someone else to approve: not told of their own step, and
        // still no other doctor is.
        Sanctum::actingAs($doctor);
        $own = $handOver($doctor);
        $this->assertSame(["summary-{$case}", "summary-{$own}"], array_column($this->told($admin), 'tag'));
        $this->assertSame([], $this->told($doctor));
        $this->assertSame([], $this->told($otherDoctor));
    }

    public function test_an_admin_who_hands_in_a_count_or_a_summary_is_not_told_of_it_and_the_other_admin_is(): void
    {
        $admin = $this->withPhone($this->user('admin', ['name' => 'Sample Owner']));
        $otherAdmin = $this->withPhone($this->user('admin'));
        $consultant = $this->user('doctor', ['sign_in_with' => 'none']); // their summaries go to those who approve them

        // The first admin counts the shelf and hands the count in, then hands over a visiting consultant's summary.
        Sanctum::actingAs($admin);
        $medicine = Medicine::create(['name' => 'Paracetamol 650mg Tablet', 'gst_percent' => 12, 'units_per_pack' => 10, 'reorder_level' => 10]);
        $this->postJson('/api/pharmacy/purchases', [
            'stockist_id' => Stockist::create(['name' => 'Sample Distributors'])->id, 'invoice_no' => 'INV-P002', 'invoice_date' => now()->toDateString(),
            'items' => [['medicine_id' => $medicine->id, 'batch_no' => 'P002', 'expiry' => now()->addYear()->format('Y-m'), 'units_per_pack' => 10, 'packs' => 10, 'pack_price' => 15, 'pack_mrp' => 28, 'gst_percent' => 12, 'rack_no' => 'A']],
        ])->assertCreated();
        $count = $this->postJson('/api/pharmacy/audit/counts', ['scope' => 'all'])->assertCreated()->json();
        $this->putJson("/api/pharmacy/audit/counts/{$count['id']}/lines/{$count['rows'][0]['id']}", ['counted_units' => 97])->assertOk();
        $this->postJson("/api/pharmacy/audit/counts/{$count['id']}/submit")->assertOk()->assertJsonPath('status', 'submitted');
        $case = $this->postJson('/api/ipd/admissions', [
            'patient_id' => Patient::factory()->create()->id, 'doctor_id' => $consultant->id, 'reason' => 'Observation', 'admitted_at' => now()->subDay()->format('Y-m-d H:i'),
            'bed_id' => Bed::create(['ward' => 'General Ward', 'bed_no' => 'S1', 'daily_charge' => 800])->id,
        ])->assertCreated()->json('id');
        $this->putJson("/api/ipd/admissions/{$case}/summary", ['diagnosis' => 'Sample diagnosis'])->assertOk();
        $this->postJson("/api/ipd/admissions/{$case}/summary/ready")->assertOk();

        // Each reaches every admin but the one who did it: the other admin is told of each, once.
        $this->assertSame([], $this->told($admin));
        $this->assertSame([['stock_count', 'stock-count-'.$count['id']], ['summary', "summary-{$case}"]], array_map(fn (array $notice) => [$notice['kind'], $notice['tag']], $this->told($otherAdmin)));
    }

    public function test_the_summary_says_whether_the_doctor_of_the_case_is_the_one_told(): void
    {
        $doctor = $this->user('doctor');
        $consultant = $this->user('doctor', ['sign_in_with' => 'none']);
        $noWard = $this->user('doctor', ['permissions' => ['canWorkIpd' => false]]);
        $beds = 0;
        $admit = function (User $doctorOfCase) use (&$beds): int {
            return $this->postJson('/api/ipd/admissions', [
                'patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctorOfCase->id, 'reason' => 'Observation', 'admitted_at' => now()->subDay()->format('Y-m-d H:i'),
                'bed_id' => Bed::create(['ward' => 'General Ward', 'bed_no' => 'S'.++$beds, 'daily_charge' => 800])->id,
            ])->assertCreated()->json('id');
        };
        Sanctum::actingAs($this->user('nurse'));

        // A doctor who signs in is the one told: so the screen says, before the summary is written, once it is, and once
        // it is handed over.
        $case = $admit($doctor);
        $this->getJson("/api/ipd/admissions/{$case}/summary")->assertOk()->assertJsonPath('id', null)->assertJsonPath('doctor_hears', true);
        $this->putJson("/api/ipd/admissions/{$case}/summary", ['diagnosis' => 'Sample diagnosis'])->assertOk()->assertJsonPath('doctor_hears', true);
        $this->postJson("/api/ipd/admissions/{$case}/summary/ready")->assertOk()->assertJsonPath('doctor_hears', true);

        // A consultant who never signs in, or a doctor who may not work in the IPD, is not: those who approve are told
        // instead.
        foreach ([$consultant, $noWard] as $notTold) {
            $other = $admit($notTold);
            $this->getJson("/api/ipd/admissions/{$other}/summary")->assertOk()->assertJsonPath('doctor_hears', false);
            $this->putJson("/api/ipd/admissions/{$other}/summary", ['diagnosis' => 'Sample diagnosis'])->assertOk()->assertJsonPath('doctor_hears', false);
            $this->postJson("/api/ipd/admissions/{$other}/summary/ready")->assertOk()->assertJsonPath('doctor_hears', false);
        }
    }

    public function test_a_device_is_let_go_of_by_whoever_holds_it_and_the_answer_is_the_same_either_way(): void
    {
        $doctor = $this->withPhone($this->user('doctor'));
        $this->withPhone($nurse = $this->user('nurse'));

        // The nurse signs in on the doctor's old phone and is not to see the doctor's notices there.
        Sanctum::actingAs($nurse);
        $this->postJson('/api/me/push/release', ['endpoint' => $this->phoneOf($doctor)])->assertNoContent();
        $this->assertSame([$nurse->id], PushSubscription::pluck('user_id')->all());
        $this->postJson('/api/me/push/release', ['endpoint' => $this->phoneOf($doctor)])->assertNoContent();
        $this->postJson('/api/me/push/release', [])->assertNoContent();
        // A phone letting go is not something a person did: the activity log is left alone.
        $this->assertSame(0, AuditLog::count());
    }

    public function test_an_own_record_login_is_refused_letting_go_of_a_device_and_the_holder_keeps_it(): void
    {
        $nurse = $this->withPhone($this->user('nurse'));
        $ward = $this->user('staff', ['email' => null, 'phone' => '9000000001', 'password' => Hash::make('482915'), 'sign_in_with' => 'pin']);

        // Left closed on purpose: an own-record login reaches nothing that is not named for it, and the owner has not
        // agreed to open more. The device stays noted for the nurse.
        Sanctum::actingAs($ward);
        $this->postJson('/api/me/push/release', ['endpoint' => $this->phoneOf($nurse)])->assertForbidden();
        $this->assertSame([$nurse->id], PushSubscription::pluck('user_id')->all());
    }

    public function test_a_person_can_ask_whether_the_device_in_hand_is_noted_for_them_and_learns_nothing_of_anyone_elses(): void
    {
        $doctor = $this->withPhone($this->user('doctor'));
        $otherDoctor = $this->withPhone($this->user('doctor'));

        Sanctum::actingAs($doctor);
        $this->postJson('/api/me/push/held', ['endpoint' => $this->phoneOf($doctor)])->assertOk()->assertExactJson(['held' => true]);
        // Another person's device, one never noted, or no address at all: the same plain no.
        $this->postJson('/api/me/push/held', ['endpoint' => $this->phoneOf($otherDoctor)])->assertOk()->assertExactJson(['held' => false]);
        $this->postJson('/api/me/push/held', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/sample-never-noted'])->assertOk()->assertExactJson(['held' => false]);
        $this->postJson('/api/me/push/held', [])->assertOk()->assertExactJson(['held' => false]);
        $this->postJson('/api/me/push/held', ['endpoint' => ['not', 'an', 'address']])->assertOk()->assertExactJson(['held' => false]);
        // Asking changes nothing, and is not something a person did: the activity log is left alone.
        $this->assertSame([$doctor->id, $otherDoctor->id], PushSubscription::orderBy('id')->pluck('user_id')->all());
        $this->assertSame(0, AuditLog::count());

        // An own-record login is refused, as it is everything else of the notices.
        Sanctum::actingAs($this->user('staff', ['email' => null, 'phone' => '9000000001', 'password' => Hash::make('482915'), 'sign_in_with' => 'pin']));
        $this->postJson('/api/me/push/held', ['endpoint' => $this->phoneOf($doctor)])->assertForbidden();
    }

    public function test_signing_a_person_out_everywhere_stops_their_notices_on_every_device(): void
    {
        $admin = $this->user('admin');
        $nurse = $this->withPhone($this->user('nurse'));
        $desk = $this->withPhone($this->user('frontdesk'));
        $leaver = $this->withPhone($this->user('pharmacist', ['phone' => '9555555555']));
        $kept = $this->withPhone($this->user('lab_tech'));
        $record = $this->employee('Sample Leaver', ['employee_no' => 'E1', 'phone' => '9555555555', 'user_id' => $leaver->id]);

        // A new password from the office.
        Sanctum::actingAs($admin);
        $this->postJson("/api/staff/{$nurse->id}/new-password")->assertOk();
        $this->assertSame(0, PushSubscription::where('user_id', $nurse->id)->count());
        // Marked as left on their staff record.
        $this->putJson("/api/workforce/employees/{$record->id}", ['employee_no' => 'E1', 'name' => 'Sample Leaver', 'phone' => '9555555555', 'user_id' => $leaver->id, 'status' => 'inactive'])->assertOk();
        $this->assertFalse($leaver->fresh()->is_active);
        $this->assertSame(0, PushSubscription::where('user_id', $leaver->id)->count());
        // Their own password changed: every device, this one included, notes itself again from the app.
        Sanctum::actingAs($desk);
        $this->postJson('/api/me/password', ['current_password' => 'password', 'password' => 'MyOwnWord77', 'password_confirmation' => 'MyOwnWord77'])->assertOk();
        $this->assertSame(0, PushSubscription::where('user_id', $desk->id)->count());

        $this->assertSame([$kept->id], PushSubscription::pluck('user_id')->all());
    }

    public function test_one_broken_device_neither_stops_the_others_nor_stays(): void
    {
        Exceptions::fake();
        $doctor = $this->user('doctor');
        $broken = 'https://fcm.googleapis.com/fcm/send/sample-broken-device';
        // Keys of the right length that no notification can be locked for.
        PushSubscription::create(['user_id' => $doctor->id, 'endpoint' => $broken, 'endpoint_hash' => PushSubscription::hashOf($broken), 'p256dh' => WebPush::encode("\x05".random_bytes(64)), 'auth' => $this->phoneSecret]);
        $this->withPhone($doctor);

        $this->assertSame(1, PhoneNotice::now($doctor->id, ['title' => 'Sample', 'body' => 'Sample', 'kind' => PhoneNotice::TASK]));
        $this->assertCount(1, $this->told($doctor));
        $this->assertSame([$this->phoneOf($doctor)], PushSubscription::pluck('endpoint')->all());
        Exceptions::assertReported(InvalidArgumentException::class);

        // The same on the test from My account: the broken device is forgotten, and the person is told to switch on again.
        PushSubscription::create(['user_id' => $doctor->id, 'endpoint' => $broken, 'endpoint_hash' => PushSubscription::hashOf($broken), 'p256dh' => WebPush::encode("\x05".random_bytes(64)), 'auth' => $this->phoneSecret]);
        $this->postJson('/api/me/push/test', ['endpoint' => $broken])->assertOk()->assertJsonPath('sent', false)->assertJsonPath('message', 'This device had stopped accepting notifications. Switch them on again.');
        $this->assertSame(1, PushSubscription::count());
    }

    public function test_a_notice_too_long_to_send_is_reported_and_the_device_is_kept(): void
    {
        Exceptions::fake();
        $nurse = $this->withPhone($this->user('nurse'));

        // Words too long are not the device's fault: nothing is sent, it is reported, and the device stays.
        $this->assertSame(0, PhoneNotice::now($nurse->id, ['title' => str_repeat('x', 4000), 'kind' => PhoneNotice::TASK]));
        Exceptions::assertReported(fn (InvalidArgumentException $e) => $e->getMessage() === 'That is too long for a notification.');
        Http::assertNothingSent();
        $this->assertSame([$this->phoneOf($nurse)], PushSubscription::pluck('endpoint')->all());

        // The next notice of the usual length reaches it.
        $this->assertSame(1, PhoneNotice::now($nurse->id, ['title' => 'Sample', 'kind' => PhoneNotice::TASK]));
        $this->assertSame(['Sample'], array_column($this->told($nurse), 'title'));
    }

    public function test_the_test_notice_speaks_for_every_kind_and_a_broken_letter_does_not_blank_a_notice(): void
    {
        $nurse = $this->withPhone($this->user('nurse'));
        $this->postJson('/api/me/push/test', ['endpoint' => $this->phoneOf($nurse)])->assertOk()->assertJsonPath('sent', true);
        $this->assertSame(['Notifications are on', 'This is how the hospital’s notices will reach you.', 'test'], [$this->told($nurse)[0]['title'], $this->told($nurse)[0]['body'], $this->told($nurse)[0]['kind']]);

        $this->assertSame(1, PhoneNotice::now($nurse->id, ['title' => "Sample \xB1 name", 'kind' => PhoneNotice::TASK]));
        $this->assertSame("Sample \u{FFFD} name", $this->told($nurse)[1]['title']);
        // A kind the person is not offered is never sent.
        $this->assertSame(0, PhoneNotice::now($nurse->id, ['title' => 'Sample', 'kind' => PhoneNotice::APPOINTMENT]));
        $this->assertCount(2, $this->told($nurse));
    }
}
