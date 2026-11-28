<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Branch;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Support\DoctorMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A consultant's "collected" figure opens the payments behind it: one line for each entry, which add up to the
 * figure on the statement and in the doctor-collections report, because both come from the same entries.
 * Made-up patients, doctors and amounts.
 */
class ConsultantCollectedLinesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $here;

    private User $admin;

    private User $own;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-24 11:00:00');
        $this->here = Branch::create(['name' => 'Sample Hospital']);
        $this->admin = $this->user('admin', ['name' => 'Owner']);
        $this->own = $this->user('doctor', ['name' => 'Dr Own Case', 'share_front_desk' => 50, 'share_lab' => 10, 'share_ipd' => 20]);
        $this->other = $this->user('doctor', ['name' => 'Dr Other Case']);
        Sanctum::actingAs($this->admin);
    }

    private function user(string $role, array $more = []): User
    {
        return User::factory()->create($more + ['role' => $role, 'branch_id' => $this->here->id]);
    }

    /** A visit to the doctor, billed at the fee, with this much paid at the desk. */
    private function visit(User $doctor, float $fee, float $paid, ?Patient $patient = null, string $at = '2026-11-24 11:00'): array
    {
        $service = Service::firstOrCreate(['name' => 'Consultation'], ['type' => 'appointment', 'price' => 500]);

        return $this->postJson('/api/appointments', [
            'patient_id' => ($patient ?? Patient::factory()->create())->id, 'doctor_id' => $doctor->id, 'service_id' => $service->id,
            'scheduled_at' => $at, 'status' => 'arrived', 'unit_price' => $fee, 'quantity' => 1,
        ] + ($paid > 0 ? ['paid' => $paid, 'payment_mode' => 'cash'] : []))->assertCreated()->json();
    }

    private function admit(Patient $patient, User $doctor): Admission
    {
        return Admission::create(['branch_id' => $this->here->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'admitted_at' => '2026-11-20 09:00', 'status' => 'admitted', 'created_by' => $this->admin->id]);
    }

    private function lines(User $doctor, string $counter): array
    {
        return $this->getJson("/api/consultants/{$doctor->id}/collected?month=2026-11&counter={$counter}")->assertOk()->json();
    }

    /**
     * November for Dr Own Case: visits paid in full, in part, from a deposit and on an earlier day; a refund; a lab
     * order paid by UPI; a lab bill paid and then cancelled; a bill part paid and moved onto the IPD bill; money on
     * the inpatient case. Around it: entries of October and December, and another doctor's patients.
     *
     * @return array<string, mixed> what the checks need to find again
     */
    private function november(): array
    {
        $sugar = $this->postJson('/api/lab/tests', ['name' => 'RBS', 'price' => 200, 'parameters' => [['name' => 'Glucose']]])->assertCreated()->json();
        $labOrder = fn (array $visit) => $this->postJson('/api/lab/orders', ['patient_id' => $visit['patient_id'], 'appointment_id' => $visit['id'], 'test_ids' => [$sugar['id']]])->assertCreated()->json();

        $earlier = $this->visit($this->own, 400, 400, null, '2026-11-10 10:00');
        $full = $this->visit($this->own, 500, 500);
        $half = $this->visit($this->own, 500, 250);
        $order = $labOrder($full);
        $this->postJson("/api/bills/{$order['bill_id']}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'upi'])->assertCreated();
        $this->postJson("/api/bills/{$full['bills'][0]['id']}/payments", ['type' => 'refund', 'amount' => 100, 'mode' => 'cash'])->assertCreated();

        $saver = Patient::factory()->create(['name' => 'Sample Saver']);
        $this->postJson("/api/patients/{$saver->id}/deposits", ['type' => 'deposit', 'amount' => 1000, 'mode' => 'upi'])->assertCreated();
        $fromDeposit = $this->visit($this->own, 600, 0, $saver);
        $this->postJson("/api/bills/{$fromDeposit['bills'][0]['id']}/use-deposit", ['amount' => 600])->assertCreated();

        // A lab bill paid and then cancelled: its lab order goes with it, so its money now counts at the front desk.
        $dropped = $labOrder($half);
        $this->postJson("/api/bills/{$dropped['bill_id']}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'cash'])->assertCreated();
        $this->postJson("/api/bills/{$dropped['bill_id']}/cancel", ['reason' => 'Ordered twice', 'refund_mode' => 'cash'])->assertOk();

        // An inpatient of Dr Own Case: a visit bill part paid in cash, the rest moved onto the IPD bill, and money on the case.
        $inpatient = Patient::factory()->create(['name' => 'Sample Inpatient']);
        $case = $this->admit($inpatient, $this->own);
        $moved = $this->visit($this->own, 500, 100, $inpatient);
        $this->postJson("/api/ipd/admissions/{$case->id}/transfer", ['type' => 'bill', 'id' => $moved['bills'][0]['id']])->assertCreated();
        $this->postJson("/api/ipd/admissions/{$case->id}/payments", ['type' => 'advance', 'amount' => 5000, 'mode' => 'cash'])->assertCreated();
        $this->postJson("/api/ipd/admissions/{$case->id}/payments", ['type' => 'payment', 'amount' => 1500, 'mode' => 'card'])->assertCreated();
        $this->postJson("/api/ipd/admissions/{$case->id}/payments", ['type' => 'refund', 'amount' => 1000, 'mode' => 'upi'])->assertCreated();

        // Another doctor's patients; Dr Own Case visits one of them as a consultant.
        $this->visit($this->other, 300, 300);
        $otherCase = $this->admit(Patient::factory()->create(['name' => 'Sample Other Inpatient']), $this->other);
        $this->postJson("/api/ipd/admissions/{$otherCase->id}/payments", ['type' => 'advance', 'amount' => 2000, 'mode' => 'cash'])->assertCreated();
        $this->postJson("/api/ipd/admissions/{$otherCase->id}/consultant-visits", ['doctor_id' => $this->own->id, 'visited_on' => '2026-11-22'])->assertCreated();

        // Money on November bills that came in October or December is not November's.
        $outside = collect(['2026-10-31 23:30:00', '2026-12-01 00:00:30'])->map(fn (string $at) => DB::table('payments')->insertGetId(
            ['bill_id' => $half['bills'][0]['id'], 'type' => 'payment', 'amount' => 70, 'mode' => 'cash', 'created_at' => $at, 'updated_at' => $at],
        ))->all();

        return compact('earlier', 'full', 'half', 'order', 'fromDeposit', 'dropped', 'moved', 'case', 'otherCase', 'outside');
    }

    public function test_each_counters_lines_add_up_to_the_statement_and_the_doctor_collections_report(): void
    {
        $made = $this->november();
        $statement = $this->getJson("/api/consultants/{$this->own->id}?month=2026-11")->assertOk()->json();
        $shares = collect($statement['shares'])->keyBy('key');
        $this->assertSame(['front_desk', 'lab', 'ipd'], $shares->keys()->all());
        $report = collect($this->getJson('/api/reports/list/doctor-collections?from=2026-11-01&to=2026-11-30')->assertOk()->json('rows'))->firstWhere('doctor_id', $this->own->id);

        $expected = ['front_desk' => 1750.0, 'lab' => 200.0, 'ipd' => 5500.0];
        foreach ($expected as $counter => $figure) {
            $list = $this->lines($this->own, $counter);
            $sum = round(array_sum(array_column($list['lines'], 'amount')), 2);
            $this->assertEquals($figure, $sum, "{$counter}: the lines");
            $this->assertEquals($figure, $list['collected'], "{$counter}: the list's total");
            $this->assertEquals($figure, $shares[$counter]['collected'], "{$counter}: the statement");
            $this->assertEquals($figure, $report["{$counter}_collected"], "{$counter}: the doctor-collections report");
            // The money by how it was paid is added up from the same lines.
            $this->assertEquals($figure, round(array_sum($list['by_mode']), 2), "{$counter}: by how it was paid");
            foreach ($list['by_mode'] as $mode => $amount) {
                $this->assertEquals(round(array_sum(array_column(array_filter($list['lines'], fn ($l) => $l['mode'] === $mode), 'amount')), 2), $amount, "{$counter}: {$mode}");
            }
            // Never the entry that moves a bill onto an IPD bill, and nothing from October or December.
            $this->assertNotContains('ipd', array_column($list['lines'], 'mode'));
            $this->assertEmpty(array_intersect($made['outside'], array_column($list['lines'], 'id')));
            foreach ($list['lines'] as $line) {
                $this->assertStringStartsWith('2026-11-', $line['on']);
            }
            $this->assertSame([$counter, DoctorMoney::COUNTERS[$counter], 'November 2026'], [$list['counter'], $list['label'], $list['month_name']]);
        }

        // The front desk, line by line, in the order the money came in.
        $desk = $this->lines($this->own, 'front_desk');
        $this->assertSame(
            [[400.0, 'cash', 'payment'], [500.0, 'cash', 'payment'], [250.0, 'cash', 'payment'], [-100.0, 'cash', 'refund'], [600.0, 'deposit', 'payment'], [200.0, 'cash', 'payment'], [-200.0, 'cash', 'refund'], [100.0, 'cash', 'payment']],
            array_map(fn ($l) => [(float) $l['amount'], $l['mode'], $l['type']], $desk['lines']),
        );
        $this->assertEquals(['cash' => 1150, 'deposit' => 600, 'upi' => 0, 'card' => 0], array_intersect_key($desk['by_mode'], array_flip(['cash', 'deposit', 'upi', 'card'])));
        // Entered on the 24th for the 10th: it counts on the 10th, and says when it was typed in.
        $this->assertSame(['2026-11-10 11:00', '2026-11-24', $made['earlier']['bills'][0]['id']], [$desk['lines'][0]['on'], $desk['lines'][0]['entered_on'], $desk['lines'][0]['bill']['id']]);
        $this->assertNull($desk['lines'][1]['entered_on']);
        // Each line names its bill and patient, so the screen can open them.
        $full = $desk['lines'][1];
        $this->assertSame([$made['full']['bills'][0]['id'], $made['full']['patient_id'], '2026-11-24', null, null], [$full['bill']['id'], $full['patient']['id'], $full['bill']['on'], $full['order'], $full['admission']]);
        $this->assertNotEmpty($full['bill']['bill_no']);
        $this->assertSame('Sample Saver', $desk['lines'][4]['patient']['name']);
        // The cancelled lab bill: its payment and the refund, both marked, with no lab order left to open.
        $cancelled = array_values(array_filter($desk['lines'], fn ($l) => $l['bill']['id'] === $made['dropped']['bill_id']));
        $this->assertSame([[200.0, true, true, 'lab', null], [-200.0, true, true, 'lab', null]], array_map(fn ($l) => [(float) $l['amount'], $l['cancelled_lab_bill'], $l['bill']['cancelled'], $l['bill']['kind'], $l['order']], $cancelled));
        $this->assertSame(1, collect($desk['lines'])->where('cancelled_lab_bill', true)->where('type', 'payment')->count());
        // Only the cash taken before the bill was moved onto the IPD bill; the rest comes in with the IPD case.
        $moved = array_values(array_filter($desk['lines'], fn ($l) => $l['bill']['id'] === $made['moved']['bills'][0]['id']));
        $this->assertSame([[100.0, 'cash']], array_map(fn ($l) => [(float) $l['amount'], $l['mode']], $moved));

        // The lab: the order from the visit, by UPI, with its order number.
        $lab = $this->lines($this->own, 'lab')['lines'];
        $order = LabOrder::findOrFail($made['order']['id']);
        $this->assertSame([[200.0, 'upi', $made['order']['bill_id'], $order->id, $order->order_no, false]], array_map(fn ($l) => [(float) $l['amount'], $l['mode'], $l['bill']['id'], $l['order']['id'], $l['order']['order_no'], $l['cancelled_lab_bill']], $lab));

        // The IPD case: its advance, payment and refund, each with the IPD number and the patient.
        $ipd = $this->lines($this->own, 'ipd');
        $ipdNo = $made['case']->fresh()->ipd_no;
        $this->assertSame(
            [['advance', 5000.0, 'cash'], ['payment', 1500.0, 'card'], ['refund', -1000.0, 'upi']],
            array_map(fn ($l) => [$l['type'], (float) $l['amount'], $l['mode']], $ipd['lines']),
        );
        foreach ($ipd['lines'] as $line) {
            $this->assertSame([$made['case']->id, $ipdNo, 'Sample Inpatient', null, null], [$line['admission']['id'], $line['admission']['ipd_no'], $line['patient']['name'], $line['bill'], $line['order']]);
        }
        $this->assertEquals(['cash' => 5000, 'card' => 1500, 'upi' => -1000], array_intersect_key($ipd['by_mode'], array_flip(['cash', 'card', 'upi'])));

        // Another doctor's money stays on their own lists.
        $this->assertEquals([300.0, 0.0, 2000.0], [$this->lines($this->other, 'front_desk')['collected'], $this->lines($this->other, 'lab')['collected'], $this->lines($this->other, 'ipd')['collected']]);
        $this->assertSame([], array_values(array_filter($this->lines($this->other, 'ipd')['lines'], fn ($l) => $l['admission']['id'] === $made['case']->id)));

        // The statement's visits name the IPD case, so the screen can open it.
        $this->assertSame([[$made['otherCase']->id, $made['otherCase']->fresh()->ipd_no, 'Sample Other Inpatient']], array_map(fn ($v) => [$v['admission_id'], $v['ipd_no'], $v['patient']['name']], $statement['visit_list']));
    }

    public function test_the_figures_themselves_are_counted_as_before(): void
    {
        $this->november();
        $rows = DoctorMoney::between(Carbon::parse('2026-11-01')->startOfDay(), Carbon::parse('2026-11-30')->endOfDay())->keyBy('doctor_id');
        $keys = ['front_desk_billed', 'front_desk_collected', 'lab_billed', 'lab_collected', 'ipd_billed', 'ipd_collected', 'billed', 'collected'];
        // Billed: four visits of November and the one entered for the 10th; the lab order; the moved bill's line on the IPD bill.
        // Collected: deposits on the day they were used, refunds taken off, the move onto the IPD bill left out.
        $this->assertEquals([2500, 1750, 200, 200, 400, 5500, 3100, 7450], array_values(array_intersect_key($rows[$this->own->id], array_flip($keys))));
        $this->assertEquals([300, 300, 0, 0, 0, 2000, 300, 2300], array_values(array_intersect_key($rows[$this->other->id], array_flip($keys))));
        // The same figures reach the month's shares at the doctor's percentages.
        $row = collect($this->getJson('/api/consultants?month=2026-11')->assertOk()->json('rows'))->firstWhere('doctor_id', $this->own->id);
        $this->assertEquals([[1750, 875], [200, 20], [5500, 1100]], array_map(fn ($s) => [$s['collected'], $s['share']], $row['shares']));
        $this->assertEquals(1995, $row['share']);
        // October and December keep their own money, in their figures and in their lists.
        foreach (['2026-10' => '2026-10-31 23:30', '2026-12' => '2026-12-01 00:00'] as $month => $on) {
            [$from, $to] = [Carbon::parse("{$month}-01")->startOfMonth(), Carbon::parse("{$month}-01")->endOfMonth()];
            $this->assertEquals(70, DoctorMoney::between($from, $to)->firstWhere('doctor_id', $this->own->id)['front_desk_collected']);
            $this->assertSame([[$on, 70.0]], DoctorMoney::collectedLines($this->own->id, 'front_desk', $from, $to)->map(fn ($l) => [$l['on'], (float) $l['amount']])->all());
        }
    }

    public function test_only_those_who_look_after_consultants_fees_open_the_lists(): void
    {
        $url = "/api/consultants/{$this->own->id}/collected?month=2026-11&counter=front_desk";
        Sanctum::actingAs($this->user('nurse'));
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($this->user('manager'));
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($this->user('accountant'));
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($this->own);
        $this->getJson($url)->assertForbidden();

        // The owner's own doctor login, once ticked, opens any consultant's lists.
        $this->own->update(['permissions' => ['canPayConsultants' => true]]);
        Sanctum::actingAs($this->own->fresh());
        $this->getJson($url)->assertOk()->assertJsonPath('collected', 0)->assertJsonPath('lines', []);
        $this->getJson("/api/consultants/{$this->other->id}/collected?month=2026-11&counter=ipd")->assertOk();

        // A counter must be one of the three, and the month a month.
        $this->getJson("/api/consultants/{$this->own->id}/collected?month=2026-11&counter=pharmacy")->assertStatus(422)->assertJsonValidationErrors('counter');
        $this->getJson("/api/consultants/{$this->own->id}/collected?month=2026-11")->assertStatus(422)->assertJsonValidationErrors('counter');
        $this->getJson("/api/consultants/{$this->own->id}/collected?month=November&counter=lab")->assertStatus(422)->assertJsonValidationErrors('month');
    }
}
