<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LabTestVersion;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * Lab report templates keep their versions: an edit writes the next version, a report says which version it was
 * made with, and a report already made never changes. The blood count is the worked example, in its older form
 * of 13 fields and its present form of 16.
 */
class LabTemplateVersionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech', 'name' => 'Lab Person']));
    }

    /** The blood count as it was: 13 fields under two headings. */
    private function older(): array
    {
        return [
            ['name' => 'Haemoglobin (Hb)', 'unit' => 'g/dL', 'reference_range' => 'Male : 14.0 - 17.0; Female : 12.0 - 15.0'],
            ['name' => 'Haematocrit (PCV)', 'unit' => '%', 'reference_range' => '36 - 52'],
            ['name' => 'Total Red Cell Count (RBC)', 'unit' => 'Million Cells/cu mm', 'reference_range' => '3.8 - 5.5'],
            ['name' => 'Total WBC Count', 'unit' => 'Cells/cu mm', 'reference_range' => '4,000 - 11,000'],
            ['name' => 'Platelets Count', 'unit' => 'lakh/cu mm', 'reference_range' => '1.50 - 4.10'],
            ['name' => 'Differential Count', 'is_heading' => true],
            ['name' => 'Neutrophils', 'unit' => '%', 'reference_range' => '40 - 75'],
            ['name' => 'Lymphocytes', 'unit' => '%', 'reference_range' => '20 - 40'],
            ['name' => 'Eosinophil', 'unit' => '%', 'reference_range' => '0 - 6'],
            ['name' => 'Monocytes', 'unit' => '%', 'reference_range' => '2 - 10'],
            ['name' => 'Basophils', 'unit' => '%', 'reference_range' => '0 - 2'],
            ['name' => 'Peripheral Blood Smear', 'is_heading' => true],
            ['name' => 'RBCs'],
            ['name' => 'WBCs'],
            ['name' => 'Platelets'],
        ];
    }

    /** The blood count as it is now: the same, with MCV, MCH and MCHC after the red cell count. 16 fields. */
    private function newer(): array
    {
        $fields = $this->older();
        array_splice($fields, 3, 0, [
            ['name' => 'MCV', 'unit' => 'fL', 'reference_range' => '83 - 101'],
            ['name' => 'MCH', 'unit' => 'pg', 'reference_range' => '27 - 32'],
            ['name' => 'MCHC', 'unit' => 'g/dL', 'reference_range' => '31.5 - 34.5'],
        ]);

        return $fields;
    }

    private function bloodCount(array $fields): array
    {
        return ['name' => 'Complete Blood Picture - CBP', 'department' => 'Haematology', 'sample_type' => 'Blood', 'price' => 300, 'parameters' => $fields];
    }

    private function order(int $testId): array
    {
        return $this->postJson('/api/lab/orders', ['patient_id' => Patient::factory()->create()->id, 'test_ids' => [$testId]])->assertCreated()->json();
    }

    /** Types a value into every field, reports the test and approves it. Returns the order as it then reads. */
    private function reportAndApprove(array $order): array
    {
        $item = $order['items'][0];
        $values = collect($item['results'])->where('is_heading', false)->values()->map(fn ($r, $i) => ['id' => $r['id'], 'value' => (string) (10 + $i)])->all();
        $this->putJson("/api/lab/orders/{$order['id']}/items/{$item['id']}", ['results' => $values, 'complete' => true])->assertOk();

        return $this->putJson("/api/lab/orders/{$order['id']}/items/{$item['id']}/approve", ['approved' => true])->assertOk()->json();
    }

    private function fields(array $order): array
    {
        return collect($order['items'][0]['results'])->where('is_heading', false)->pluck('parameter')->all();
    }

    public function test_an_approved_13_field_blood_count_stays_as_it_was_when_the_template_gains_three_fields(): void
    {
        $test = $this->postJson('/api/lab/tests', $this->bloodCount($this->older()))->assertCreated()->assertJsonPath('version', 1)->json();
        $before = $this->reportAndApprove($this->order($test['id']));
        $this->assertSame(1, $before['items'][0]['template_version']);
        $this->assertCount(13, $this->fields($before));
        $linesBefore = DB::table('lab_results')->orderBy('id')->get()->toArray();

        // The template is edited: three fields more. That is version 2; version 1 is left as it was.
        $this->putJson("/api/lab/tests/{$test['id']}", $this->bloodCount($this->newer()))->assertOk()->assertJsonPath('version', 2)->assertJsonCount(18, 'parameters');

        $versions = $this->getJson("/api/lab/tests/{$test['id']}/versions")->assertOk()->json();
        $this->assertSame([2, 1], array_column($versions, 'version'));
        $this->assertSame([true, false], array_column($versions, 'is_current'));
        $this->assertSame('Added: MCV, MCH, MCHC.', $versions[0]['change']);
        $this->assertSame('Lab Person', $versions[0]['by']);
        $this->assertCount(18, $versions[0]['parameters']);
        $this->assertCount(15, $versions[1]['parameters']);
        $this->assertNotContains('MCV', array_column($versions[1]['parameters'], 'name'));
        $this->assertSame(['reports' => 1, 'approved' => 1], ['reports' => $versions[1]['reports'], 'approved' => $versions[1]['approved']]);

        // The approved report: the same 13 fields, the same values, still saying version 1. Nothing was added for it.
        $after = $this->getJson("/api/lab/orders/{$before['id']}")->assertOk()->json();
        $this->assertSame(1, $after['items'][0]['template_version']);
        $this->assertSame($this->fields($before), $this->fields($after));
        $this->assertNotContains('MCV', $this->fields($after));
        $this->assertEquals($linesBefore, DB::table('lab_results')->where('lab_order_item_id', $before['items'][0]['id'])->orderBy('id')->get()->toArray());

        // The next blood count is made with version 2 and has all 16 fields, in the template's order.
        $next = $this->reportAndApprove($this->order($test['id']));
        $this->assertSame(2, $next['items'][0]['template_version']);
        $this->assertCount(16, $this->fields($next));
        $this->assertSame(['Total Red Cell Count (RBC)', 'MCV', 'MCH', 'MCHC', 'Total WBC Count'], array_slice($this->fields($next), 2, 5));
        $this->assertSame('31.5 - 34.5', collect($next['items'][0]['results'])->firstWhere('parameter', 'MCHC')['reference_range']);
    }

    public function test_only_a_change_to_what_the_report_reads_as_makes_a_new_version(): void
    {
        $test = $this->postJson('/api/lab/tests', $this->bloodCount($this->older()))->assertCreated()->json();

        // Price, department and whether it is offered are filing: still version 1.
        $this->putJson("/api/lab/tests/{$test['id']}", ['price' => 350, 'department' => 'Clinical Pathology', 'is_active' => false] + $this->bloodCount($this->older()))
            ->assertOk()->assertJsonPath('version', 1);
        $this->assertSame(1, LabTestVersion::where('lab_test_id', $test['id'])->count());

        // A unit, a normal range, the order of the fields, the name: each is a new version, and says what changed.
        $fields = $this->older();
        $fields[1]['reference_range'] = '36 - 50';
        $this->putJson("/api/lab/tests/{$test['id']}", $this->bloodCount($fields))->assertOk()->assertJsonPath('version', 2);
        [$fields[3], $fields[4]] = [$fields[4], $fields[3]];
        $this->putJson("/api/lab/tests/{$test['id']}", ['name' => 'Complete Blood Count'] + $this->bloodCount($fields))->assertOk()->assertJsonPath('version', 3);

        $changes = LabTestVersion::where('lab_test_id', $test['id'])->orderBy('version')->pluck('change')->all();
        $this->assertSame('The first version of this template.', $changes[0]);
        $this->assertSame('Changed: Haematocrit (PCV) (normal range).', $changes[1]);
        $this->assertSame('Renamed from “Complete Blood Picture - CBP”. Fields put in a different order.', $changes[2]);
    }

    public function test_a_version_once_written_down_cannot_be_changed_or_removed(): void
    {
        $test = $this->postJson('/api/lab/tests', $this->bloodCount($this->older()))->assertCreated()->json();
        $version = LabTestVersion::where('lab_test_id', $test['id'])->firstOrFail();

        try {
            $version->update(['parameters' => []]);
            $this->fail('A version was changed.');
        } catch (LogicException) {
            $this->assertCount(15, $version->fresh()->parameters);
        }
        try {
            $version->delete();
            $this->fail('A version was removed.');
        } catch (LogicException) {
            $this->assertNotNull($version->fresh());
        }
    }

    public function test_the_record_of_who_did_what_names_the_version(): void
    {
        $test = $this->postJson('/api/lab/tests', $this->bloodCount($this->older()))->assertCreated()->json();
        $this->putJson("/api/lab/tests/{$test['id']}", $this->bloodCount($this->newer()))->assertOk();
        $order = $this->reportAndApprove($this->order($test['id']));

        $said = AuditLog::whereIn('action', ['lab.template', 'lab.ordered', 'lab.approved'])->orderBy('id')->pluck('summary', 'action');
        $this->assertSame('Complete Blood Picture - CBP · version 2 · Added: MCV, MCH, MCHC.', $said['lab.template']);
        $this->assertSame("{$order['order_no']} · Complete Blood Picture - CBP (template version 2)", $said['lab.ordered']);
        $this->assertSame("{$order['order_no']} · Complete Blood Picture - CBP (template version 2)", $said['lab.approved']);
        $this->assertSame($order['patient']['id'], AuditLog::where('action', 'lab.approved')->value('patient_id'));
    }

    public function test_a_template_changed_outside_the_template_screen_is_noticed_at_the_next_order(): void
    {
        $test = $this->postJson('/api/lab/tests', $this->bloodCount($this->older()))->assertCreated()->json();
        $first = $this->order($test['id']);

        // A field put in straight into the database, as a correction script would.
        DB::table('lab_test_parameters')->insert(['lab_test_id' => $test['id'], 'name' => 'ESR', 'unit' => 'mm/hr', 'position' => 99, 'is_heading' => false]);

        $next = $this->order($test['id']);
        $this->assertSame([1, 2], [$first['items'][0]['template_version'], $next['items'][0]['template_version']]);
        $this->assertSame(['found', 'Added: ESR.'], array_values(LabTestVersion::where('lab_test_id', $test['id'])->where('version', 2)->first(['source', 'change'])->toArray()));
        // And a third order, with nothing changed in between, is still version 2.
        $this->assertSame(2, $this->order($test['id'])['items'][0]['template_version']);
    }

    public function test_reports_made_before_versions_were_kept_get_their_version_from_the_fields_they_hold(): void
    {
        // As the hospital's records stand today: two approved blood counts made on the 13-field template, the
        // template since given three more fields, one blood count made after that, and a test never ordered.
        $test = $this->postJson('/api/lab/tests', $this->bloodCount($this->older()))->assertCreated()->json();
        $old = [$this->reportAndApprove($this->order($test['id'])), $this->reportAndApprove($this->order($test['id']))];
        $this->putJson("/api/lab/tests/{$test['id']}", $this->bloodCount($this->newer()))->assertOk();
        $new = $this->reportAndApprove($this->order($test['id']));
        $unused = $this->postJson('/api/lab/tests', ['name' => 'ECG', 'kind' => 'text', 'price' => 200])->assertCreated()->json();

        $migration = require database_path('migrations/2026_12_05_100001_lab_templates_keep_versions.php');
        $migration->down(); // back to before versions existed: no version table, no version on any report
        $reportsBefore = DB::table('lab_results')->orderBy('id')->get()->toArray();
        $itemsBefore = DB::table('lab_order_items')->orderBy('id')->get()->toArray();

        ob_start();
        $migration->up();
        $said = (string) ob_get_clean();

        // Not one result line added, removed or altered, and nothing else about the reports changed.
        $this->assertEquals($reportsBefore, DB::table('lab_results')->orderBy('id')->get()->toArray());
        $this->assertEquals($itemsBefore, DB::table('lab_order_items')->orderBy('id')->get()->map(fn ($i) => (object) collect((array) $i)->except('template_version')->all())->toArray());

        // The two older reports are version 1, rebuilt from the 13 fields they hold; the template now is version 2.
        $versions = DB::table('lab_test_versions')->where('lab_test_id', $test['id'])->orderBy('version')->get();
        $this->assertSame([[1, 'reports'], [2, 'start']], $versions->map(fn ($v) => [(int) $v->version, $v->source])->all());
        $this->assertSame([13, 16], $versions->map(fn ($v) => count(array_filter(json_decode($v->parameters, true), fn ($p) => ! $p['is_heading'])))->all());
        $this->assertSame(
            [1, 1, 2],
            DB::table('lab_order_items')->whereIn('id', [$old[0]['items'][0]['id'], $old[1]['items'][0]['id'], $new['items'][0]['id']])->orderBy('id')->pluck('template_version')->map(fn ($v) => (int) $v)->all(),
        );
        $this->assertSame(2, (int) DB::table('lab_tests')->where('id', $test['id'])->value('version'));
        $this->assertSame(1, (int) DB::table('lab_tests')->where('id', $unused['id'])->value('version'));
        $this->assertStringContainsString('Complete Blood Picture - CBP: version 1 rebuilt from 2 earlier reports (13 fields)', $said);
        $this->assertStringContainsString('Complete Blood Picture - CBP: version 2 is the template now (16 fields)', $said);

        // The older report still reads with its 13 fields, and now says which version that was.
        $read = $this->getJson("/api/lab/orders/{$old[0]['id']}")->assertOk()->json();
        $this->assertSame(1, $read['items'][0]['template_version']);
        $this->assertSame($this->fields($old[0]), $this->fields($read));
        // And the next order carries on from version 2 without making a third.
        $this->assertSame(2, $this->order($test['id'])['items'][0]['template_version']);
        $this->assertSame(2, DB::table('lab_test_versions')->where('lab_test_id', $test['id'])->count());
    }
}
