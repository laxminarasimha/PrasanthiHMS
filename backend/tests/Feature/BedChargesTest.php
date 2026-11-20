<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AdmissionBed;
use App\Models\AdmissionCharge;
use App\Models\Bed;
use App\Models\DayClose;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BedChargesTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private Bed $general;

    private Bed $icu;

    protected function setUp(): void
    {
        parent::setUp();
        // A fixed moment, so "a day of stay" in these tests always means the same thing.
        $this->travelTo('2026-03-10 09:00:00');
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($this->doctor);
        $this->general = Bed::create(['ward' => 'General Ward', 'bed_no' => '1', 'daily_charge' => 800]);
        $this->icu = Bed::create(['ward' => 'ICU', 'bed_no' => '1', 'daily_charge' => 3000]);
    }

    private function admit(array $overrides = []): array
    {
        return $this->postJson('/api/ipd/admissions', $overrides + [
            'patient_id' => Patient::factory()->create()->id, 'doctor_id' => $this->doctor->id, 'bed_id' => $this->general->id,
            'admitted_at' => now()->format('Y-m-d H:i'), 'reason' => 'Fever for evaluation',
        ])->assertCreated()->json();
    }

    /** The bed lines on the case as "day: amount", after opening the case the way the screen does. */
    private function bedLines(int $id): array
    {
        return collect($this->getJson("/api/ipd/admissions/{$id}")->assertOk()->json('charges'))
            ->where('source_type', 'bed')->mapWithKeys(fn ($c) => [$c['source_id'] => $c['amount']])->all();
    }

    public function test_every_bed_of_a_stay_is_kept_with_when_the_patient_moved_in_and_out(): void
    {
        $case = $this->admit();
        $url = "/api/ipd/admissions/{$case['id']}";
        $this->assertSame('General Ward', $case['bed_stays'][0]['ward']);
        $this->assertNull($case['bed_stays'][0]['to_at']);

        $this->travel(30)->hours();
        $moved = $this->putJson($url, ['bed_id' => $this->icu->id])->assertOk()->json('bed_stays');
        $this->assertSame(['General Ward', 'ICU'], array_column($moved, 'ward'));
        $this->assertSame('2026-03-11 15:00', $moved[0]['to_at']);
        $this->assertSame('2026-03-11 15:00', $moved[1]['from_at']);
        $this->assertSame($this->doctor->name, $moved[1]['mover']['name']);

        // Leaving gives the bed up at the time of leaving, even when that is entered a little later; reopening takes it again from then.
        $this->travel(12)->hours();
        $this->postJson("{$url}/discharge", ['status' => 'discharged', 'discharged_at' => '2026-03-12 01:00'])->assertOk()->assertJsonPath('bed_stays.1.to_at', '2026-03-12 01:00');
        $this->postJson("{$url}/reopen", ['reason' => 'Closed by mistake'])->assertOk()->assertJsonCount(3, 'bed_stays')->assertJsonPath('bed_stays.2.from_at', '2026-03-12 03:00');

        // The bed report counts each bed for the time it was really used, and the history lists every move.
        $beds = collect($this->getJson('/api/ipd/reports/beds?from=2026-03-10&to=2026-03-12')->assertOk()->json('rows'))->keyBy('ward');
        $this->assertEquals(1.3, $beds['General Ward']['days_used']); // 30 hours
        $this->assertEquals(0.4, $beds['ICU']['days_used']); // 10 hours, and the 2 hours since reopening have not ended a full tenth yet
        $this->getJson('/api/ipd/reports/moves?from=2026-03-10&to=2026-03-12')->assertOk()->assertJsonCount(3, 'rows')->assertJsonPath('rows.0.until', 'Still there');
    }

    public function test_nothing_is_added_until_automatic_bed_charges_are_switched_on(): void
    {
        $case = $this->admit();
        $this->travel(50)->hours();
        $this->assertSame([], $this->bedLines($case['id']));
        $this->getJson("/api/ipd/admissions/{$case['id']}")->assertJsonPath('bed_charges_auto', false)->assertJsonPath('total', 0);

        // Only someone who manages setup can switch it on.
        Sanctum::actingAs(User::factory()->create(['role' => 'nurse']));
        $this->putJson('/api/ipd/bed-charging', ['auto' => true])->assertForbidden();
        Sanctum::actingAs($this->doctor);
        $this->putJson('/api/ipd/bed-charging', ['auto' => true])->assertOk()->assertJsonPath('auto', true);

        // The three days that had already begun stay as the staff left them. Day 4 begins at 72 hours and is the first one added.
        $this->assertSame([], $this->bedLines($case['id']));
        $this->travel(23)->hours();
        $this->assertSame([4 => 800], $this->bedLines($case['id']));
    }

    public function test_each_day_of_stay_is_charged_once_for_the_bed_the_patient_is_in_when_it_begins(): void
    {
        $this->putJson('/api/ipd/bed-charging', ['auto' => true])->assertOk();
        $case = $this->admit();
        $url = "/api/ipd/admissions/{$case['id']}";

        // Day 1 goes on at admission; opening the case again and again adds nothing more.
        $this->assertSame([1 => 800], $this->bedLines($case['id']));
        $this->travel(23)->hours();
        $this->assertSame([1 => 800], $this->bedLines($case['id']));
        $this->travel(2)->hours();
        $this->assertSame([1 => 800, 2 => 800], $this->bedLines($case['id']));
        $line = AdmissionCharge::where('source_id', 2)->where('source_type', 'bed')->sole();
        $this->assertSame('Bed charge, day 2 (General Ward · Bed 1)', $line->description);
        $this->assertSame('2026-03-11', $line->charged_on->toDateString());
        $this->assertNull($line->created_by);

        // Moved to the ICU during day 2: day 2 stays at the general rate, day 3 is at the ICU rate.
        $this->putJson($url, ['bed_id' => $this->icu->id])->assertOk();
        $this->travel(24)->hours();
        $this->assertSame([1 => 800, 2 => 800, 3 => 3000], $this->bedLines($case['id']));
        $this->getJson($url)->assertJsonPath('total', 4600);

        // A line can be corrected or taken off like any other, and a line taken off does not come back.
        $day3 = AdmissionCharge::where('source_id', 3)->where('source_type', 'bed')->sole();
        $this->putJson("{$url}/charges/{$day3->id}", ['charged_on' => '2026-03-12', 'quantity' => 1, 'unit_price' => 2500])->assertOk()->assertJsonPath('total', 4100);
        $this->deleteJson("{$url}/charges/{$day3->id}")->assertOk()->assertJsonPath('total', 1600);
        $this->assertSame([1 => 800, 2 => 800], $this->bedLines($case['id']));

        // Switched off for this patient (a package, say): day 4 is left alone, and is not filled in when it is switched back on.
        $this->putJson($url, ['auto_bed_charge' => false])->assertOk();
        $this->travel(24)->hours();
        $this->assertSame([1 => 800, 2 => 800], $this->bedLines($case['id']));
        $this->putJson($url, ['auto_bed_charge' => true])->assertOk();
        $this->travel(24)->hours();
        $this->assertSame([1 => 800, 2 => 800, 5 => 3000], $this->bedLines($case['id']));

        // Leaving during day 5 adds nothing more, then or later.
        $this->postJson("{$url}/discharge", ['status' => 'discharged', 'discharged_at' => now()->format('Y-m-d H:i')])->assertOk();
        $this->travel(72)->hours();
        $this->assertSame([1 => 800, 2 => 800, 5 => 3000], $this->bedLines($case['id']));
    }

    public function test_days_missed_while_nobody_looked_are_caught_up_and_a_closed_day_is_not_written_into(): void
    {
        $this->putJson('/api/ipd/bed-charging', ['auto' => true])->assertOk();
        $case = $this->admit();
        $this->assertSame([1 => 800], $this->bedLines($case['id']));

        // Nobody opens the case for three days, and the second day's accounts are closed meanwhile.
        DayClose::create(['day' => '2026-03-11', 'closed_by_name' => 'Admin', 'closed_at' => now(), 'expected_cash' => 0, 'counted_cash' => 0, 'variance' => 0, 'snapshot' => []]);
        $this->travel(73)->hours();

        // The list of cases is enough to bring every open case up to date.
        $this->getJson('/api/ipd/admissions')->assertOk()->assertJsonPath('0.total', 3200);
        $lines = AdmissionCharge::where('admission_id', $case['id'])->orderBy('source_id')->get();
        $this->assertSame([1, 2, 3, 4], $lines->pluck('source_id')->all());
        // Day 2 belongs to the closed day, so it is written today and says which day it is for.
        $this->assertSame('2026-03-13', $lines[1]->charged_on->toDateString());
        $this->assertStringContainsString('For 11 Mar 2026', $lines[1]->remarks);
        $this->assertSame('2026-03-12', $lines[2]->charged_on->toDateString());
        $this->assertNull($lines[2]->remarks);

        // An approved bill is locked, so nothing is added to it until the approval is withdrawn.
        Admission::whereKey($case['id'])->update(['bill_approved_at' => now()]);
        $this->travel(24)->hours();
        $this->assertCount(4, $this->bedLines($case['id']));
        Admission::whereKey($case['id'])->update(['bill_approved_at' => null]);
        $this->assertCount(5, $this->bedLines($case['id']));
    }

    public function test_a_patient_admitted_in_the_same_minute_it_is_switched_on_is_charged_from_day_one(): void
    {
        // Half a minute past: the admission typed a moment later is still stamped 09:00.
        $this->travelTo('2026-03-10 09:00:30');
        $this->putJson('/api/ipd/bed-charging', ['auto' => true])->assertOk()->assertJsonPath('from', '2026-03-10 09:00:00');
        $this->assertSame([1 => 800], $this->bedLines($this->admit()['id']));
    }

    public function test_a_case_whose_bed_was_set_without_any_history_gets_its_history_when_next_opened(): void
    {
        $this->putJson('/api/ipd/bed-charging', ['auto' => true])->assertOk();
        // As a case admitted by the older version of the app would look: a bed, and no history rows.
        $old = Admission::create(['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $this->doctor->id, 'bed_id' => $this->general->id, 'admitted_at' => now()]);
        $this->assertSame(0, AdmissionBed::count());

        $this->travel(2)->hours();
        $this->getJson("/api/ipd/admissions/{$old->id}")->assertOk()->assertJsonCount(1, 'bed_stays')->assertJsonPath('bed_stays.0.from_at', '2026-03-10 09:00')->assertJsonPath('bed_stays.0.to_at', null);
        $this->assertSame([1 => 800], $this->bedLines($old->id));
        $this->getJson("/api/ipd/admissions/{$old->id}")->assertOk()->assertJsonCount(1, 'bed_stays');
    }

    public function test_a_bed_given_a_few_hours_after_admission_is_still_charged_for_that_day(): void
    {
        $this->putJson('/api/ipd/bed-charging', ['auto' => true])->assertOk();
        $case = $this->admit(['bed_id' => null]);
        $this->assertSame([], $this->bedLines($case['id']));
        $this->assertSame(0, AdmissionBed::count());

        $this->travel(3)->hours();
        $this->putJson("/api/ipd/admissions/{$case['id']}", ['bed_id' => $this->icu->id])->assertOk();
        $this->assertSame([1 => 3000], $this->bedLines($case['id']));

        // A bed with no daily rate adds nothing.
        $free = Bed::create(['ward' => 'Observation', 'bed_no' => '1', 'daily_charge' => 0]);
        $other = $this->admit(['bed_id' => $free->id]);
        $this->travel(30)->hours();
        $this->assertSame([], $this->bedLines($other['id']));
    }

    public function test_a_discharge_time_entered_wrongly_is_corrected_and_the_bed_charges_follow(): void
    {
        $this->putJson('/api/ipd/bed-charging', ['auto' => true])->assertOk();
        $case = $this->admit();
        $url = "/api/ipd/admissions/{$case['id']}";

        // Discharged at 9 PM on day 3 when it should have been entered as day 4: three bed days were charged.
        $this->travelTo('2026-03-14 12:00:00');
        $this->postJson("{$url}/discharge", ['status' => 'discharged', 'discharged_at' => '2026-03-12 21:00'])->assertOk();
        $this->assertEquals([1 => 800, 2 => 800, 3 => 800], $this->bedLines($case['id']));

        // Only a closed case, with a reason, within the stay and not in the future.
        $this->postJson("{$url}/discharge-time", ['discharged_at' => '2026-03-13 11:30'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("{$url}/discharge-time", ['discharged_at' => '2026-03-09 11:30', 'reason' => 'Typed wrongly'])->assertStatus(422)->assertJsonValidationErrors('discharged_at');
        $this->postJson("{$url}/discharge-time", ['discharged_at' => '2026-03-15 11:30', 'reason' => 'Typed wrongly'])->assertStatus(422)->assertJsonValidationErrors('discharged_at');

        // Later: the fourth day began before the patient left, so it is charged; the bed is held until the new time.
        $this->postJson("{$url}/discharge-time", ['discharged_at' => '2026-03-13 11:30', 'reason' => 'Left the next morning; the evening time was typed by mistake'])
            ->assertOk()->assertJsonPath('discharged_at', '2026-03-13 11:30')->assertJsonPath('bed_stays.0.to_at', '2026-03-13 11:30');
        $this->assertEquals([1 => 800, 2 => 800, 3 => 800, 4 => 800], $this->bedLines($case['id']));

        // Earlier: the days that no longer began come off, and are kept on record as removed lines.
        $this->postJson("{$url}/discharge-time", ['discharged_at' => '2026-03-11 10:00', 'reason' => 'Went home on day 2 morning'])->assertOk()->assertJsonPath('bed_stays.0.to_at', '2026-03-11 10:00');
        $this->assertEquals([1 => 800, 2 => 800], $this->bedLines($case['id']));
        $this->assertSame(2, AdmissionCharge::onlyTrashed()->where('admission_id', $case['id'])->where('source_type', 'bed')->count());
        $this->assertSame(2, \App\Models\AuditLog::where('action', 'ipd.discharge_time')->count());
        // Leaving before a day began means that day is not owed: 8 AM on the 11th is before day 2 began at 9.
        $this->assertSame(1, $this->postJson("{$url}/discharge-time", ['discharged_at' => '2026-03-11 08:00', 'reason' => 'Checked the register'])->assertOk()->json('bed_days_done'));
        $this->postJson("{$url}/discharge-time", ['discharged_at' => '2026-03-11 10:00', 'reason' => 'Register was right the first time'])->assertOk();
        $this->assertEquals([1 => 800, 2 => 800], $this->bedLines($case['id']));

        // A day whose accounts are closed keeps its charges: the correction is refused rather than change that day's money.
        DayClose::create(['day' => '2026-03-11', 'closed_by_name' => 'Admin', 'closed_at' => now(), 'expected_cash' => 0, 'counted_cash' => 0, 'variance' => 0, 'snapshot' => []]);
        $this->postJson("{$url}/discharge-time", ['discharged_at' => '2026-03-10 18:00', 'reason' => 'Went home on day 1'])->assertStatus(423);
        $this->assertEquals([1 => 800, 2 => 800], $this->bedLines($case['id']));

        // A case still in the hospital is discharged, not corrected; an approved bill is final.
        $other = $this->admit();
        $this->postJson("/api/ipd/admissions/{$other['id']}/discharge-time", ['discharged_at' => '2026-03-14 10:00', 'reason' => 'x x x'])->assertStatus(422);
        $this->postJson("{$url}/approve-bill", ["approved" => true])->assertOk();
        $this->postJson("{$url}/discharge-time", ['discharged_at' => '2026-03-11 09:00', 'reason' => 'One more fix'])->assertStatus(422)->assertJsonValidationErrors('bill');
    }
}
