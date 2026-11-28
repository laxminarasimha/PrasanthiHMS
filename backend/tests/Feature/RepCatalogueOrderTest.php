<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Medicine;
use App\Models\PurchaseOrder;
use App\Models\RepCompany;
use App\Models\RepProduct;
use App\Models\Stockist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A medicine company's brands come through one stockist, chosen by those who run the pharmacy, and a brand the
 * pharmacy keeps under the same name can be ordered from the catalogue as a draft order. The pharmacy's medicines
 * are never made or changed by any of it.
 */
class RepCatalogueOrderTest extends TestCase
{
    use RefreshDatabase;

    private Stockist $city;

    private Stockist $far;

    private RepCompany $company;

    private RepCompany $other;

    private Medicine $ours;

    private RepProduct $linked;

    protected function setUp(): void
    {
        parent::setUp();
        $this->city = Stockist::create(['name' => 'City Sample Agencies', 'lead_days' => 2]);
        $this->far = Stockist::create(['name' => 'Far Sample Distributors', 'lead_days' => 5]);
        $this->company = RepCompany::create(['name' => 'Sample Pharma']);
        $this->other = RepCompany::create(['name' => 'Other Labs']);
        // The pharmacy keeps it in packs of 15; the company's catalogue says a strip of 10.
        $this->ours = Medicine::create(['name' => 'Samplepan 40', 'gst_percent' => 12, 'units_per_pack' => 15, 'preferred_stockist_id' => $this->far->id]);
        $this->linked = RepProduct::create(['rep_company_id' => $this->company->id, 'name' => 'Samplepan 40', 'pack' => 'Strip of 10', 'rate' => 84.5, 'medicine_id' => $this->ours->id]);
    }

    private function signIn(string $role, array $over = []): User
    {
        $user = User::factory()->create($over + ['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function medicines(): array
    {
        return Medicine::orderBy('id')->get()->map(fn (Medicine $m) => $m->only(['id', 'name', 'units_per_pack', 'gst_percent', 'preferred_stockist_id', 'is_active', 'composition']))->all();
    }

    public function test_those_who_run_the_pharmacy_choose_the_stockist_a_company_supplies_through(): void
    {
        $before = $this->medicines();
        $url = "/api/reps/companies/{$this->company->id}/stockist";

        // The front desk keeps the company, but not its stockist; a login that only looks at the pharmacy may not set it either.
        $this->signIn('frontdesk');
        $this->putJson($url, ['stockist_id' => $this->city->id])->assertForbidden();
        $this->signIn('manager');
        $this->putJson($url, ['stockist_id' => $this->city->id])->assertForbidden();
        $this->signIn('pharmacist', ['permissions' => ['canRunPharmacy' => false]]);
        $this->putJson($url, ['stockist_id' => $this->city->id])->assertForbidden();
        $this->assertNull($this->company->fresh()->stockist_id);

        $this->signIn('pharmacist');
        $this->putJson($url, ['stockist_id' => 99999])->assertStatus(422)->assertJsonValidationErrors('stockist_id');
        $this->putJson($url, [])->assertStatus(422)->assertJsonValidationErrors('stockist_id');
        $this->putJson($url, ['stockist_id' => $this->city->id])->assertOk()->assertJsonPath('stockist.name', 'City Sample Agencies');
        $this->assertTrue(AuditLog::where('subject_type', 'RepCompany')->where('subject_id', $this->company->id)->where('summary', 'like', '%no stockist → City Sample Agencies%')->exists());

        // Everyone who reads the companies sees it; the desk renaming the company leaves it as it is.
        $this->signIn('frontdesk');
        $companies = collect($this->getJson('/api/reps/companies')->assertOk()->assertJsonCount(2)->json())->keyBy('name');
        $this->assertSame('City Sample Agencies', $companies['Sample Pharma']['stockist']['name']);
        $this->assertNull($companies['Other Labs']['stockist']);
        $this->putJson("/api/reps/companies/{$this->company->id}", ['name' => 'Sample Pharma Ltd', 'notes' => null])->assertOk();
        $this->assertSame($this->city->id, $this->company->fresh()->stockist_id);

        // A doctor runs the pharmacy too, by default; one stockist a company, and it can be cleared.
        $this->signIn('doctor');
        $this->putJson($url, ['stockist_id' => $this->far->id])->assertOk()->assertJsonPath('stockist.name', 'Far Sample Distributors');
        $this->putJson($url, ['stockist_id' => null])->assertOk()->assertJsonPath('stockist', null);
        $this->assertNull($this->company->fresh()->stockist_id);

        $this->assertSame($before, $this->medicines());
    }

    public function test_a_brand_the_pharmacy_keeps_is_ordered_as_a_draft_for_the_companys_stockist(): void
    {
        $theirs = Medicine::create(['name' => 'Otherpan 40', 'gst_percent' => 12, 'units_per_pack' => 10]);
        $otherBrand = RepProduct::create(['rep_company_id' => $this->other->id, 'name' => 'Otherpan 40', 'medicine_id' => $theirs->id]);
        $before = $this->medicines();
        $this->signIn('pharmacist');
        $this->putJson("/api/reps/companies/{$this->company->id}/stockist", ['stockist_id' => $this->city->id])->assertOk();

        // No supplier chosen on the line: the company's stockist. The pharmacy's own pack of 15 is ordered, not the rep's strip of 10.
        $made = $this->postJson('/api/pharmacy/manager/orders', ['from' => 'catalogue', 'lines' => [['rep_product_id' => $this->linked->id, 'packs' => 3]]])
            ->assertCreated()->assertJsonCount(1, 'orders')->assertJsonPath('orders.0.stockist', 'City Sample Agencies')->assertJsonPath('orders.0.items', 1)->json('orders.0');
        $order = PurchaseOrder::with('items')->find($made['id']);
        $this->assertSame(['draft', $this->city->id, today()->addDays(2)->toDateString(), 'Chosen from the catalogue of Sample Pharma'], [$order->status, $order->stockist_id, $order->expected_on->toDateString(), $order->remarks]);
        $this->assertSame([[$this->ours->id, 'Samplepan 40', 15, 3]], $order->items->map(fn ($i) => [$i->medicine_id, $i->medicine_name, $i->units_per_pack, $i->packs])->all());
        $this->assertTrue(AuditLog::where('summary', 'like', 'Made draft purchase orders from the companies’ catalogues%')->exists());

        // A supplier chosen on the line is used; one new draft for each stockist, each time.
        $this->postJson('/api/pharmacy/manager/orders', ['from' => 'catalogue', 'lines' => [
            ['rep_product_id' => $this->linked->id, 'stockist_id' => $this->far->id, 'packs' => 2],
            ['rep_product_id' => $otherBrand->id, 'stockist_id' => $this->city->id, 'packs' => 1],
        ]])->assertCreated()->assertJsonCount(2, 'orders');
        $this->assertSame(3, PurchaseOrder::where('status', 'draft')->count());
        $far = PurchaseOrder::with('items')->where('stockist_id', $this->far->id)->sole();
        $this->assertSame([today()->addDays(5)->toDateString(), 'Chosen from the catalogue of Sample Pharma', [$this->ours->id]], [$far->expected_on->toDateString(), $far->remarks, $far->items->pluck('medicine_id')->all()]);
        $this->assertSame('Chosen from the catalogue of Other Labs', PurchaseOrder::where('stockist_id', $this->city->id)->latest('id')->value('remarks'));

        // Both drafts count as on order, in the pharmacy's units, beside the brand in the catalogue.
        $row = collect($this->getJson('/api/reps/products')->assertOk()->assertJsonPath('can_order', true)->json('products'))->firstWhere('id', $this->linked->id);
        $this->assertSame([75, $this->ours->id, 15, true], [$row['on_order'], $row['medicine']['id'], $row['medicine']['units_per_pack'], $row['medicine']['is_active']]);
        $this->assertSame([$this->far->id, $this->city->id, 'City Sample Agencies'], [$row['medicine']['preferred_stockist_id'], $row['company']['stockist_id'], $row['company']['stockist']['name']]);

        $this->assertSame($before, $this->medicines());
    }

    public function test_a_brand_that_cannot_be_ordered_is_refused_saying_why(): void
    {
        $unlinked = RepProduct::create(['rep_company_id' => $this->company->id, 'name' => 'Samplecef 200']);
        $old = Medicine::create(['name' => 'Sampleold 5', 'gst_percent' => 12, 'units_per_pack' => 10, 'is_active' => false]);
        $stopped = RepProduct::create(['rep_company_id' => $this->company->id, 'name' => 'Sampleold 5', 'medicine_id' => $old->id]);
        $theirs = Medicine::create(['name' => 'Otherpan 40', 'gst_percent' => 12, 'units_per_pack' => 10]);
        $noStockist = RepProduct::create(['rep_company_id' => $this->other->id, 'name' => 'Otherpan 40', 'medicine_id' => $theirs->id]);
        $this->company->update(['stockist_id' => $this->city->id]);
        $before = $this->medicines();
        $order = fn (array $lines) => $this->postJson('/api/pharmacy/manager/orders', ['from' => 'catalogue', 'lines' => $lines]);

        $this->signIn('frontdesk');
        $order([['rep_product_id' => $this->linked->id, 'packs' => 1]])->assertForbidden();
        $this->signIn('manager');
        $order([['rep_product_id' => $this->linked->id, 'packs' => 1]])->assertForbidden();

        $this->signIn('pharmacist');
        $said = fn ($response, string $field) => $response->assertStatus(422)->assertJsonValidationErrors($field)->json('errors')[$field][0];
        $this->assertStringContainsString('Samplecef 200 is not in the pharmacy’s medicines under this name', $said($order([['rep_product_id' => $unlinked->id, 'packs' => 1]]), 'lines.0.rep_product_id'));
        $this->assertStringContainsString('Sampleold 5 is marked as not in use', $said($order([['rep_product_id' => $stopped->id, 'packs' => 1]]), 'lines.0.rep_product_id'));
        $this->assertStringContainsString('Other Labs has no stockist set', $said($order([['rep_product_id' => $noStockist->id, 'packs' => 1]]), 'lines.0.stockist_id'));
        // A line is a medicine or a brand, not both; and a brand is not ordered beside its own medicine on another line.
        $order([['rep_product_id' => $this->linked->id, 'medicine_id' => $theirs->id, 'stockist_id' => $this->city->id, 'packs' => 1]])->assertStatus(422)->assertJsonValidationErrors('lines.0.rep_product_id');
        $this->assertStringContainsString('Samplepan 40 is chosen twice', $said($order([
            ['rep_product_id' => $this->linked->id, 'packs' => 1], ['medicine_id' => $this->ours->id, 'stockist_id' => $this->city->id, 'packs' => 1],
        ]), 'lines.1.medicine_id'));
        // A medicine line still needs its supplier, as before; and a line needs a medicine or a brand.
        $order([['medicine_id' => $this->ours->id, 'packs' => 1]])->assertStatus(422)->assertJsonValidationErrors('lines.0.stockist_id');
        $this->assertSame('Every line needs its medicine, or a brand from a catalogue.', $said($order([['stockist_id' => $this->city->id, 'packs' => 1]]), 'lines.0.medicine_id'));
        // Nothing was half made: no order, and no medicine made or changed.
        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame($before, $this->medicines());

        // With a supplier chosen on the line, a company with no stockist is no bar.
        $order([['rep_product_id' => $noStockist->id, 'stockist_id' => $this->far->id, 'packs' => 4]])->assertCreated()->assertJsonPath('orders.0.stockist', 'Far Sample Distributors');
    }

    public function test_a_brand_taken_out_of_the_catalogue_after_it_was_chosen_is_refused_in_plain_words(): void
    {
        $this->company->update(['stockist_id' => $this->city->id]);
        $gone = $this->linked->id;
        $order = fn (array $lines) => $this->postJson('/api/pharmacy/manager/orders', ['from' => 'catalogue', 'lines' => $lines]);
        $said = fn ($response, string $field) => $response->assertStatus(422)->assertJsonValidationErrors($field)->json('errors')[$field][0];

        // Someone takes the brand out of the catalogue, on another screen, while it waits to be ordered here.
        $this->signIn('frontdesk');
        $this->deleteJson("/api/reps/products/{$gone}")->assertNoContent();

        $this->signIn('pharmacist');
        // With the supplier chosen on the line, and without it, as the catalogue sends it when the company has a stockist.
        $this->assertSame('That brand is no longer in the catalogue.', $said($order([['rep_product_id' => $gone, 'stockist_id' => $this->city->id, 'packs' => 1]]), 'lines.0.rep_product_id'));
        $this->assertSame('That brand is no longer in the catalogue.', $said($order([['rep_product_id' => $gone, 'packs' => 1]]), 'lines.0.rep_product_id'));
        // A supplier that is not on the list is told plainly too.
        $theirs = Medicine::create(['name' => 'Otherpan 40', 'gst_percent' => 12, 'units_per_pack' => 10]);
        $this->assertSame('That supplier is not in the list of stockists. Choose another.', $said($order([['medicine_id' => $theirs->id, 'stockist_id' => 99999, 'packs' => 1]]), 'lines.0.stockist_id'));
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_the_catalogue_tells_only_the_pharmacy_what_is_on_order(): void
    {
        $this->signIn('pharmacist');
        $this->postJson('/api/pharmacy/manager/orders', ['lines' => [['medicine_id' => $this->ours->id, 'stockist_id' => $this->city->id, 'packs' => 2]]])->assertCreated();
        $this->company->update(['stockist_id' => $this->city->id]);
        $row = fn () => collect($this->getJson('/api/reps/products')->assertOk()->json('products'))->firstWhere('id', $this->linked->id);

        // The front desk sees the brand, its linked medicine and the company's stockist; not what is on order or whom the pharmacy buys from.
        $this->signIn('frontdesk');
        $this->getJson('/api/reps/products')->assertOk()->assertJsonMissingPath('can_order');
        $seen = $row();
        $this->assertSame(['Samplepan 40', 'City Sample Agencies'], [$seen['medicine']['name'], $seen['company']['stockist']['name']]);
        $this->assertArrayNotHasKey('on_order', $seen);
        $this->assertArrayNotHasKey('preferred_stockist_id', $seen['medicine']);
        // So too a nurse, who reads the catalogue as a clinician.
        $this->signIn('nurse');
        $this->assertArrayNotHasKey('on_order', $row());

        // A login that only looks at the pharmacy is told what is on order, but may not order.
        $this->signIn('manager');
        $this->getJson('/api/reps/products')->assertJsonPath('can_order', false);
        $this->assertSame(30, $row()['on_order']);

        $this->signIn('pharmacist');
        $this->getJson('/api/reps/products')->assertJsonPath('can_order', true);
        $this->assertSame([30, $this->far->id], [$row()['on_order'], $row()['medicine']['preferred_stockist_id']]);
    }
}
