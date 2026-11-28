<?php

namespace Tests\Feature;

use App\Models\DrugIngredient;
use App\Models\Medicine;
use App\Models\RepCompany;
use App\Models\RepProduct;
use App\Models\User;
use App\Support\Composition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The companies' catalogues: each brand, what it contains, what is on offer, set beside the pharmacy's own medicines. */
class RepCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private RepCompany $company;

    private RepCompany $other;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->company = RepCompany::create(['name' => 'Sample Pharma']);
        $this->other = RepCompany::create(['name' => 'Other Labs']);
    }

    public function test_a_brand_is_linked_to_its_drugs_and_set_beside_the_pharmacys_own(): void
    {
        // The pharmacy keeps one brand of this drug already.
        $ours = Medicine::create(['name' => 'Ourbrand 40', 'gst_percent' => 12, 'units_per_pack' => 10]);
        Composition::apply($ours, 'pantoprazole 40mg');

        $this->postJson('/api/reps/products', ['rep_company_id' => $this->company->id, 'name' => 'X'])->assertStatus(422)->assertJsonValidationErrors('name');
        $product = $this->postJson('/api/reps/products', [
            'rep_company_id' => $this->company->id, 'name' => 'Samplepan 40', 'composition' => 'Pantoprazole 40 mg', 'form' => 'Tablet', 'pack' => 'Strip of 10',
            'mrp' => 120, 'rate' => 84.5, 'scheme' => '10 + 1',
        ])->assertCreated()->assertJsonPath('composition', 'Pantoprazole 40 mg')->assertJsonPath('company.name', 'Sample Pharma')->json();
        // Typed another way, it is still the same drug: one record of it, shared with the pharmacy's medicine.
        $this->postJson('/api/reps/products', ['rep_company_id' => $this->other->id, 'name' => 'Otherpan-D', 'composition' => 'domperidone 10mg + PANTOPRAZOLE 40 MG'])
            ->assertCreated()->assertJsonPath('composition', 'Domperidone 10 mg + Pantoprazole 40 mg');
        $this->postJson('/api/reps/products', ['rep_company_id' => $this->other->id, 'name' => 'Otherpan 40', 'composition' => 'Pantoprazole 40mg', 'mrp' => 110])->assertCreated();
        $this->assertSame(1, DrugIngredient::whereRaw('lower(name) = ?', ['pantoprazole'])->count());
        $this->assertSame(1, RepProduct::find($product['id'])->ingredients()->count());
        $this->assertSame(2, RepProduct::where('name', 'Otherpan-D')->sole()->ingredients()->count());
        $this->postJson('/api/reps/products', ['rep_company_id' => $this->company->id, 'name' => 'samplepan 40'])->assertStatus(422)->assertJsonValidationErrors('name');

        // Found by the drug, each with what the pharmacy keeps of the same thing and who else offers it.
        $found = collect($this->getJson('/api/reps/products?q=pantoprazole')->assertOk()->assertJsonCount(3, 'products')->assertJsonPath('can_change', true)->json('products'))->keyBy('name');
        $this->assertSame('Ourbrand 40', $found['Samplepan 40']['in_pharmacy'][0]['name']);
        $this->assertSame(['Otherpan 40'], array_column($found['Samplepan 40']['also_from'], 'name'));
        $this->assertSame([], $found['Otherpan-D']['in_pharmacy']);
        $this->getJson("/api/reps/products?company_id={$this->other->id}")->assertJsonCount(2, 'products');

        // The pharmacy's own medicine is never changed by the catalogue.
        $this->assertSame('Pantoprazole 40 mg', $ours->fresh()->composition);
        $this->putJson("/api/reps/products/{$product['id']}", ['rep_company_id' => $this->company->id, 'name' => 'Samplepan 40', 'composition' => 'Pantoprazole 20 mg', 'rate' => 80])
            ->assertOk()->assertJsonPath('composition', 'Pantoprazole 20 mg')->assertJsonPath('rate', 80);
        $this->assertSame('Pantoprazole 40 mg', $ours->fresh()->composition);

        // A brand the pharmacy keeps under the same name is linked to it, and takes its composition when none is typed.
        $linked = $this->postJson('/api/reps/products', ['rep_company_id' => $this->company->id, 'name' => 'ourbrand 40'])->assertCreated()->json();
        $this->assertSame($ours->id, $linked['medicine_id']);
        $this->assertSame('Pantoprazole 40 mg', $linked['composition']);

        $this->deleteJson("/api/reps/products/{$product['id']}")->assertNoContent();
        $this->assertNull(RepProduct::find($product['id']));
    }

    public function test_a_companys_price_sheet_is_checked_before_it_is_taken_in(): void
    {
        $this->postJson('/api/reps/products', ['rep_company_id' => $this->company->id, 'name' => 'Samplepan 40', 'composition' => 'Pantoprazole 40 mg', 'mrp' => 100, 'form' => 'Tablet'])->assertCreated();
        $rows = [
            ['name' => 'Samplepan 40', 'composition' => 'pantoprazole 40mg', 'mrp' => '₹120', 'rate' => '84.50', 'scheme' => '10+1'],
            ['name' => 'Samplecef 200', 'composition' => 'Cefixime 200 mg', 'form' => 'Tablet', 'pack' => '10 tablets', 'mrp' => '1,090'],
            ['name' => '', 'composition' => 'Something'],
            ['name' => 'Samplecef 200', 'mrp' => '50'],
            ['name' => 'Samplezole', 'mrp' => 'ask'],
            ['name' => 'Samplevit'],
        ];

        // Looked at first: nothing is saved, and each line says what is wrong with it.
        $check = $this->postJson('/api/reps/products/sheet', ['company_id' => $this->company->id, 'rows' => $rows])->assertOk()
            ->assertJsonPath('saved', 0)
            ->assertJsonPath('totals', ['lines' => 6, 'good' => 3, 'wrong' => 3, 'new' => 2, 'updated' => 1, 'without_composition' => 1])
            ->json('rows');
        $this->assertTrue($check[0]['already']);
        $this->assertSame('Pantoprazole 40 mg', $check[0]['composition']);
        $this->assertEquals(1090, $check[1]['mrp']);
        $this->assertStringContainsString('missing', $check[2]['errors'][0]);
        $this->assertStringContainsString('twice (line 3)', $check[3]['errors'][0]);
        $this->assertStringContainsString('not a number', $check[4]['errors'][0]);
        $this->assertSame(1, RepProduct::count());

        // Taken in: the good lines only; the brand already there is brought up to date, not added twice.
        $this->postJson('/api/reps/products/sheet', ['company_id' => $this->company->id, 'rows' => $rows, 'save' => true])->assertOk()->assertJsonPath('saved', 3);
        $this->assertSame(3, RepProduct::count());
        $pan = RepProduct::where('name', 'Samplepan 40')->sole();
        $this->assertEquals(120, $pan->mrp);
        $this->assertEquals(84.5, $pan->rate);
        $this->assertSame('10+1', $pan->scheme);
        // The sheet had no column for the form, so what was typed before stays.
        $this->assertSame('Tablet', $pan->form);
        // A later sheet with only names and prices leaves what each brand contains as it was.
        $this->postJson('/api/reps/products/sheet', ['company_id' => $this->company->id, 'rows' => [['name' => 'SAMPLEPAN 40', 'mrp' => '125']], 'save' => true])->assertOk()->assertJsonPath('saved', 1);
        $this->assertSame('Pantoprazole 40 mg', $pan->fresh()->composition);
        $this->assertEquals(125, $pan->fresh()->mrp);
        $this->assertSame('Samplepan 40', $pan->fresh()->name);
        $this->assertSame('Cefixime 200 mg', RepProduct::where('name', 'Samplecef 200')->value('composition'));
        $this->assertNull(RepProduct::where('name', 'Samplevit')->value('composition'));
    }

    public function test_the_catalogue_shows_where_medicines_are_ordered_and_prescribed(): void
    {
        $ours = Medicine::create(['name' => 'Ourbrand 40', 'gst_percent' => 12, 'units_per_pack' => 10]);
        Composition::apply($ours, 'Pantoprazole 40 mg');
        $this->postJson('/api/reps/people', ['name' => 'Sample Rep One', 'company' => 'Sample Pharma', 'mobile' => '9000011111'])->assertCreated();
        $this->postJson('/api/reps/products', ['rep_company_id' => $this->company->id, 'name' => 'Samplepan 40', 'composition' => 'Pantoprazole 40 mg', 'rate' => 84.5, 'scheme' => '10 + 1'])->assertCreated();
        $this->postJson('/api/reps/products', ['rep_company_id' => $this->company->id, 'name' => 'Ourbrand 40'])->assertCreated();

        // Where the pharmacy chooses what to order: the offer, the company, and whom to ring.
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $offers = collect($this->getJson('/api/pharmacy/medicines/options')->assertOk()->json('offers'))->keyBy('name');
        $this->assertSame('Pantoprazole 40 mg', $offers['Samplepan 40']['composition']);
        $this->assertSame('Sample Pharma', $offers['Samplepan 40']['company']);
        $this->assertSame('10 + 1', $offers['Samplepan 40']['scheme']);
        $this->assertSame('9000011111', $offers['Samplepan 40']['reps'][0]['mobile']);
        // The pharmacy may add to the catalogue too; a lab login may not even read it.
        $this->postJson('/api/reps/products', ['rep_company_id' => $this->other->id, 'name' => 'Otherpan 40', 'composition' => 'Pantoprazole 40 mg'])->assertCreated();
        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
        $this->getJson('/api/reps/products')->assertForbidden();

        // While prescribing: the pharmacy's own brand first, then catalogue brands it does not list, named by company.
        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $found = $this->getJson('/api/medicines/find?q=pantop')->assertOk()->json();
        $this->assertSame(['Ourbrand 40', 'Otherpan 40', 'Samplepan 40'], array_column($found, 'name'));
        $this->assertArrayNotHasKey('catalogue', $found[0]);
        $this->assertSame('Other Labs', $found[1]['catalogue']);
        $this->assertTrue($found[1]['by_drug']);
        // A doctor reads the catalogue too.
        $this->getJson('/api/reps/products')->assertOk()->assertJsonCount(3, 'products');
    }
}
