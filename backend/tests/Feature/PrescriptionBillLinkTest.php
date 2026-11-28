<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Bed;
use App\Models\Consultation;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PharmacySale;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A doctor's prescription reaching the pharmacy counter: the tablets a plain prescription comes to, a bill made from a
 * visit's card remembering that visit, the checks on it, "filled" against "billed since", and To do showing a doctor
 * only their own patients. Made-up patients, staff and medicines.
 */
class PrescriptionBillLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private User $pharmacist;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-24 10:00');
        $this->doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Sample One']);
        $this->pharmacist = User::factory()->create(['role' => 'pharmacist']);
        $this->patient = Patient::factory()->create(['name' => 'Sample Patient']);
        Sanctum::actingAs($this->pharmacist);
    }

    /** A medicine with batches on sale, each [units in stock, units to a pack], the first expiring soonest. */
    private function stock(string $name, ?string $type, array $batches = [[50, 10]]): Medicine
    {
        $medicine = Medicine::create(['name' => $name, 'type' => $type, 'gst_percent' => 12, 'units_per_pack' => 10]);
        foreach ($batches as $i => [$units, $perPack]) {
            StockBatch::create([
                'medicine_id' => $medicine->id, 'batch_no' => 'S'.($i + 1), 'expiry' => now()->addMonths(6 + $i), 'units_per_pack' => $perPack, 'packs' => 5,
                'pack_price' => 15, 'pack_mrp' => 50, 'unit_mrp' => 5, 'gst_percent' => 12, 'units_received' => $units, 'units_in_stock' => $units,
            ]);
        }

        return $medicine;
    }

    /** A visit with this doctor, saved with these medicines and finished unless said otherwise. Its consultation's id. */
    private function visit(array $medicines, ?User $doctor = null, ?Patient $patient = null, bool $finish = true): int
    {
        $doctor ??= $this->doctor;
        $appointment = Appointment::create(['patient_id' => ($patient ?? $this->patient)->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now(), 'status' => 'arrived']);
        Sanctum::actingAs($doctor);
        $this->putJson("/api/appointments/{$appointment->id}/consultation", ['complete' => $finish, 'medicines' => $medicines])->assertOk();
        Sanctum::actingAs($this->pharmacist);

        return Consultation::where('appointment_id', $appointment->id)->value('id');
    }

    /** A pharmacy bill of one unit of the first batch of this medicine. */
    private function sell(Medicine $medicine, array $extra = [], ?Patient $patient = null)
    {
        $patient ??= $this->patient;

        return $this->postJson('/api/pharmacy/sales', $extra + [
            'patient_id' => $patient->id, 'customer_name' => $patient->name, 'paid' => 0, 'payment_mode' => 'cash',
            'items' => [['stock_batch_id' => $medicine->sellableBatches()->value('id'), 'quantity' => 1]],
        ]);
    }

    private function cards(?Patient $patient = null): array
    {
        return $this->getJson('/api/pharmacy/prescriptions?patient_id='.($patient ?? $this->patient)->id)->assertOk()->json();
    }

    /** The signed-in person's waiting work, each line by "section.key". */
    private function todo(): array
    {
        return collect($this->getJson('/api/todo')->assertOk()->json('waiting'))
            ->flatMap(fn ($section) => collect($section['items'])->mapWithKeys(fn ($i) => [$section['key'].'.'.$i['key'] => $i]))->all();
    }

    public function test_a_visit_card_offers_the_tablets_a_plain_prescription_comes_to_and_nothing_else(): void
    {
        $this->stock('Sample Amlodipine 5 Tablet', 'Tablet');
        $this->stock('Sample Cefixime 200 Capsule', 'Capsule');
        $this->stock('Sample Cough Syrup', 'Syrup', [[20, 1]]);
        $this->stock('Sample Untyped 10', null);
        $this->stock('Sample Loose Tablet', 'Tablet', [[30, 1]]);
        $this->stock('Sample Twin Tablet', 'Tablet');
        $this->stock('Sample Twin Tablet', 'Tablet');
        $this->stock('Sample Pain Tablet', 'Tablet');
        $this->visit([
            ['medicine' => 'Sample Amlodipine 5 Tablet', 'frequency' => '1-0-1', 'duration' => '5 days'],
            ['medicine' => 'Sample Cefixime 200 Capsule', 'dose' => '1 cap', 'frequency' => 'BD', 'duration' => '1 week'],
            ['medicine' => 'Sample Cough Syrup', 'dose' => '5 ml', 'frequency' => 'TDS', 'duration' => '5 days'],
            ['medicine' => 'Sample Untyped 10', 'frequency' => '1-0-1', 'duration' => '5 days'],
            ['medicine' => 'Sample Loose Tablet', 'frequency' => '1-0-1', 'duration' => '5 days'],
            ['medicine' => 'Sample Twin Tablet', 'frequency' => '1-0-1', 'duration' => '5 days'],
            ['medicine' => 'Sample Pain Tablet', 'dose' => '1 tab', 'frequency' => 'SOS', 'duration' => '3 days'],
            ['medicine' => 'Sample Not Stocked', 'frequency' => '1-0-1', 'duration' => '5 days'],
        ]);

        $card = $this->cards()[0];
        $this->assertSame('visit', $card['kind']);
        $lines = collect($card['medicines'])->keyBy('medicine');
        $this->assertSame([10, '1-0-1 for 5 days = 10'], [$lines['Sample Amlodipine 5 Tablet']['quantity'], $lines['Sample Amlodipine 5 Tablet']['worked_out']]);
        $this->assertSame([14, '1 cap BD for 1 week (7 days) = 14'], [$lines['Sample Cefixime 200 Capsule']['quantity'], $lines['Sample Cefixime 200 Capsule']['worked_out']]);
        // The directions are shown as written, as before.
        $this->assertSame('1-0-1 · 5 days', $lines['Sample Amlodipine 5 Tablet']['directions']);

        // A syrup, a medicine with no type, one sold whole, a name two medicines share, SOS, and one not stocked: no count.
        foreach (['Sample Cough Syrup', 'Sample Untyped 10', 'Sample Loose Tablet', 'Sample Twin Tablet', 'Sample Pain Tablet', 'Sample Not Stocked'] as $name) {
            $this->assertNull($lines[$name]['quantity'], $name);
            $this->assertNull($lines[$name]['worked_out'], $name);
        }
        // The shared name is still offered for sale, as before; only its count is not worked out.
        $this->assertNotNull($lines['Sample Twin Tablet']['match']);
        // Nothing has been billed for it yet.
        $this->assertNull($card['bill']);
        $this->assertFalse($card['filled']);
    }

    public function test_a_visit_line_whose_when_or_instructions_may_change_how_often_gets_no_count_and_its_instructions_are_shown(): void
    {
        foreach (['Sample Weekly Tablet', 'Sample Alternate Tablet', 'Sample Fever Tablet', 'Sample Daily Tablet'] as $name) {
            $this->stock($name, 'Tablet');
        }
        $this->visit([
            ['medicine' => 'Sample Weekly Tablet', 'dose' => '1 tablet', 'frequency' => '1-0-0', 'timing' => 'After food', 'duration' => '1 month', 'instructions' => 'Once a week'],
            ['medicine' => 'Sample Alternate Tablet', 'dose' => '1 tablet', 'frequency' => '1-0-0', 'timing' => 'Alternate days', 'duration' => '1 month'],
            ['medicine' => 'Sample Fever Tablet', 'dose' => '1 tablet', 'frequency' => '1-1-1', 'timing' => 'SOS', 'duration' => '5 days', 'instructions' => 'Only if fever'],
            ['medicine' => 'Sample Daily Tablet', 'dose' => '1 tablet', 'frequency' => '1-0-1', 'timing' => 'After food', 'duration' => '5 days'],
        ]);

        $lines = collect($this->cards()[0]['medicines'])->keyBy('medicine');
        // Once a week, alternate days and SOS are never counted as every day.
        foreach (['Sample Weekly Tablet', 'Sample Alternate Tablet', 'Sample Fever Tablet'] as $name) {
            $this->assertSame([null, null], [$lines[$name]['quantity'], $lines[$name]['worked_out']], $name);
        }
        // An ordinary When, such as after food, still gets the count.
        $this->assertSame([10, '1 tablet 1-0-1 for 5 days = 10'], [$lines['Sample Daily Tablet']['quantity'], $lines['Sample Daily Tablet']['worked_out']]);
        // The pharmacist sees the instructions with the rest of the directions.
        $this->assertSame('1 tablet · 1-0-0 · After food · 1 month · Once a week', $lines['Sample Weekly Tablet']['directions']);
        $this->assertSame('1 tablet · 1-1-1 · SOS · 5 days · Only if fever', $lines['Sample Fever Tablet']['directions']);
        $this->assertSame('1 tablet · 1-0-1 · After food · 5 days', $lines['Sample Daily Tablet']['directions']);
    }

    public function test_a_take_home_line_whose_when_or_instructions_may_change_how_often_gets_no_count_and_its_instructions_are_shown(): void
    {
        foreach (['Sample Weekly Tablet', 'Sample Alternate Tablet', 'Sample Daily Tablet'] as $name) {
            $this->stock($name, 'Tablet');
        }
        Sanctum::actingAs($this->doctor);
        $case = $this->postJson('/api/ipd/admissions', [
            'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'bed_id' => Bed::create(['ward' => 'General Ward', 'bed_no' => '1', 'daily_charge' => 800])->id,
            'admitted_at' => now()->subDay()->format('Y-m-d H:i'),
        ])->assertCreated()->json();
        $this->putJson("/api/ipd/admissions/{$case['id']}/summary", ['diagnosis' => 'Sample', 'medicines' => [
            ['medicine' => 'Sample Weekly Tablet', 'dose' => '1 tab', 'frequency' => 'OD', 'timing' => 'After food', 'duration' => '1 month', 'instructions' => 'Once a week'],
            ['medicine' => 'Sample Alternate Tablet', 'dose' => '1 tab', 'frequency' => 'OD', 'timing' => 'Alternate days', 'duration' => '1 month'],
            ['medicine' => 'Sample Daily Tablet', 'dose' => '1 tab', 'frequency' => 'OD', 'timing' => 'After food', 'duration' => '1 month'],
        ]])->assertOk();

        Sanctum::actingAs($this->pharmacist);
        $lines = collect(collect($this->cards())->firstWhere('kind', 'discharge')['medicines'])->keyBy('medicine');
        foreach (['Sample Weekly Tablet', 'Sample Alternate Tablet'] as $name) {
            $this->assertSame([null, null], [$lines[$name]['quantity'], $lines[$name]['worked_out']], $name);
        }
        $this->assertSame([30, '1 tab OD for 1 month (30 days) = 30'], [$lines['Sample Daily Tablet']['quantity'], $lines['Sample Daily Tablet']['worked_out']]);
        $this->assertSame('1 tab · OD · After food · 1 month · Once a week', $lines['Sample Weekly Tablet']['directions']);
        $this->assertSame('1 tab · OD · Alternate days · 1 month', $lines['Sample Alternate Tablet']['directions']);
    }

    public function test_ward_orders_never_get_a_quantity_take_home_medicines_do_and_neither_card_is_ever_filled(): void
    {
        $tablet = $this->stock('Sample Amlodipine 5 Tablet', 'Tablet');
        Sanctum::actingAs($this->doctor);
        $case = $this->postJson('/api/ipd/admissions', [
            'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'bed_id' => Bed::create(['ward' => 'General Ward', 'bed_no' => '1', 'daily_charge' => 800])->id,
            'admitted_at' => now()->subDay()->format('Y-m-d H:i'),
        ])->assertCreated()->json();
        $url = "/api/ipd/admissions/{$case['id']}";
        $this->postJson("{$url}/chart/orders", ['kind' => 'medicine', 'title' => 'Sample Amlodipine 5 Tablet', 'dose' => '1 tab', 'frequency' => 'BD'])->assertCreated();
        $this->putJson("{$url}/summary", ['diagnosis' => 'Sample', 'medicines' => [['medicine' => 'Sample Amlodipine 5 Tablet', 'dose' => '1 tab', 'frequency' => 'OD', 'duration' => '1 month']]])->assertOk();

        Sanctum::actingAs($this->pharmacist);
        $cards = collect($this->cards())->keyBy('kind');
        $this->assertSame(['ward', 'discharge'], $cards->keys()->all());
        $this->assertNull($cards['ward']['medicines'][0]['quantity']);
        $this->assertNull($cards['ward']['medicines'][0]['worked_out']);
        $this->assertSame(30, $cards['discharge']['medicines'][0]['quantity']);
        $this->assertSame('1 tab OD for 1 month (30 days) = 30', $cards['discharge']['medicines'][0]['worked_out']);
        // Only a visit's card can be filled by a bill; these never say so.
        $this->assertArrayNotHasKey('bill', $cards['ward']);
        $this->assertArrayNotHasKey('filled', $cards['discharge']);

        // A bill made for the inpatient from these cards remembers no visit.
        $sale = $this->sell($tablet, ['admission_id' => $case['id']])->assertCreated()->json();
        $this->assertNull($sale['consultation_id']);
        $this->assertNull(PharmacySale::find($sale['id'])->consultation_id);
    }

    public function test_a_bill_made_from_a_visit_card_remembers_the_visit_and_fills_it(): void
    {
        $tablet = $this->stock('Sample Amlodipine 5 Tablet', 'Tablet');
        $visit = $this->visit([['medicine' => 'Sample Amlodipine 5 Tablet', 'frequency' => '1-0-1', 'duration' => '5 days']]);

        $sale = $this->sell($tablet, ['consultation_id' => $visit])->assertCreated()
            ->assertJsonPath('consultation_id', $visit)
            ->assertJsonPath('consultation.completed_at', '2026-11-24 10:00')
            ->assertJsonPath('consultation.doctor.name', 'Dr Sample One')
            ->json();

        $this->getJson('/api/pharmacy/orders')->assertOk()->assertJsonPath('0.bill', ['id' => $sale['id'], 'bill_no' => $sale['bill_no']])->assertJsonPath('0.filled', true);
        $this->assertSame([['id' => $sale['id'], 'bill_no' => $sale['bill_no']], true], [$this->cards()[0]['bill'], $this->cards()[0]['filled']]);

        // The bill, opened on screen, says which prescription it was made from; so does every answer that changes it.
        $this->getJson("/api/pharmacy/sales/{$sale['id']}")->assertOk()->assertJsonPath('consultation.id', $visit)->assertJsonPath('consultation.doctor.id', $this->doctor->id);
        $this->postJson("/api/pharmacy/sales/{$sale['id']}/payments", ['amount' => 5, 'mode' => 'cash'])->assertCreated()->assertJsonPath('consultation.doctor.name', 'Dr Sample One');
        // Nothing clinical comes with it.
        $this->assertSame(['id', 'doctor_id', 'completed_at', 'doctor'], array_keys($this->getJson("/api/pharmacy/sales/{$sale['id']}")->json('consultation')));
    }

    public function test_the_prescription_a_bill_is_made_from_must_be_the_patients_finished_and_not_of_a_later_day(): void
    {
        $tablet = $this->stock('Sample Amlodipine 5 Tablet', 'Tablet');
        $other = Patient::factory()->create(['name' => 'Sample Other']);
        $theirs = $this->visit([['medicine' => 'Sample Amlodipine 5 Tablet']], null, $other);
        $unfinished = $this->visit([['medicine' => 'Sample Amlodipine 5 Tablet']], null, null, false);

        $this->sell($tablet, ['consultation_id' => $theirs])->assertStatus(422)->assertJsonPath('errors.consultation_id.0', 'That prescription is for another patient.');
        // A walk-in's bill cannot be made from a patient's prescription.
        $this->sell($tablet, ['patient_id' => null, 'customer_name' => 'Walk-in', 'consultation_id' => $theirs])->assertStatus(422)->assertJsonValidationErrors('consultation_id');
        $this->sell($tablet, ['consultation_id' => $unfinished])->assertStatus(422)->assertJsonPath('errors.consultation_id.0', 'That visit is not finished yet, so there is no prescription to bill from.');
        $this->sell($tablet, ['consultation_id' => 999])->assertStatus(422)->assertJsonValidationErrors('consultation_id');
        $this->assertSame(0, PharmacySale::count());
        $this->assertSame(50, $tablet->batches()->value('units_in_stock'));

        // A bill entered later for an earlier day may be dated the prescription's day, never before it.
        $visit = $this->visit([['medicine' => 'Sample Amlodipine 5 Tablet', 'frequency' => '1-0-1', 'duration' => '5 days']]);
        $this->travelTo('2026-11-26 09:00');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->sell($tablet, ['consultation_id' => $visit, 'sold_on' => '2026-11-23'])->assertStatus(422)
            ->assertJsonPath('errors.sold_on.0', 'The prescription is of 24 Nov 2026, so a bill made from it cannot be dated earlier.');
        $this->sell($tablet, ['consultation_id' => $visit, 'sold_on' => '2026-11-24'])->assertCreated()->assertJsonPath('consultation_id', $visit);
    }

    public function test_a_bill_made_from_the_card_fills_the_prescription_even_dated_before_the_visit_ended(): void
    {
        $tablet = $this->stock('Sample Amlodipine 5 Tablet', 'Tablet');
        $this->travelTo('2026-11-24 16:00');
        $visit = $this->visit([['medicine' => 'Sample Amlodipine 5 Tablet', 'frequency' => '1-0-1', 'duration' => '5 days']]);

        // Missed on the day and entered two days later: it takes the time of typing, 10:00, on the 24th, before the visit was finished.
        $this->travelTo('2026-11-26 10:00');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $typed = $this->sell($tablet, ['sold_on' => '2026-11-24'])->assertCreated()->json();
        $this->assertSame('2026-11-24 10:00', $typed['sold_at']);
        // Typed without the card, a bill timed before the visit ended is not counted for it.
        $this->getJson('/api/pharmacy/orders?date=2026-11-24')->assertOk()->assertJsonPath('0.bill', null)->assertJsonPath('0.filled', false);

        $fromCard = $this->sell($tablet, ['sold_on' => '2026-11-24', 'consultation_id' => $visit])->assertCreated()->json();
        $this->getJson('/api/pharmacy/orders?date=2026-11-24')->assertJsonPath('0.bill.bill_no', $fromCard['bill_no'])->assertJsonPath('0.filled', true);
    }

    public function test_a_later_bill_not_made_from_the_card_still_counts_as_billed_since(): void
    {
        $tablet = $this->stock('Sample Amlodipine 5 Tablet', 'Tablet');
        // A bill made before the visit counts for nothing.
        $this->sell($tablet)->assertCreated();
        $this->travelTo('2026-11-24 11:00');
        $other = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Sample Two']);
        $first = $this->visit([['medicine' => 'Sample Amlodipine 5 Tablet', 'frequency' => '1-0-1', 'duration' => '5 days']]);
        $this->travelTo('2026-11-24 11:30');
        $second = $this->visit([['medicine' => 'Sample Amlodipine 5 Tablet', 'frequency' => '0-0-1', 'duration' => '5 days']], $other);
        $orders = fn () => collect($this->getJson('/api/pharmacy/orders')->assertOk()->json())->keyBy('id');
        $this->assertSame([null, null], [$orders()[$first]['bill'], $orders()[$second]['bill']]);

        // Typed at the counter without the card: both prescriptions count as billed since, neither as filled.
        $this->travelTo('2026-11-24 12:00');
        $typed = $this->sell($tablet)->assertCreated()->json();
        $this->assertSame([['id' => $typed['id'], 'bill_no' => $typed['bill_no']], false], [$orders()[$first]['bill'], $orders()[$first]['filled']]);
        $this->assertSame([$typed['bill_no'], false], [$orders()[$second]['bill']['bill_no'], $orders()[$second]['filled']]);
        $card = collect($this->cards())->firstWhere('id', $first);
        $this->assertSame([$typed['bill_no'], false], [$card['bill']['bill_no'], $card['filled']]);

        // Then one made from the second doctor's card: that one is filled by it; the first is still billed since.
        $this->travelTo('2026-11-24 12:30');
        $fromCard = $this->sell($tablet, ['consultation_id' => $second])->assertCreated()->json();
        $this->assertSame([$fromCard['bill_no'], true], [$orders()[$second]['bill']['bill_no'], $orders()[$second]['filled']]);
        $this->assertSame([$typed['bill_no'], false], [$orders()[$first]['bill']['bill_no'], $orders()[$first]['filled']]);

        // A deleted bill counts for neither.
        $this->deleteJson("/api/pharmacy/sales/{$fromCard['id']}", ['reason' => 'Made by mistake'])->assertOk();
        $this->assertSame([$typed['bill_no'], false], [$orders()[$second]['bill']['bill_no'], $orders()[$second]['filled']]);
        $this->deleteJson("/api/pharmacy/sales/{$typed['id']}", ['reason' => 'Made by mistake'])->assertOk();
        $this->assertNull($orders()[$first]['bill']);
        $this->assertNull($orders()[$second]['bill']);
    }

    public function test_to_do_shows_a_doctor_only_their_own_patients_prescriptions_and_ward_orders(): void
    {
        $tablet = $this->stock('Sample Amlodipine 5 Tablet', 'Tablet');
        $other = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Sample Two']);
        $theirs = Patient::factory()->create(['name' => 'Sample Other']);
        $mine = $this->visit([['medicine' => 'Sample Amlodipine 5 Tablet', 'frequency' => '1-0-1', 'duration' => '5 days']]);
        $this->visit([['medicine' => 'Sample Amlodipine 5 Tablet', 'frequency' => '0-0-1', 'duration' => '5 days']], $other, $theirs);

        // Each patient admitted under their own doctor, with a medicine ordered on the ward.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([[$this->patient, $this->doctor, '1'], [$theirs, $other, '2']] as [$patient, $doctor, $bed]) {
            $case = $this->postJson('/api/ipd/admissions', [
                'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'bed_id' => Bed::create(['ward' => 'General Ward', 'bed_no' => $bed, 'daily_charge' => 800])->id,
                'admitted_at' => now()->subDay()->format('Y-m-d H:i'),
            ])->assertCreated()->json();
            $this->postJson("/api/ipd/admissions/{$case['id']}/chart/orders", ['kind' => 'medicine', 'title' => 'Sample Amlodipine 5 Tablet', 'dose' => '1 tab', 'frequency' => 'BD'])->assertCreated();
        }
        // A bill of the other doctor's patient with money due.
        $this->travelTo('2026-11-24 09:00');
        Sanctum::actingAs($this->pharmacist);
        $due = $this->sell($tablet, [], $theirs)->assertCreated()->json();
        $this->travelTo('2026-11-24 10:00');

        // The pharmacy sees every patient.
        $todo = $this->todo();
        $this->assertSame(2, $todo['pharmacy.prescriptions']['count']);
        $this->assertSame(2, $todo['pharmacy.ward_orders']['count']);

        // A doctor sees only their own patients on those two lines; the money line is the pharmacy's, as before.
        Sanctum::actingAs($this->doctor);
        $todo = $this->todo();
        $this->assertSame(1, $todo['pharmacy.prescriptions']['count']);
        $this->assertSame('Sample Patient', $todo['pharmacy.prescriptions']['rows'][0]['text']);
        $this->assertSame(1, $todo['pharmacy.ward_orders']['count']);
        $this->assertStringContainsString('Sample Patient', $todo['pharmacy.ward_orders']['rows'][0]['text']);
        $this->assertSame("{$due['bill_no']} · Sample Other", $todo['pharmacy.pharmacy_dues']['rows'][0]['text']);
        // The pharmacy's own screen still lists every prescription for them.
        $this->getJson('/api/pharmacy/orders')->assertOk()->assertJsonCount(2);

        // Filled from the card: off the doctor's list, and off the pharmacy's.
        Sanctum::actingAs($this->pharmacist);
        $this->sell($tablet, ['consultation_id' => $mine])->assertCreated();
        $this->assertSame(1, $this->todo()['pharmacy.prescriptions']['count']);
        Sanctum::actingAs($this->doctor);
        $this->assertArrayNotHasKey('pharmacy.prescriptions', $this->todo());
    }
}
