<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AdmissionPayment;
use App\Models\Branch;
use App\Models\ConsultantPayment;
use App\Models\ConsultantVisit;
use App\Models\DayClose;
use App\Models\Expense;
use App\Models\Patient;
use App\Models\User;
use App\Support\PaidOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Consultants: a share of what is collected on their own cases, a fixed fee for visiting another doctor's inpatient
 * (more for the first visit, less for each later day), and the record of what the hospital has paid them.
 */
class ConsultantPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $here;

    private User $admin;

    private User $nurse;

    private User $own;

    private User $visiting;

    private Admission $admission;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-24 11:00:00');
        $this->here = Branch::create(['name' => 'Sample Hospital']);
        $user = fn (string $role, array $more = []) => User::factory()->create($more + ['role' => $role, 'branch_id' => $this->here->id]);
        $this->admin = $user('admin', ['name' => 'Owner']);
        $this->nurse = $user('nurse', ['name' => 'Ward Nurse']);
        $this->own = $user('doctor', ['name' => 'Dr Own Case', 'share_ipd' => 10]);
        $this->visiting = $user('doctor', ['name' => 'Dr Visiting']);
        $this->admission = Admission::create(['branch_id' => $this->here->id, 'patient_id' => Patient::factory()->create(['name' => 'Sample Patient'])->id, 'doctor_id' => $this->own->id, 'admitted_at' => '2026-11-20 09:00', 'status' => 'admitted', 'created_by' => $this->admin->id]);
    }

    private function add(User $doctor, string $date, ?Admission $admission = null)
    {
        return $this->postJson('/api/ipd/admissions/'.($admission ?? $this->admission)->id.'/consultant-visits', ['doctor_id' => $doctor->id, 'visited_on' => $date]);
    }

    private function row(User $doctor, string $month = '2026-11'): array
    {
        return collect($this->getJson("/api/consultants?month={$month}")->assertOk()->json('rows'))->firstWhere('doctor_id', $doctor->id);
    }

    public function test_a_visit_to_another_doctors_inpatient_earns_the_first_visit_fee_once_and_the_later_fee_each_day_after(): void
    {
        Sanctum::actingAs($this->nurse);
        // The ward writes down that the consultant came. It sees which visit it is, never the fee.
        $first = $this->add($this->visiting, '2026-11-22')->assertCreated()->assertJsonPath('kind', 'first')->assertJsonMissingPath('fee')->json('id');
        $this->add($this->visiting, '2026-11-22')->assertStatus(422)->assertJsonValidationErrors('visited_on');
        $this->add($this->visiting, '2026-11-23')->assertCreated()->assertJsonPath('kind', 'later');
        // Not the doctor the patient is admitted under, not a day to come, not a day before the admission.
        $this->add($this->own, '2026-11-22')->assertStatus(422)->assertJsonValidationErrors('doctor_id');
        $this->add($this->visiting, '2026-11-25')->assertStatus(422)->assertJsonValidationErrors('visited_on');
        $this->add($this->visiting, '2026-11-19')->assertStatus(422)->assertJsonValidationErrors('visited_on');
        // An earlier day written down afterwards becomes the first visit, and the one that was first becomes a later one.
        $this->add($this->visiting, '2026-11-21')->assertCreated()->assertJsonPath('kind', 'first');
        $this->assertSame([['2026-11-21', 'first'], ['2026-11-22', 'later'], ['2026-11-23', 'later']], array_map(fn ($v) => [$v['visited_on'], $v['kind']], $this->getJson("/api/ipd/admissions/{$this->admission->id}/consultant-visits")->assertOk()->json('visits')));
        $this->getJson('/api/consultants')->assertForbidden();

        // The owner sees the money: one thousand for the first visit, five hundred for each later day.
        Sanctum::actingAs($this->admin);
        $this->assertSame([1000.0, 500.0, 500.0], array_map(fn ($v) => (float) $v['fee'], $this->getJson("/api/ipd/admissions/{$this->admission->id}/consultant-visits")->json('visits')));
        $row = $this->row($this->visiting);
        $this->assertSame([3, 1, 2000.0, 0.0, 2000.0, 0.0, 2000.0], [$row['visits'], $row['first_visits'], (float) $row['visit_fees'], (float) $row['share'], (float) $row['earned'], (float) $row['paid'], (float) $row['due']]);

        // The first visit taken off, with its reason: the next day's becomes the first.
        $this->postJson("/api/consultant-visits/{$first}/cancel")->assertStatus(422)->assertJsonValidationErrors('reason');
        $earliest = ConsultantVisit::whereDate('visited_on', '2026-11-21')->value('id');
        $this->postJson("/api/consultant-visits/{$earliest}/cancel", ['reason' => 'Written down for the wrong patient'])->assertOk();
        $this->assertSame([['2026-11-22', 'first', 1000.0], ['2026-11-23', 'later', 500.0]], array_map(fn ($v) => [$v['visited_on'], $v['kind'], (float) $v['fee']], $this->getJson("/api/ipd/admissions/{$this->admission->id}/consultant-visits")->json('visits')));

        // New rates apply to visits written down from now on; what was earned before stays as it was.
        $this->putJson('/api/consultants/rates', ['first_visit' => 1200, 'later_visit' => 600])->assertOk()->assertJsonPath('first_visit', 1200);
        $other = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Other', 'branch_id' => $this->here->id]);
        $this->add($other, '2026-11-24')->assertCreated()->assertJsonPath('fee', 1200);
        $this->add($this->visiting, '2026-11-24')->assertCreated()->assertJsonPath('fee', 600);
        $this->assertSame(2100.0, (float) $this->row($this->visiting)['visit_fees']);
    }

    public function test_their_own_case_earns_a_share_of_what_is_collected(): void
    {
        (new AdmissionPayment)->forceFill(['admission_id' => $this->admission->id, 'type' => 'payment', 'amount' => 20000, 'mode' => 'cash', 'received_by' => $this->admin->id])->save();
        Sanctum::actingAs($this->admin);
        $row = $this->row($this->own);
        $ipd = collect($row['shares'])->firstWhere('counter', 'IPD');
        $this->assertSame([20000.0, 10.0, 2000.0, 2000.0, 0, 2000.0], [(float) $ipd['collected'], (float) $ipd['percent'], (float) $ipd['share'], (float) $row['share'], $row['visits'], (float) $row['due']]);
        // A consultant with no percentage set earns nothing from collections, and it says the percentage is not set.
        $this->assertNull(collect($this->row($this->visiting)['shares'])->firstWhere('counter', 'IPD')['percent']);
    }

    public function test_a_payment_is_kept_against_the_month_and_stands_in_the_cash_book(): void
    {
        Sanctum::actingAs($this->nurse);
        $this->add($this->visiting, '2026-11-22')->assertCreated();
        $this->add($this->visiting, '2026-11-23')->assertCreated();
        $pay = fn (array $more = []) => $this->postJson("/api/consultants/{$this->visiting->id}/payments", $more + ['month' => '2026-11', 'paid_on' => '2026-11-24', 'amount' => 1000, 'mode' => 'cash']);
        $pay()->assertForbidden();

        Sanctum::actingAs($this->admin);
        $pay(['amount' => 0])->assertStatus(422)->assertJsonValidationErrors('amount');
        $pay(['paid_on' => '2026-11-25'])->assertStatus(422)->assertJsonValidationErrors('paid_on');
        $id = $pay(['reference' => 'Voucher 12'])->assertCreated()->json('id');
        $this->assertSame([1500.0, 1000.0, 500.0], [(float) $this->row($this->visiting)['earned'], (float) $this->row($this->visiting)['paid'], (float) $this->row($this->visiting)['due']]);

        // It is a paid expense under the consultants' heading, and the day's cash count sees the cash going out.
        $expense = Expense::query()->where('source_type', 'consultant_payment')->where('source_id', $id)->firstOrFail();
        $this->assertSame(['Consultants’ fees', 'paid', 1000.0, 'cash', 'Dr Visiting', '2026-11-24'], [$expense->category, $expense->status, (float) $expense->amount, $expense->mode, $expense->paid_to, $expense->paid_on->format('Y-m-d')]);
        $this->assertSame(1000.0, PaidOut::between(Carbon::parse('2026-11-24')->startOfDay(), Carbon::parse('2026-11-24')->endOfDay())['expenses_cash']);
        // The statement lists each visit with its patient, and each payment.
        $statement = $this->getJson("/api/consultants/{$this->visiting->id}?month=2026-11")->assertOk()->json();
        $this->assertSame([['Sample Patient', 'first', 1000.0], ['Sample Patient', 'later', 500.0]], array_map(fn ($v) => [$v['patient']['name'], $v['kind'], (float) $v['fee']], $statement['visit_list']));
        $this->assertSame([[1000.0, 'cash', 'Voucher 12', false]], array_map(fn ($p) => [(float) $p['amount'], $p['mode'], $p['reference'], $p['cancelled']], $statement['payments']));

        // A payment cannot be dated into a day whose accounts are closed: nothing is written at all.
        DayClose::create(['day' => '2026-11-23', 'closed_by_name' => 'Owner', 'closed_at' => now(), 'expected_cash' => 0, 'counted_cash' => 0, 'variance' => 0, 'snapshot' => []]);
        $this->assertGreaterThanOrEqual(400, $pay(['paid_on' => '2026-11-23'])->status());
        $this->assertSame([1, 1], [ConsultantPayment::count(), Expense::query()->where('source_type', 'consultant_payment')->count()]);

        // One written down by mistake is cancelled with its reason, and its line in the cash book with it.
        $this->postJson("/api/consultants/payments/{$id}/cancel")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/consultants/payments/{$id}/cancel", ['reason' => 'Paid to the wrong doctor'])->assertOk();
        $this->assertSame([0.0, 1500.0], [(float) $this->row($this->visiting)['paid'], (float) $this->row($this->visiting)['due']]);
        $this->assertNotNull($expense->fresh()->cancelled_at);
        $this->assertSame(0.0, PaidOut::between(Carbon::parse('2026-11-24')->startOfDay(), Carbon::parse('2026-11-24')->endOfDay())['expenses_cash']);
        $this->postJson("/api/consultants/payments/{$id}/cancel", ['reason' => 'Again'])->assertStatus(422);
    }

    public function test_the_money_is_for_the_admin_unless_given_to_another_login(): void
    {
        Sanctum::actingAs($this->own);
        $this->getJson('/api/consultants')->assertForbidden();
        $this->getJson('/api/reports/list/doctor-payout?from=2026-11-01&to=2026-11-30')->assertForbidden();
        $this->getJson('/api/me')->assertJsonPath('can_pay_consultants', false);
        // The owner's own doctor login, ticked by the admin, sees it all.
        $this->own->update(['permissions' => ['canPayConsultants' => true]]);
        $this->getJson('/api/me')->assertJsonPath('can_pay_consultants', true);
        $this->getJson('/api/consultants')->assertOk();
        $this->getJson('/api/reports/list/doctor-payout?from=2026-11-01&to=2026-11-30')->assertOk();
        // An own-record login reaches none of it.
        Sanctum::actingAs(User::factory()->create(['role' => 'staff', 'branch_id' => $this->here->id]));
        $this->getJson('/api/consultants')->assertForbidden();
        $this->getJson("/api/ipd/admissions/{$this->admission->id}/consultant-visits")->assertForbidden();
    }
}
