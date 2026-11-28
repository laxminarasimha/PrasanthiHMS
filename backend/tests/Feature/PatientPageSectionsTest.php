<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\DischargeSummary;
use App\Models\Medicine;
use App\Models\MedicineSubscription;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The patient's page: discharge summaries, the medicines prescribed and the monthly lists, each only for those whose work needs it. */
class PatientPageSectionsTest extends TestCase
{
    use RefreshDatabase;

    private const PARTS = ['discharge_summaries', 'medicines_prescribed', 'monthly_medicines'];

    private User $doctor;

    private Patient $patient;

    private ?PatientAccount $account = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-20 10:00');
        $this->doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Sample Rao']);
        $this->patient = Patient::factory()->create(['name' => 'Sample Patient']);
    }

    /** A visit with this doctor and what was prescribed at it; unfinished, it is still on the doctor's pad. */
    private function visit(string $at, array $medicines, bool $finished = true, ?User $doctor = null): Appointment
    {
        $doctor ??= $this->doctor;
        $appointment = Appointment::create(['patient_id' => $this->patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => $at, 'status' => $finished ? 'reviewed' : 'ongoing']);
        $consultation = Consultation::create([
            'appointment_id' => $appointment->id, 'patient_id' => $this->patient->id, 'doctor_id' => $doctor->id,
            'diagnosis' => 'Sample fever', 'completed_at' => $finished ? $at : null,
        ]);
        foreach ($medicines as $i => $medicine) {
            $consultation->medicines()->create((is_string($medicine) ? ['medicine' => $medicine] : $medicine) + ['position' => $i]);
        }

        return $appointment;
    }

    /** A stay, with its discharge summary when one is given. */
    private function stay(string $from, ?string $to, ?array $summary = null, string $status = 'discharged'): Admission
    {
        $admission = Admission::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'admitted_at' => $from, 'discharged_at' => $to, 'status' => $status]);
        if ($summary !== null) {
            DischargeSummary::create(['admission_id' => $admission->id] + $summary);
        }

        return $admission->fresh();
    }

    private function approved(array $more = []): array
    {
        return $more + ['diagnosis' => "Sample enteric fever\nTreated and settled", 'follow_up_on' => '2026-11-27', 'approved_at' => '2026-11-18 12:00', 'approved_by' => $this->doctor->id];
    }

    private function monthlyList(array $more = []): MedicineSubscription
    {
        $this->account ??= PatientAccount::create(['patient_id' => $this->patient->id, 'name' => 'Sample Patient', 'mobile' => '9000000009', 'password' => 'Sample2026']);

        return MedicineSubscription::create($more + [
            'patient_account_id' => $this->account->id, 'patient_id' => $this->patient->id, 'title' => 'Sample sugar medicines',
            'items' => [['medicine_id' => null, 'name' => 'Sample tablet', 'quantity' => 30]], 'day_of_month' => 23, 'status' => 'active', 'next_due_on' => '2026-11-23',
        ]);
    }

    private function overview(string $role, ?array $permissions = null): array
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role] + ($permissions === null ? [] : ['permissions' => $permissions])));

        return $this->getJson("/api/patients/{$this->patient->id}/overview")->assertOk()->json();
    }

    /** @return list<string> */
    private function partsIn(array $page): array
    {
        return array_values(array_intersect(self::PARTS, array_keys($page)));
    }

    public function test_each_login_gets_only_the_parts_its_work_needs(): void
    {
        $this->visit('2026-11-10 11:00', ['Sample Paracetamol 650']);
        $this->stay('2026-11-14 09:00', '2026-11-18 11:00', $this->approved(['medicines' => [['medicine' => 'Sample Cefixime 200', 'frequency' => '1-0-1']]]));
        $this->monthlyList();

        // Those who read clinical notes get all three, with the diagnosis on each summary; so does the Hospital Manager.
        foreach (['doctor', 'nurse', 'lab_tech', 'manager', 'admin'] as $role) {
            $page = $this->overview($role);
            $this->assertSame(self::PARTS, $this->partsIn($page), $role);
            $this->assertSame('Sample enteric fever', $page['discharge_summaries'][0]['diagnosis'], $role);
        }

        // The pharmacy: what was prescribed and the monthly lists, but no discharge summaries to print.
        $this->assertSame(['medicines_prescribed', 'monthly_medicines'], $this->partsIn($this->overview('pharmacist')));
        // Anyone given a look at the pharmacy reads the same.
        $this->assertSame(['medicines_prescribed', 'monthly_medicines'], $this->partsIn($this->overview('accountant', ['canViewPharmacy' => true])));

        // The front desk: the approved summaries to print, never what is in them, and none of the medicines.
        $desk = $this->overview('frontdesk');
        $this->assertSame(['discharge_summaries'], $this->partsIn($desk));
        $this->assertArrayNotHasKey('diagnosis', $desk['discharge_summaries'][0]);
        $this->assertStringNotContainsString('enteric', json_encode($desk['discharge_summaries']));
        $this->assertStringNotContainsString('Cefixime', json_encode($desk));
        // Without IPD work the desk gets no list either.
        $this->assertSame([], $this->partsIn($this->overview('frontdesk', ['canWorkIpd' => false])));

        // Those who work only with money or staff records get nothing new.
        foreach (['accountant', 'hr', 'dept_manager'] as $role) {
            $this->assertSame([], $this->partsIn($this->overview($role)), $role);
        }
    }

    public function test_only_approved_summaries_are_listed_with_what_the_print_needs(): void
    {
        $older = $this->stay('2026-10-01 09:00', '2026-10-04 10:00', $this->approved(['approved_at' => '2026-10-04 09:30', 'follow_up_on' => null]));
        $latest = $this->stay('2026-11-14 09:00', '2026-11-18 11:00', $this->approved());
        $this->stay('2026-11-19 08:00', null, ['diagnosis' => 'Sample draft, not approved', 'medicines' => [['medicine' => 'Sample Draft Syrup']]], 'admitted');
        $this->stay('2026-09-01 08:00', '2026-09-02 08:00'); // no summary written

        $page = $this->overview('nurse');
        $this->assertSame([
            [
                'admission_id' => $latest->id, 'ipd_no' => $latest->ipd_no, 'status' => 'discharged', 'admitted_at' => '2026-11-14 09:00', 'discharged_at' => '2026-11-18 11:00',
                'doctor' => ['id' => $this->doctor->id, 'name' => 'Dr Sample Rao'], 'approved_at' => '2026-11-18 12:00', 'follow_up_on' => '2026-11-27', 'diagnosis' => 'Sample enteric fever',
            ],
            [
                'admission_id' => $older->id, 'ipd_no' => $older->ipd_no, 'status' => 'discharged', 'admitted_at' => '2026-10-01 09:00', 'discharged_at' => '2026-10-04 10:00',
                'doctor' => ['id' => $this->doctor->id, 'name' => 'Dr Sample Rao'], 'approved_at' => '2026-10-04 09:30', 'follow_up_on' => null, 'diagnosis' => 'Sample enteric fever',
            ],
        ], $page['discharge_summaries']);
        // The draft's medicines are not offered as taken home either.
        $this->assertStringNotContainsString('Sample Draft', json_encode($page));

        // The desk's list is the same stays, without the diagnosis.
        $desk = $this->overview('frontdesk')['discharge_summaries'];
        $this->assertSame([$latest->id, $older->id], array_column($desk, 'admission_id'));
        $this->assertSame([false, false], array_map(fn ($row) => array_key_exists('diagnosis', $row), $desk));
        $this->assertSame('2026-11-27', $desk[0]['follow_up_on']);
    }

    public function test_medicines_prescribed_are_the_latest_finished_visits_and_what_went_home(): void
    {
        $other = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Sample Devi']);
        for ($day = 1; $day <= 12; $day++) {
            $this->visit(sprintf('2026-10-%02d 10:00', $day), ["Sample Medicine {$day}"]);
        }
        $this->visit('2026-11-02 10:00', []); // finished, nothing prescribed: not a prescription
        $this->visit('2026-11-20 09:30', ['Sample Still On The Pad'], false);
        $latest = $this->visit('2026-11-12 10:00', [
            ['medicine' => 'Sample Amoxicillin 500', 'dose' => '1 tab', 'frequency' => '1-0-1', 'timing' => 'After food', 'duration' => '5 days', 'instructions' => 'Finish the course'],
            ['medicine' => 'Sample Pantoprazole 40'],
        ], true, $other);
        $stay = $this->stay('2026-11-14 09:00', '2026-11-18 11:00', $this->approved(['medicines' => [['medicine' => 'Sample Cefixime 200', 'frequency' => '1-0-1', 'duration' => '5 days']]]));
        // A stay that ended in death sends nothing home.
        $this->stay('2026-11-05 09:00', '2026-11-06 09:00', $this->approved(['medicines' => [['medicine' => 'Sample Not Taken Home']]]), 'died');

        $rows = $this->overview('doctor')['medicines_prescribed'];
        $this->assertCount(11, $rows); // ten prescriptions, and one discharge
        $this->assertSame([
            'key' => "d{$stay->id}", 'kind' => 'discharge', 'date' => '2026-11-18 11:00', 'doctor' => ['id' => $this->doctor->id, 'name' => 'Dr Sample Rao'],
            'admission_id' => $stay->id, 'ipd_no' => $stay->ipd_no, 'medicines' => [['medicine' => 'Sample Cefixime 200', 'directions' => '1-0-1 · 5 days', 'medicine_id' => null]],
        ], $rows[0]);
        // Every doctor's prescriptions, the newest first.
        $this->assertSame([
            'key' => "v{$latest->id}", 'kind' => 'visit', 'date' => '2026-11-12 10:00', 'doctor' => ['id' => $other->id, 'name' => 'Dr Sample Devi'], 'appointment_id' => $latest->id,
            'medicines' => [
                ['medicine' => 'Sample Amoxicillin 500', 'directions' => '1 tab · 1-0-1 · After food · 5 days · Finish the course', 'medicine_id' => null],
                ['medicine' => 'Sample Pantoprazole 40', 'directions' => '', 'medicine_id' => null],
            ],
        ], $rows[1]);
        $this->assertSame(['Sample Medicine 12', 'Sample Medicine 4'], [$rows[2]['medicines'][0]['medicine'], $rows[10]['medicines'][0]['medicine']]);
        $words = json_encode($rows);
        $this->assertStringNotContainsString('Still On The Pad', $words);
        $this->assertStringNotContainsString('Not Taken Home', $words);
    }

    public function test_a_medicine_opens_from_the_list_only_when_exactly_one_active_medicine_has_its_very_name(): void
    {
        $paracetamol = Medicine::create(['name' => 'Sample Paracetamol 650mg Tablet', 'gst_percent' => 12, 'units_per_pack' => 10]);
        Medicine::create(['name' => 'Sample Dolo 650', 'gst_percent' => 12, 'units_per_pack' => 10]);
        Medicine::create(['name' => 'sample dolo 650', 'gst_percent' => 12, 'units_per_pack' => 15]); // the same name twice: neither is chosen
        Medicine::create(['name' => 'Sample Old Brand', 'gst_percent' => 12, 'units_per_pack' => 10, 'is_active' => false]);
        $this->visit('2026-11-12 10:00', ['  sample paracetamol 650MG tablet ', 'Sample Dolo 650', 'Sample Old Brand', 'Sample Paracetamol']);

        $ids = fn (array $page) => array_column($page['medicines_prescribed'][0]['medicines'], 'medicine_id');
        $this->assertSame([$paracetamol->id, null, null, null], $ids($this->overview('pharmacist')));
        $this->assertSame([$paracetamol->id, null, null, null], $ids($this->overview('manager')));
        // A nurse cannot open the pharmacy's medicines, so nothing is matched for her.
        $nurse = $this->overview('nurse')['medicines_prescribed'][0]['medicines'];
        $this->assertSame([false], array_unique(array_map(fn ($m) => array_key_exists('medicine_id', $m), $nurse)));
    }

    public function test_monthly_lists_are_the_active_and_paused_ones_with_the_next_due_date(): void
    {
        $medicine = Medicine::create(['name' => 'Sample Metformin 500', 'gst_percent' => 5, 'units_per_pack' => 10]);
        $due = $this->monthlyList(['items' => [['medicine_id' => $medicine->id, 'name' => 'Sample Metformin 500', 'quantity' => 60], ['name' => 'Sample typed by the patient', 'quantity' => 30]]]);
        $paused = $this->monthlyList(['title' => 'Sample paused list', 'status' => 'paused', 'next_due_on' => '2026-11-22', 'day_of_month' => 22]);
        $later = $this->monthlyList(['title' => 'Sample later list', 'next_due_on' => '2026-12-05', 'day_of_month' => 5, 'last_prepared_on' => '2026-11-05']);
        $this->monthlyList(['title' => 'Sample cancelled list', 'status' => 'cancelled']);
        $someoneElse = Patient::factory()->create();
        MedicineSubscription::create(['patient_account_id' => $this->account->id, 'patient_id' => $someoneElse->id, 'title' => 'Sample family list', 'items' => [], 'day_of_month' => 1, 'status' => 'active', 'next_due_on' => '2026-12-01']);

        $lists = $this->overview('pharmacist')['monthly_medicines'];
        $this->assertSame([$paused->id, $due->id, $later->id], array_column($lists, 'id'));
        $this->assertSame([
            'id' => $due->id, 'title' => 'Sample sugar medicines', 'status' => 'active', 'day_of_month' => 23, 'next_due_on' => '2026-11-23', 'due_soon' => true,
            'last_prepared_on' => null, 'notes' => null,
            'items' => [['medicine_id' => $medicine->id, 'name' => 'Sample Metformin 500', 'quantity' => 60], ['medicine_id' => null, 'name' => 'Sample typed by the patient', 'quantity' => 30]],
        ], $lists[1]);
        $this->assertSame([false, true, false], array_column($lists, 'due_soon')); // paused is never due; December is beyond the week
        $this->assertSame('2026-11-05', $lists[2]['last_prepared_on']);
        $this->assertArrayNotHasKey('patient_account_id', $lists[0]);
    }

    public function test_the_lists_stay_short(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $this->stay(sprintf('2026-08-%02d 09:00', $i + 1), sprintf('2026-08-%02d 18:00', $i + 1), $this->approved(['medicines' => [['medicine' => 'Sample Zinc']]]));
            $this->monthlyList(['title' => "Sample list {$i}"]);
        }

        $page = $this->overview('doctor');
        $this->assertCount(20, $page['discharge_summaries']); // the stays the page lists
        $this->assertCount(20, $page['monthly_medicines']);
        $this->assertCount(20, $page['medicines_prescribed']); // one take-home list per approved stay listed
    }

    public function test_odd_writing_on_a_summary_does_not_stop_the_page(): void
    {
        $this->stay('2026-11-01 09:00', '2026-11-02 09:00', $this->approved(['medicines' => null, 'diagnosis' => null]));
        $this->stay('2026-11-10 09:00', '2026-11-11 09:00', $this->approved(['medicines' => [['dose' => '1 tab'], ['medicine' => '   '], 'Sample loose text', ['medicine' => 'Sample Zinc', 'frequency' => null]]]));
        $this->monthlyList(['items' => [['name' => 'Sample loose item'], 'Sample not a row']]);

        $page = $this->overview('doctor');
        $this->assertSame(['Sample enteric fever', null], array_map(fn ($row) => $row['diagnosis'], $page['discharge_summaries']));
        $this->assertCount(1, $page['medicines_prescribed']);
        $this->assertSame([['medicine' => 'Sample Zinc', 'directions' => '', 'medicine_id' => null]], $page['medicines_prescribed'][0]['medicines']);
        $this->assertSame([['medicine_id' => null, 'name' => 'Sample loose item', 'quantity' => null]], $page['monthly_medicines'][0]['items']);
    }

    public function test_a_diagnosis_in_telugu_or_with_a_sign_comes_through_whole(): void
    {
        // Both 'అ' and '★' are written with a byte that a careless search for line breaks also takes.
        $this->stay('2026-11-01 09:00', '2026-11-02 09:00', $this->approved(['diagnosis' => "అతిసారం (Sample diarrhoea)\nTreated and settled"]));
        $this->stay('2026-11-10 09:00', '2026-11-11 09:00', $this->approved(['diagnosis' => "★ Sample fever, stage Ⅳ\r\nSent home"]));

        $page = $this->overview('doctor');
        $this->assertSame(['★ Sample fever, stage Ⅳ', 'అతిసారం (Sample diarrhoea)'], array_column($page['discharge_summaries'], 'diagnosis'));
    }

    public function test_opening_the_page_is_still_noted_once(): void
    {
        $this->visit('2026-11-10 11:00', ['Sample Paracetamol 650']);
        $this->stay('2026-11-14 09:00', '2026-11-18 11:00', $this->approved());
        $this->monthlyList();

        $this->overview('doctor');
        $this->assertSame(['patient.opened'], AuditLog::pluck('action')->all());
    }

    public function test_anyone_who_may_look_at_the_pharmacy_reads_the_monthly_lists_and_only_those_who_run_it_mark_a_pack(): void
    {
        $list = $this->monthlyList();

        // The Hospital Manager is shown the screen, and now reads it; marking the pack stays with the pharmacy.
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->getJson('/api/pharmacy/subscriptions')->assertOk()->assertJsonPath('subscriptions.0.id', $list->id)->assertJsonPath('counts.due', 1);
        $this->postJson("/api/pharmacy/subscriptions/{$list->id}/prepared")->assertForbidden();
        $this->assertNull($list->fresh()->last_prepared_on);

        Sanctum::actingAs(User::factory()->create(['role' => 'accountant', 'permissions' => ['canViewPharmacy' => true]]));
        $this->getJson('/api/pharmacy/subscriptions')->assertOk();

        foreach (['frontdesk', 'nurse', 'accountant'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/pharmacy/subscriptions')->assertForbidden();
        }

        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->postJson("/api/pharmacy/subscriptions/{$list->id}/prepared")->assertOk()->assertJsonPath('last_prepared_on', '2026-11-20');
    }
}
