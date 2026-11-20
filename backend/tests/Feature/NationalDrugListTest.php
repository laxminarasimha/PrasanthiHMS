<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\PharmacyChange;
use App\Models\StockBatch;
use App\Models\User;
use App\Support\DrugReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * The national drug list (Common Drug Codes for India) and linking the pharmacy's medicines to it. The files here are
 * a small made-up sample in the government's layout; the brand names are invented.
 */
class NationalDrugListTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = sys_get_temp_dir().'/cdci-test-'.bin2hex(random_bytes(4));
        mkdir($this->folder);
        $write = fn (string $file, string $head, array $rows) => file_put_contents("{$this->folder}/{$file}", $head."\r\n".implode("\r\n", array_map(fn ($r) => implode("\t", $r), $rows))."\r\n");
        $write('SubstanceMaster.txt', "Identifier\tSubstance Name", [['387517004', 'Acetaminophen'], ['372687004', 'Amoxicillin'], ['395938000', 'Clavulanic acid']]);
        $write('DrugFormMaster.txt', "Id\tDose Form", [['421026006', 'Oral tablet'], ['385023001', 'Oral solution']]);
        $write('RouteOfAdministrationMaster.txt', "Identifier\tRouteOfAdministration", [['26643006', 'Oral route']]);
        $write('ProductMaster.txt', "Identifier\tProduct Name", [['9001', 'Samplol'], ['9002', 'Samplomox'], ['9003', 'Samplomox DUO']]);
        $write('SupplierMaster.txt', "Identifier\tSupplier Name\tCountry", [['8001', 'Sample Labs Limited', '']]);
        $generic = fn ($code, $name, $subs, $form, $interactions = '') => [$code, $name, $subs, '26643006', $form, '', '', '', $interactions, '', '', '20260831'];
        $generics = [
            $generic('1001', 'Acetaminophen 650 mg oral tablet', '387517004', '421026006', 'Take care with warfarin.'),
            $generic('1002', 'Acetaminophen 500 mg oral tablet', '387517004', '421026006'),
            $generic('1003', 'Amoxicillin 500 mg and clavulanic acid (as clavulanate potassium) 125 mg oral tablet', '372687004+395938000', '421026006'),
        ];
        $brands = [
            ['5001', 'Samplol 650 mg oral tablet', '9001', '8001', '1001', '', 'UNKNOWN', '', '20260831'],
            ['5002', 'Samplol 500 mg oral tablet', '9001', '8001', '1002', '', 'UNKNOWN', '', '20260831'],
            ['5003', 'Samplomox 500 mg + 125 mg oral tablet', '9002', '8001', '1003', '', 'UNKNOWN', '', '20260831'],
            ['5004', 'Samplomox DUO 500 mg + 125 mg oral tablet', '9003', '8001', '1003', '', 'UNKNOWN', '', '20260831'],
        ];
        // A real list has thousands; the sample is padded so the importer's "is this a full list" check passes.
        for ($i = 0; $i < 1000; $i++) {
            $generics[] = $generic((string) (20000 + $i), "Samplinol {$i} mg oral tablet", '387517004', '421026006');
            $brands[] = [(string) (30000 + $i), "Filler {$i} mg oral tablet", '', '', (string) (20000 + $i), '', 'UNKNOWN', '', '20260831'];
        }
        $write('GenericMaster.txt', "Identifier\tGeneric Name\tSubstance Identifier\tRoute of Administration\tDose Form\tTherapeutic Role\tIndication\tContra Indication\tInteraction with Drugs\tClassification of Drugs\tSource/ Regulatory\tlast_updated_on", $generics);
        $write('BrandMaster.txt', "Identifier\tBrand Name\tProduct Identifier\tSupplier Identifier\tGeneric Identifier\tLicense Number\tLicense Status\tExcipient\tlast_updated_on", $brands);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->folder.'/*') ?: []);
        @rmdir($this->folder);
        parent::tearDown();
    }

    public function test_the_list_loads_and_a_second_load_brings_entries_up_to_date_without_duplicates(): void
    {
        $this->assertSame(['substances' => 3, 'generics' => 1003, 'brands' => 1004], DrugReference::load($this->folder));
        $this->assertSame('Samplol', DB::table('drug_ref_brands')->where('code', '5001')->value('product_name'));
        $this->assertSame('Sample Labs Limited', DB::table('drug_ref_brands')->where('code', '5001')->value('supplier'));
        $this->assertSame('Oral tablet', DB::table('drug_ref_generics')->where('code', '1001')->value('form'));
        $this->assertSame('2026-08-31', DB::table('drug_ref_brands')->where('code', '5001')->value('updated_on'));

        DrugReference::load($this->folder);
        $this->assertSame(1004, DB::table('drug_ref_brands')->count());

        // A cut-short file is refused before anything is written.
        file_put_contents($this->folder.'/BrandMaster.txt', "Identifier\tBrand Name\r\n5001\tSamplol\r\n");
        $this->expectException(RuntimeException::class);
        DrugReference::load($this->folder);
    }

    public function test_a_generic_is_written_as_a_composition_in_indian_names(): void
    {
        $this->assertSame('Amoxicillin 500 mg + Clavulanic acid 125 mg', DrugReference::composition('Amoxicillin 500 mg and clavulanic acid (as clavulanate potassium) 125 mg oral tablet', 'Oral tablet'));
        $this->assertSame('Paracetamol 650 mg', DrugReference::composition('Acetaminophen 650 mg oral tablet', 'Oral tablet'));
        $this->assertSame('Levothyroxine sodium 12.5 mcg', DrugReference::composition('Levothyroxine sodium 12.5 microgram oral tablet', 'Oral tablet'));
        $this->assertSame('Budesonide 100 mcg/actuation', DrugReference::composition('Budesonide 100 microgram/actuation nasal spray', 'Nasal spray'));
        // Text turned into UTF-8 twice upstream is mended; ordinary text is left alone.
        $this->assertSame('alpha-lactamase–negative', DrugReference::repair('alpha-lactamaseâ€“negative'));
        $this->assertSame('Paget’s disease ≥ 5 µg', DrugReference::repair('Pagetâ€™s disease â‰¥ 5 Âµg'));
        $this->assertSame('naïve', DrugReference::repair('naïve'));
    }

    public function test_staff_link_a_medicine_from_the_suggestions_and_its_composition_is_filled_in(): void
    {
        DrugReference::load($this->folder);
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $dolo = Medicine::create(['name' => 'SAMPLOL 650 TAB', 'units_per_pack' => 10]);
        $duo = Medicine::create(['name' => 'SAMPLOMOX 625 DUO TAB', 'units_per_pack' => 10]);
        $shelf = Medicine::create(['name' => 'NOT ON THE LIST 5', 'units_per_pack' => 10]);
        foreach ([$dolo, $duo, $shelf] as $m) {
            StockBatch::create(['medicine_id' => $m->id, 'batch_no' => 'B1', 'expiry' => now()->addYear(), 'units_per_pack' => 10, 'packs' => 1, 'pack_price' => 10, 'pack_mrp' => 20, 'unit_mrp' => 2, 'gst_percent' => 12, 'units_received' => 10, 'units_in_stock' => 10]);
        }

        // The best guess comes first: the same pack name and strength, and the DUO variant for a DUO.
        $next = $this->getJson('/api/pharmacy/national-list/next')->assertOk()->assertJsonPath('remaining', 3)->assertJsonPath('medicine.id', $shelf->id)->json();
        $this->assertSame([], $next['suggestions']);
        $this->getJson("/api/pharmacy/national-list/next?after_name={$shelf->name}&after_id={$shelf->id}")->assertOk()->assertJsonPath('medicine.id', $dolo->id)
            ->assertJsonPath('suggestions.0.code', '5001')->assertJsonPath('suggestions.0.composition', 'Paracetamol 650 mg');
        $this->getJson("/api/pharmacy/national-list/medicines/{$duo->id}")->assertOk()->assertJsonPath('suggestions.0.code', '5004');

        // Nothing is linked until someone picks it.
        $this->assertNull($dolo->fresh()->ref_brand_code);
        $this->postJson("/api/pharmacy/medicines/{$dolo->id}/national-link", ['brand_code' => '5001'])->assertOk()
            ->assertJsonPath('ref_brand_code', '5001')->assertJsonPath('ref_generic_code', '1001')->assertJsonPath('composition', 'Paracetamol 650 mg')
            ->assertJsonPath('manufacturer', 'Sample Labs Limited')->assertJsonPath('generic.interactions', 'Take care with warfarin.');
        $this->assertSame(['Paracetamol'], $dolo->fresh()->ingredients()->pluck('name')->all());
        $this->assertStringContainsString('Linked to the national drug list: Samplol 650 mg oral tablet', PharmacyChange::latest('id')->value('summary'));

        // A brand the list lacks can be linked to its generic, found by words.
        $this->getJson('/api/pharmacy/national-list/search?q=amoxicillin 500')->assertOk()->assertJsonPath('generics.0.code', '1003');
        $this->postJson("/api/pharmacy/medicines/{$shelf->id}/national-link", ['generic_code' => '1003', 'use_composition' => false])->assertOk()
            ->assertJsonPath('ref_brand_code', null)->assertJsonPath('ref_generic_code', '1003')->assertJsonPath('composition', null);

        $this->getJson('/api/pharmacy/national-list')->assertOk()->assertJsonPath('brands', 1004)->assertJsonPath('linked', 2)->assertJsonPath('medicines', 3);
        $this->postJson("/api/pharmacy/medicines/{$duo->id}/national-link", ['brand_code' => 'nope'])->assertStatus(422);

        // A link made by mistake comes off; what the medicine contains is left alone.
        $this->deleteJson("/api/pharmacy/medicines/{$dolo->id}/national-link")->assertOk()->assertJsonPath('ref_brand_code', null)->assertJsonPath('composition', 'Paracetamol 650 mg');

        // Someone outside the pharmacy cannot link.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->postJson("/api/pharmacy/medicines/{$dolo->id}/national-link", ['brand_code' => '5001'])->assertForbidden();
    }
}
