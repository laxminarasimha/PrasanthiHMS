<?php

namespace Tests\Feature;

use App\Models\LabTest;
use App\Models\Medicine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportListsTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_lists_are_loaded_once_and_existing_names_are_left_alone(): void
    {
        LabTest::create(['name' => 'Random Blood Sugar', 'price' => 80]);
        Medicine::create(['name' => 'Sample Tablet 500mg', 'gst_percent' => 12, 'units_per_pack' => 10]);

        $file = tempnam(sys_get_temp_dir(), 'lists');
        file_put_contents($file, json_encode([
            'tests' => [
                ['name' => 'random  blood sugar', 'price' => 999], // already there, whatever the capitals and spacing
                ['name' => 'Lipid Profile', 'sample_type' => 'Blood', 'price' => 600, 'print_order' => 7, 'parameters' => [
                    ['name' => 'Lipids', 'is_heading' => true],
                    ['name' => 'Total Cholesterol', 'unit' => 'mg/dL', 'reference_range' => '0 - 200', 'method' => 'Enzymatic'],
                    ['name' => ''],
                ]],
                ['name' => 'ECG', 'kind' => 'text', 'price' => 300, 'default_comment' => 'Correlate clinically.'],
                ['name' => ''],
            ],
            'medicines' => [
                ['name' => 'SAMPLE TABLET 500MG', 'manufacturer' => 'Other'],
                ['name' => 'Sample Syrup 100ml', 'type' => 'Syrup', 'manufacturer' => 'Sample Pharma', 'is_scheduled' => true, 'gst_percent' => 5, 'units_per_pack' => 1, 'reorder_level' => 10],
            ],
        ]));

        $this->artisan('hms:import-lists', ['file' => $file, '--dry-run' => true])->assertExitCode(0);
        $this->assertSame(1, LabTest::count());

        $this->artisan('hms:import-lists', ['file' => $file])->assertExitCode(0);
        $this->assertSame(3, LabTest::count());
        $this->assertSame(2, Medicine::count());

        $lipid = LabTest::where('name', 'Lipid Profile')->with('parameters')->first();
        $this->assertEquals(600, $lipid->price);
        $this->assertSame(7, $lipid->print_order);
        $this->assertCount(2, $lipid->parameters);
        $this->assertTrue((bool) $lipid->parameters[0]->is_heading);
        $this->assertSame('0 - 200', $lipid->parameters[1]->reference_range);
        $this->assertSame('text', LabTest::where('name', 'ECG')->value('kind'));
        $this->assertEquals(80, LabTest::where('name', 'Random Blood Sugar')->value('price')); // untouched

        $syrup = Medicine::where('name', 'Sample Syrup 100ml')->first();
        $this->assertTrue($syrup->is_scheduled);
        $this->assertEquals(5, $syrup->gst_percent);
        $this->assertDatabaseHas('manufacturers', ['name' => 'Sample Pharma']);

        // Running it again adds nothing.
        $this->artisan('hms:import-lists', ['file' => $file])->assertExitCode(0);
        $this->assertSame(3, LabTest::count());
        $this->assertSame(2, Medicine::count());

        $this->artisan('hms:import-lists', ['file' => '/nowhere/lists.json'])->assertExitCode(1);
        unlink($file);
    }
}
