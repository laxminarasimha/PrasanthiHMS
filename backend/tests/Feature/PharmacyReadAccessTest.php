<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\Stockist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the pharmacy paid for its stock is the pharmacy's to read: its invoices with their rates, a stockist's page
 * with every bill, and the stock list with each batch's cost. Those who run the pharmacy, those who may only look at
 * it, and those who audit its stock read them; the rest of the hospital does not.
 */
class PharmacyReadAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoices_a_stockists_bills_and_the_stock_list_are_read_only_in_the_pharmacy(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $supplier = Stockist::create(['name' => 'Sample Distributors']);
        $m = Medicine::create(['name' => 'Sampleatorva 10', 'gst_percent' => 12, 'units_per_pack' => 10]);
        $invoice = $this->postJson('/api/pharmacy/purchases', ['stockist_id' => $supplier->id, 'invoice_no' => 'INV-1', 'invoice_date' => today()->toDateString(), 'items' => [
            ['medicine_id' => $m->id, 'batch_no' => 'A1', 'expiry' => now()->addYear()->format('Y-m'), 'units_per_pack' => 10, 'packs' => 3, 'pack_price' => 20, 'pack_mrp' => 30, 'gst_percent' => 12],
        ]])->assertCreated()->json();
        $reads = ['/api/pharmacy/purchases', "/api/pharmacy/purchases/{$invoice['id']}", "/api/pharmacy/stockists/{$supplier->id}", '/api/pharmacy/stock'];

        // The front desk, a nurse and the lab are refused every one of them.
        foreach (['frontdesk', 'nurse', 'lab_tech'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            foreach ($reads as $url) {
                $this->getJson($url)->assertForbidden();
            }
        }
        // So is a login whose pharmacy access was taken away.
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist', 'permissions' => ['canRunPharmacy' => false, 'canViewPharmacy' => false]]));
        $this->getJson('/api/pharmacy/stock')->assertForbidden();

        // The look-only manager, someone who audits the stock and nothing else of the pharmacy, and the pharmacist read them.
        $readers = [
            User::factory()->create(['role' => 'manager']),
            User::factory()->create(['role' => 'accountant', 'permissions' => ['canAuditPharmacy' => true]]),
            User::factory()->create(['role' => 'pharmacist']),
        ];
        foreach ($readers as $reader) {
            Sanctum::actingAs($reader);
            $this->getJson('/api/pharmacy/purchases')->assertOk()->assertJsonPath('0.invoice_no', 'INV-1');
            $this->getJson("/api/pharmacy/purchases/{$invoice['id']}")->assertOk()->assertJsonPath('batches.0.pack_price', 20);
            $this->getJson("/api/pharmacy/stockists/{$supplier->id}")->assertOk()->assertJsonPath('totals.invoices', 1);
            $this->getJson('/api/pharmacy/stock')->assertOk()->assertJsonPath('0.batch_no', 'A1');
        }
        // Looking is not changing: the manager still may not enter an invoice.
        Sanctum::actingAs($readers[0]);
        $this->postJson('/api/pharmacy/purchases', ['stockist_id' => $supplier->id, 'invoice_no' => 'INV-2', 'invoice_date' => today()->toDateString(), 'items' => []])->assertForbidden();
    }
}
