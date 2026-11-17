<?php

namespace Tests\Feature;

use App\Models\DrugIngredient;
use App\Models\Medicine;
use App\Models\User;
use App\Support\Composition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DrugComponentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_composition_is_read_as_its_individual_drugs(): void
    {
        $this->assertSame([['name' => 'Paracetamol', 'strength' => '650 mg']], Composition::parts(' Paracetamol   650mg '));
        $this->assertSame(
            [['name' => 'Amoxicillin', 'strength' => '500 mg'], ['name' => 'Clavulanic acid', 'strength' => '125 mg']],
            Composition::parts('Amoxicillin 500 mg + Clavulanic acid 125mg'),
        );
        $this->assertSame([['name' => 'Ambroxol', 'strength' => '15 mg/5 ml'], ['name' => 'Guaifenesin', 'strength' => null]], Composition::parts('Ambroxol 15mg/5ml & Guaifenesin'));
        $this->assertSame([['name' => 'Vitamin B12', 'strength' => '1500 mcg']], Composition::parts('Vitamin B12 1500 mcg'));
        $this->assertSame([['name' => 'Diclofenac gel', 'strength' => '1 % w/w']], Composition::parts('Diclofenac gel 1% w/w'));
        $this->assertSame([], Composition::parts('  '));
    }

    public function test_compositions_are_filled_from_a_sheet_after_a_check(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $add = fn (string $name, ?string $composition = null) => $this->postJson('/api/pharmacy/medicines', ['name' => $name, 'composition' => $composition, 'gst_percent' => 12, 'units_per_pack' => 10])->assertCreated()->json('id');
        $dolo = $add('DOLO 650 TAB');
        $calpol = $add('CALPOL 650 TAB', 'Paracetamol 650 mg');
        $zerodol = $add('ZERODOL-P TAB');
        $cetzine = $add('CETZINE TAB', 'Cetirizine 10 mg');

        $rows = [
            ['medicine' => ' dolo  650 tab ', 'composition' => 'paracetamol 650mg'],
            ['medicine' => 'CALPOL 650 TAB', 'composition' => 'Paracetamol 650 mg'],
            ['medicine' => 'ZERODOL-P TAB', 'composition' => 'Paracetamol 325mg + Aceclofenac 100mg'],
            ['medicine' => 'CETZINE TAB', 'composition' => ''],
            ['medicine' => 'NOT IN THE LIST', 'composition' => 'Something 5 mg'],
            ['medicine' => 'Dolo 650 Tab', 'composition' => 'Paracetamol 500 mg'],
            ['medicine' => '', 'composition' => ''],
        ];

        // The check says what each line would do, and saves nothing.
        $check = $this->postJson('/api/pharmacy/medicines/compositions/import', ['rows' => $rows])->assertOk()->assertJsonPath('saved', 0)->json();
        $this->assertSame(['lines' => 6, 'change' => 2, 'same' => 1, 'skip' => 1, 'error' => 2], $check['totals']);
        $this->assertSame(['change', 'same', 'change', 'skip', 'error', 'error'], array_column($check['lines'], 'state'));
        $this->assertSame(['Paracetamol 650 mg', ['paracetamol 650 mg']], [$check['lines'][0]['composition'], $check['lines'][0]['drugs']]);
        $this->assertSame('Aceclofenac 100 mg + Paracetamol 325 mg', $check['lines'][2]['composition']);
        $this->assertStringContainsString('also on line 2', $check['lines'][5]['note']);
        $this->assertNull(Medicine::find($dolo)->composition);

        // Saving changes the two that differ, leaves an empty box alone, and links the drugs.
        $this->postJson('/api/pharmacy/medicines/compositions/import', ['rows' => $rows, 'commit' => true])->assertOk()->assertJsonPath('saved', 2);
        $this->assertSame(['Paracetamol 650 mg', 'Paracetamol 650 mg', 'Aceclofenac 100 mg + Paracetamol 325 mg', 'Cetirizine 10 mg'], array_map(fn ($id) => Medicine::find($id)->composition, [$dolo, $calpol, $zerodol, $cetzine]));
        $this->assertSame(3, DrugIngredient::where('name', 'Paracetamol')->first()->medicines()->count());
        // The same sheet again changes nothing.
        $this->postJson('/api/pharmacy/medicines/compositions/import', ['rows' => $rows, 'commit' => true])->assertOk()->assertJsonPath('saved', 0)->assertJsonPath('totals.change', 0);

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->postJson('/api/pharmacy/medicines/compositions/import', ['rows' => $rows])->assertForbidden();
    }

    public function test_a_drug_is_found_alone_and_inside_combinations_wherever_medicines_are_searched(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $add = fn (string $name, ?string $composition) => $this->postJson('/api/pharmacy/medicines', ['name' => $name, 'composition' => $composition, 'gst_percent' => 12, 'units_per_pack' => 10])->assertCreated()->json('id');
        $dolo = $add('DOLO 650 TAB', 'paracetamol 650mg');
        $calpol = $add('CALPOL 650 TAB', 'Paracetamol 650 mg');
        $combo = $add('ZERODOL-P TAB', 'Paracetamol 325 mg + Aceclofenac 100 mg');
        $same = $add('ACECLO PLUS TAB', 'aceclofenac 100mg + PARACETAMOL 325mg');
        $plain = $add('CETZINE TAB', null);

        // Each drug is one record, however it was typed; a combination is linked to each of its drugs.
        $this->assertSame(['Aceclofenac', 'Paracetamol'], DrugIngredient::orderBy('name')->pluck('name')->all());
        $this->assertSame(['Paracetamol 650 mg', 'Paracetamol 650 mg'], [Medicine::find($dolo)->composition, Medicine::find($calpol)->composition]);
        // The same combination typed in either order is one composition.
        $this->assertSame('Aceclofenac 100 mg + Paracetamol 325 mg', Medicine::find($combo)->composition);
        $this->assertSame(Medicine::find($combo)->composition, Medicine::find($same)->composition);
        $this->assertSame([['Paracetamol', '325 mg'], ['Aceclofenac', '100 mg']], Medicine::find($combo)->ingredients->map(fn ($d) => [$d->name, $d->pivot->strength])->all());
        $this->assertSame(4, DrugIngredient::where('name', 'Paracetamol')->first()->medicines()->count());

        // Stock for one single drug and one combination.
        $this->postJson('/api/pharmacy/opening-stock', ['items' => [
            ['medicine_id' => $calpol, 'batch_no' => 'C1', 'expiry' => now()->addYear()->format('Y-m'), 'units_per_pack' => 10, 'quantity' => 40, 'mrp' => 30, 'cost_price' => 20, 'gst_percent' => 12],
            ['medicine_id' => $combo, 'batch_no' => 'Z1', 'expiry' => now()->addYear()->format('Y-m'), 'units_per_pack' => 10, 'quantity' => 20, 'mrp' => 60, 'cost_price' => 40, 'gst_percent' => 12],
        ]])->assertCreated();

        // The medicines list, the counter and the inventory all find a medicine by a drug inside it.
        $this->getJson('/api/pharmacy/medicines?q=aceclofenac')->assertOk()->assertJsonCount(2);
        $this->assertSame(['CALPOL 650 TAB', 'ZERODOL-P TAB'], array_column($this->getJson('/api/pharmacy/sell-search?q=paracet')->assertOk()->json(), 'name'));
        $this->assertSame('Paracetamol 650 mg', collect($this->getJson('/api/pharmacy/inventory')->json('all'))->firstWhere('id', $calpol)['composition']);
        $options = $this->getJson('/api/pharmacy/medicines/options')->assertOk()->json();
        $this->assertSame(['Aceclofenac', 'Paracetamol'], $options['drugs']);
        $this->assertSame([['Paracetamol', '325 mg'], ['Aceclofenac', '100 mg']], array_map(fn ($d) => [$d['name'], $d['strength']], collect($options['medicines'])->firstWhere('id', $combo)['drugs']));

        // Clearing or changing the composition changes the links with it.
        $this->putJson("/api/pharmacy/medicines/{$same}/composition", ['composition' => 'Aceclofenac 100 mg'])->assertOk()->assertJsonPath('composition', 'Aceclofenac 100 mg');
        $this->putJson("/api/pharmacy/medicines/{$dolo}/composition", ['composition' => null])->assertOk()->assertJsonPath('composition', null);
        $this->assertSame([2, 0], [DrugIngredient::where('name', 'Paracetamol')->first()->medicines()->count(), Medicine::find($dolo)->ingredients()->count()]);
        $this->putJson("/api/pharmacy/medicines/{$dolo}/composition", ['composition' => 'Paracetamol 650 mg'])->assertOk();

        // The doctor, prescribing: typing a drug shows the single drugs and the combinations, what is on the shelf first.
        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $found = $this->getJson('/api/medicines/find?q=paracet')->assertOk()->json();
        $this->assertSame(
            [['CALPOL 650 TAB', 40, false, true], ['ZERODOL-P TAB', 20, true, true], ['DOLO 650 TAB', 0, false, true]],
            array_map(fn ($m) => [$m['name'], $m['in_stock'], $m['combination'], $m['by_drug']], $found),
        );
        $this->assertSame('Aceclofenac 100 mg + Paracetamol 325 mg', $found[1]['composition']);
        // A prescription's medicines are described in one go: what each contains, what is on the shelf, and what the pharmacy does not list.
        $about = collect($this->getJson('/api/medicines/describe?'.http_build_query(['names' => ['calpol 650  tab', 'ZERODOL-P TAB', 'Something From Outside']]))->assertOk()->json())->keyBy('name');
        $this->assertSame([true, 'Paracetamol 650 mg', 40, false], [$about['calpol 650 tab']['listed'], $about['calpol 650 tab']['composition'], $about['calpol 650 tab']['in_stock'], $about['calpol 650 tab']['combination']]);
        $this->assertSame([true, 20, true], [$about['zerodol-p tab']['listed'], $about['zerodol-p tab']['in_stock'], $about['zerodol-p tab']['combination']]);
        $this->assertSame([false, null], [$about['something from outside']['listed'], $about['something from outside']['composition']]);

        // By brand name it is found as before, and a single letter finds nothing.
        $this->getJson('/api/medicines/find?q=cetz')->assertOk()->assertJsonCount(1)->assertJsonPath('0.by_drug', false)->assertJsonPath('0.composition', null);
        $this->getJson('/api/medicines/find?q=p')->assertOk()->assertJsonCount(0);
        // The plain list of names the phone app uses also finds by drug.
        $this->assertContains('ZERODOL-P TAB', $this->getJson('/api/medicines/suggest?q=aceclo')->assertOk()->json());
        $this->assertNotNull($plain);
    }
}
