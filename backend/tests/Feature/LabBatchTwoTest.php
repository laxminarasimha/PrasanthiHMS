<?php

namespace Tests\Feature;

use App\Models\LabTestVersion;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Four lab additions: normal ranges that depend on the patient's age and sex, tests done at an outside lab,
 * the settings of the sample label, and a report of how long reports take.
 */
class LabBatchTwoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'name' => 'Lab Person']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Haemoglobin with a range for children, one for women, one for men, and a plain one for everybody else. */
    private function haemoglobin(?array $ranges = null): array
    {
        return ['name' => 'Haemoglobin', 'sample_type' => 'Blood', 'price' => 100, 'parameters' => [[
            'name' => 'Haemoglobin (Hb)', 'unit' => 'g/dL', 'reference_range' => '12 - 17',
            'ranges' => $ranges ?? [
                ['sex' => null, 'age_from' => 0, 'age_to' => 12, 'age_unit' => 'years', 'range' => '11 - 14'],
                ['sex' => 'female', 'age_from' => null, 'age_to' => null, 'age_unit' => 'years', 'range' => '12 - 15'],
                ['sex' => 'male', 'age_from' => null, 'age_to' => null, 'age_unit' => 'years', 'range' => '13 - 17'],
            ],
        ]]];
    }

    private function rangeFor(int $testId, array $patient): ?string
    {
        $order = $this->postJson('/api/lab/orders', ['patient_id' => Patient::factory()->create($patient)->id, 'test_ids' => [$testId]])->assertCreated()->json();

        return $order['items'][0]['results'][0]['reference_range'];
    }

    public function test_a_report_prints_the_normal_range_for_the_patients_age_and_sex(): void
    {
        $test = $this->postJson('/api/lab/tests', $this->haemoglobin())->assertCreated()->assertJsonPath('parameters.0.ranges.1.range', '12 - 15')->json();

        $this->assertSame('12 - 15', $this->rangeFor($test['id'], ['gender' => 'female', 'date_of_birth' => '1990-05-01']));
        $this->assertSame('13 - 17', $this->rangeFor($test['id'], ['gender' => 'male', 'date_of_birth' => '1980-05-01']));
        // The first line that fits is used: a girl of five gets the children's range, not the women's.
        $this->assertSame('11 - 14', $this->rangeFor($test['id'], ['gender' => 'female', 'date_of_birth' => '2021-06-01']));
        // Twelve completed years still counts as twelve, until the thirteenth birthday.
        $this->assertSame('11 - 14', $this->rangeFor($test['id'], ['gender' => 'male', 'date_of_birth' => '2013-11-25']));
        $this->assertSame('13 - 17', $this->rangeFor($test['id'], ['gender' => 'male', 'date_of_birth' => '2013-11-24']));
        // Nobody's range fits: the plain one. An age line never fits a patient with no date of birth.
        $this->assertSame('12 - 17', $this->rangeFor($test['id'], ['gender' => 'other', 'date_of_birth' => null]));
        $this->assertSame('12 - 15', $this->rangeFor($test['id'], ['gender' => 'female', 'date_of_birth' => null]));
    }

    public function test_age_bands_can_be_in_days_and_months(): void
    {
        $test = $this->postJson('/api/lab/tests', $this->haemoglobin([
            ['sex' => null, 'age_from' => 0, 'age_to' => 28, 'age_unit' => 'days', 'range' => '14 - 22'],
            ['sex' => null, 'age_from' => 1, 'age_to' => 11, 'age_unit' => 'months', 'range' => '10 - 14'],
            ['sex' => null, 'age_from' => 60, 'age_to' => null, 'age_unit' => 'years', 'range' => '11.5 - 16'],
        ]))->assertCreated()->json();

        $this->assertSame('14 - 22', $this->rangeFor($test['id'], ['gender' => 'male', 'date_of_birth' => '2026-11-10']));
        $this->assertSame('10 - 14', $this->rangeFor($test['id'], ['gender' => 'male', 'date_of_birth' => '2026-05-20']));
        $this->assertSame('11.5 - 16', $this->rangeFor($test['id'], ['gender' => 'female', 'date_of_birth' => '1950-01-01']));
        $this->assertSame('12 - 17', $this->rangeFor($test['id'], ['gender' => 'female', 'date_of_birth' => '1990-01-01']));
    }

    public function test_ranges_by_age_and_sex_are_part_of_the_template_version(): void
    {
        $test = $this->postJson('/api/lab/tests', $this->haemoglobin())->assertCreated()->assertJsonPath('version', 1)->json();
        $this->assertSame('12 - 15', LabTestVersion::where('lab_test_id', $test['id'])->first()->parameters[0]['ranges'][1]['range']);

        // Saved again as it is: still version 1. An order does not make a version either.
        $this->putJson("/api/lab/tests/{$test['id']}", $this->haemoglobin())->assertOk()->assertJsonPath('version', 1);
        $this->rangeFor($test['id'], ['gender' => 'female']);
        $this->assertSame(1, LabTestVersion::where('lab_test_id', $test['id'])->count());

        // One of the ranges is changed: that is version 2, and it says what changed.
        $changed = $this->haemoglobin();
        $changed['parameters'][0]['ranges'][1]['range'] = '11.5 - 15';
        $this->putJson("/api/lab/tests/{$test['id']}", $changed)->assertOk()->assertJsonPath('version', 2);
        $this->assertSame('Changed: Haemoglobin (Hb) (ranges by age and sex).', LabTestVersion::where('lab_test_id', $test['id'])->where('version', 2)->first()->change);

        // A template with no such ranges is written down exactly as before they existed.
        $plain = $this->postJson('/api/lab/tests', ['name' => 'RBS', 'price' => 80, 'parameters' => [['name' => 'Glucose', 'unit' => 'mg/dL', 'reference_range' => '70 - 140']]])->assertCreated()->json();
        $this->assertSame(['name', 'is_heading', 'unit', 'reference_range', 'method'], array_keys(LabTestVersion::where('lab_test_id', $plain['id'])->first()->parameters[0]));
    }

    public function test_an_extra_range_has_to_say_who_it_is_for(): void
    {
        $this->postJson('/api/lab/tests', $this->haemoglobin([['sex' => null, 'age_from' => null, 'age_to' => null, 'age_unit' => 'years', 'range' => '12 - 15']]))
            ->assertStatus(422)->assertJsonValidationErrors('parameters');
        $this->postJson('/api/lab/tests', $this->haemoglobin([['sex' => 'female', 'age_from' => 50, 'age_to' => 12, 'age_unit' => 'years', 'range' => '12 - 15']]))
            ->assertStatus(422)->assertJsonValidationErrors('parameters');
        $this->postJson('/api/lab/tests', $this->haemoglobin([['sex' => 'female', 'range' => '']]))->assertStatus(422);
    }

    public function test_a_test_done_at_an_outside_lab_is_followed_until_its_result_comes_back(): void
    {
        $culture = $this->postJson('/api/lab/tests', ['name' => 'Blood culture', 'kind' => 'text', 'price' => 900, 'outside_lab' => ' City Diagnostics ', 'outside_cost' => 550])
            ->assertCreated()->assertJsonPath('outside_lab', 'City Diagnostics')->json();
        $sugar = $this->postJson('/api/lab/tests', ['name' => 'RBS', 'price' => 80, 'parameters' => [['name' => 'Glucose', 'unit' => 'mg/dL', 'reference_range' => '70 - 140']]])->assertCreated()->json();
        // Naming an outside lab or what it charges makes no new version of the template.
        $this->assertSame(1, LabTestVersion::where('lab_test_id', $culture['id'])->count());

        $order = $this->postJson('/api/lab/orders', ['patient_id' => Patient::factory()->create(['name' => 'Sample Patient'])->id, 'test_ids' => [$culture['id'], $sugar['id']]])->assertCreated()->json();
        $items = collect($order['items'])->keyBy('test_name');
        $out = $items['Blood culture'];
        $this->assertSame('City Diagnostics', $out['outside_lab']);
        $this->assertEquals(550, $out['outside_cost']);
        $this->assertNull($items['RBS']['outside_lab']);
        $path = "/api/lab/orders/{$order['id']}/items";

        // The sample cannot be sent from a test that names no lab.
        $this->putJson("{$path}/{$items['RBS']['id']}/outside", ['sent' => true])->assertStatus(422);

        // Sent: the time is noted, and the sample counts as collected.
        Carbon::setTestNow('2026-11-24 10:30');
        $sent = collect($this->putJson("{$path}/{$out['id']}/outside", ['sent' => true])->assertOk()->json('items'))->firstWhere('id', $out['id']);
        $this->assertSame('2026-11-24 10:30', $sent['sent_at']);
        $this->assertSame('2026-11-24 10:30', $sent['collected_at']);

        // The machine is down today: the sugar goes out too, to another lab, and comes back in-house when undone.
        $this->putJson("{$path}/{$items['RBS']['id']}/outside", ['outside_lab' => 'Metro Labs', 'outside_cost' => 40, 'sent' => true])->assertOk();
        $this->assertSame(['City Diagnostics', 'Metro Labs'], $this->getJson('/api/lab/outside-labs')->assertOk()->json());

        $report = $this->getJson('/api/reports/list/lab-outside?from=2026-11-24&to=2026-11-24');
        $rows = collect($report->assertOk()->json('rows'));
        $this->assertSame(['City Diagnostics', 'Metro Labs'], $rows->pluck('outside_lab')->all());
        $this->assertSame('Waiting for the result', $rows[0]['reported']);
        $this->assertSame('2026-11-24 10:30', $rows[0]['sent']);
        $this->assertEquals(590, $report->json('totals.outside_cost'));

        $this->putJson("{$path}/{$items['RBS']['id']}/outside", ['outside_lab' => null])->assertOk();
        $back = collect($this->getJson("/api/lab/orders/{$order['id']}")->json('items'))->firstWhere('id', $items['RBS']['id']);
        $this->assertNull($back['outside_lab']);
        $this->assertNull($back['sent_at']);
        $this->assertNull($back['outside_cost']);

        // Once the result is in, where the test was done is settled.
        $this->putJson("{$path}/{$out['id']}", ['results' => [], 'report_text' => 'No growth after 48 hours.', 'complete' => true])->assertOk();
        $this->putJson("{$path}/{$out['id']}/outside", ['outside_lab' => 'Metro Labs'])->assertStatus(422);
    }

    public function test_the_report_of_time_taken_reads_the_three_waits_of_each_test(): void
    {
        $sugar = $this->postJson('/api/lab/tests', ['name' => 'RBS', 'department' => 'Biochemistry', 'price' => 80, 'parameters' => [['name' => 'Glucose', 'unit' => 'mg/dL', 'reference_range' => '70 - 140']]])->assertCreated()->json();

        // Two patients: one waits 20 minutes for the sample and 70 more for the report, the other 40 and 50.
        foreach ([[20, 70], [40, 50]] as [$toSample, $toReport]) {
            Carbon::setTestNow('2026-11-24 10:00');
            $order = $this->postJson('/api/lab/orders', ['patient_id' => Patient::factory()->create()->id, 'test_ids' => [$sugar['id']]])->assertCreated()->json();
            $item = $order['items'][0];
            Carbon::setTestNow(Carbon::parse('2026-11-24 10:00')->addMinutes($toSample));
            $this->putJson("/api/lab/orders/{$order['id']}/items/{$item['id']}/collect", ['collected' => true])->assertOk();
            Carbon::setTestNow(Carbon::parse('2026-11-24 10:00')->addMinutes($toSample + $toReport));
            $this->putJson("/api/lab/orders/{$order['id']}/items/{$item['id']}", ['results' => [['id' => $item['results'][0]['id'], 'value' => '110']], 'complete' => true])->assertOk();
        }
        // A third order, not reported yet, is not counted.
        $this->postJson('/api/lab/orders', ['patient_id' => Patient::factory()->create()->id, 'test_ids' => [$sugar['id']]])->assertCreated();

        $report = $this->getJson('/api/reports/list/lab-turnaround?from=2026-11-24&to=2026-11-24');
        $row = $report->assertOk()->json('rows.0');
        $this->assertSame(['RBS', 'Biochemistry', 2], [$row['test'], $row['department'], $row['reports']]);
        $this->assertSame(['30 min', '1 h 0 min', '1 h 30 min', '1 h 30 min', 90], [$row['to_sample'], $row['to_report'], $row['whole'], $row['longest'], $row['minutes']]);
    }

    public function test_the_sample_label_settings_are_kept(): void
    {
        $this->getJson('/api/lab/settings')->assertOk()->assertJsonPath('label_width_mm', 50)->assertJsonPath('label_height_mm', 25)->assertJsonPath('label_barcode', true)->assertJsonPath('print_outside_lab', true);

        $current = $this->getJson('/api/lab/settings')->json();
        $this->putJson('/api/lab/settings', ['label_width_mm' => 38, 'label_height_mm' => 25, 'label_barcode' => false, 'print_outside_lab' => false] + $current)
            ->assertOk()->assertJsonPath('label_width_mm', 38)->assertJsonPath('label_barcode', false)->assertJsonPath('print_outside_lab', false);
        $this->putJson('/api/lab/settings', ['label_width_mm' => 5] + $current)->assertStatus(422);
    }
}
