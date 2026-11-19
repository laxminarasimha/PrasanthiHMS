<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The change that gives the blood count its MCV, MCH and MCHC fields. */
class BloodCountIndicesTest extends TestCase
{
    use RefreshDatabase;

    private function template(string $name, array $fields): int
    {
        $id = DB::table('lab_tests')->insertGetId(['code' => 'T'.random_int(1000, 9999), 'name' => $name, 'kind' => 'values', 'price' => 300, 'is_active' => true]);
        foreach ($fields as $position => $field) {
            DB::table('lab_test_parameters')->insert(['lab_test_id' => $id, 'position' => $position, 'is_heading' => false, ...$field]);
        }

        return $id;
    }

    private function run_change(): string
    {
        ob_start();
        (require database_path('migrations/2026_12_04_100001_blood_count_has_red_cell_indices.php'))->up();

        return (string) ob_get_clean();
    }

    private function names(int $test): array
    {
        return DB::table('lab_test_parameters')->where('lab_test_id', $test)->orderBy('position')->pluck('name')->all();
    }

    public function test_the_three_fields_follow_the_red_cell_count_and_the_rest_move_down(): void
    {
        $cbp = $this->template('Complete Blood Picture - CBP', [
            ['name' => 'Haemoglobin (Hb)', 'unit' => 'mg/dl'],
            ['name' => 'Haematocrit (PCV)', 'unit' => '%'],
            ['name' => 'Total Red Cell Count (RBC)', 'unit' => 'Million Cells/cu mm'],
            ['name' => 'Total WBC Count', 'unit' => 'Cells/cu mm'],
            ['name' => 'Differential Count', 'is_heading' => true],
            ['name' => 'Neutrophils', 'unit' => '%'],
            ['name' => 'Peripheral Blood Smear', 'is_heading' => true],
            ['name' => 'RBCs'],
        ]);
        $sugar = $this->template('Random Blood Sugar', [['name' => 'Glucose', 'unit' => 'mg/dl']]);

        $said = $this->run_change();

        $this->assertSame(
            ['Haemoglobin (Hb)', 'Haematocrit (PCV)', 'Total Red Cell Count (RBC)', 'MCV', 'MCH', 'MCHC', 'Total WBC Count', 'Differential Count', 'Neutrophils', 'Peripheral Blood Smear', 'RBCs'],
            $this->names($cbp),
        );
        $this->assertSame(range(0, 10), DB::table('lab_test_parameters')->where('lab_test_id', $cbp)->orderBy('position')->pluck('position')->all());
        $this->assertSame('g/dL', DB::table('lab_test_parameters')->where('lab_test_id', $cbp)->where('name', 'Haemoglobin (Hb)')->value('unit'));
        $this->assertSame('fL', DB::table('lab_test_parameters')->where('lab_test_id', $cbp)->where('name', 'MCV')->value('unit'));
        $this->assertStringContainsString('added MCV, MCH, MCHC; haemoglobin unit set to g/dL', $said);

        // Another test keeps its fields and its unit: mg/dl is right for a sugar.
        $this->assertSame(['Glucose'], $this->names($sugar));
        $this->assertSame('mg/dl', DB::table('lab_test_parameters')->where('lab_test_id', $sugar)->value('unit'));
    }

    public function test_fields_the_lab_already_typed_are_not_added_twice(): void
    {
        $cbc = $this->template('Complete Blood Count', [
            ['name' => 'Haemoglobin', 'unit' => 'g/dL'],
            ['name' => 'PCV', 'unit' => '%'],
            ['name' => 'Mean Corpuscular Volume (MCV)', 'unit' => 'fL'],
            ['name' => 'Platelet Count'],
        ]);

        $this->run_change();
        $this->assertSame(['Haemoglobin', 'PCV', 'MCH', 'MCHC', 'Mean Corpuscular Volume (MCV)', 'Platelet Count'], $this->names($cbc));

        // Running it again changes nothing and says nothing.
        $this->assertSame('', $this->run_change());
        $this->assertCount(6, $this->names($cbc));
    }
}
