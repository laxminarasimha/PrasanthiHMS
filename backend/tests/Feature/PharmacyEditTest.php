<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\PharmacyChange;
use App\Models\StockBatch;
use App\Models\Stockist;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyEditTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $medicine;

    private Stockist $stockist;

    private StockBatch $batch;

    private array $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->medicine = Medicine::create(['name' => 'Paracetamol 650mg Tablet', 'gst_percent' => 12, 'units_per_pack' => 10, 'reorder_level' => 20]);
        $this->stockist = Stockist::create(['name' => 'Sample Distributors']);
        // 10 packs of 10 at 15 a pack, MRP 28 a pack (2.80 a unit).
        $this->invoice = $this->postJson('/api/pharmacy/purchases', [
            'stockist_id' => $this->stockist->id, 'invoice_no' => 'INV-1', 'invoice_date' => now()->toDateString(),
            'items' => [[
                'medicine_id' => $this->medicine->id, 'batch_no' => 'B001', 'expiry' => now()->addYear()->format('Y-m'),
                'units_per_pack' => 10, 'packs' => 10, 'pack_price' => 15, 'pack_mrp' => 28, 'gst_percent' => 12,
            ]],
        ])->assertCreated()->json();
        $this->batch = StockBatch::find($this->invoice['batches'][0]['id']);
    }

    private function sell(int $quantity, float $paid): array
    {
        return $this->postJson('/api/pharmacy/sales', [
            'customer_name' => 'Walk-in', 'paid' => $paid, 'payment_mode' => 'cash',
            'items' => [['stock_batch_id' => $this->batch->id, 'quantity' => $quantity]],
        ])->assertCreated()->json();
    }

    private function report(string $type): array
    {
        return $this->getJson("/api/pharmacy/reports/{$type}")->assertOk()->json('rows');
    }

    public function test_reducing_a_bill_restocks_refunds_the_excess_and_is_logged(): void
    {
        $sale = $this->sell(20, 56); // 20 × 2.80 = 56, paid in full
        $line = $sale['items'][0]['id'];

        $this->putJson("/api/pharmacy/sales/{$sale['id']}", ['customer_name' => 'Ravi Kumar', 'items' => [['id' => $line, 'quantity' => 10]]])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->putJson("/api/pharmacy/sales/{$sale['id']}", [
            'customer_name' => 'Ravi Kumar', 'reason' => 'Wrong quantity entered', 'refund_mode' => 'upi',
            'items' => [['id' => $line, 'quantity' => 10]],
        ])->assertOk()
            ->assertJsonPath('total', 28)->assertJsonPath('paid', 28)->assertJsonPath('due', 0)
            ->assertJsonPath('customer_name', 'Ravi Kumar')->assertJsonPath('items.0.quantity', 10);

        $this->assertSame(90, $this->batch->fresh()->units_in_stock);
        $this->assertDatabaseHas('pharmacy_payments', ['pharmacy_sale_id' => $sale['id'], 'type' => 'refund', 'amount' => 28, 'mode' => 'upi']);

        $rows = $this->report('bills-modified');
        $this->assertCount(1, $rows);
        $this->assertSame($sale['bill_no'], $rows[0]['subject']);
        $this->assertStringContainsString('Customer: Walk-in → Ravi Kumar', $rows[0]['changes']);
        $this->assertStringContainsString('quantity: 20 → 10', $rows[0]['changes']);
        $this->assertStringContainsString('Refunded ₹28', $rows[0]['changes']);
        $this->assertSame('Wrong quantity entered', $rows[0]['reason']);
    }

    public function test_a_bill_can_grow_only_while_stock_lasts_and_leaves_a_balance_due(): void
    {
        $sale = $this->sell(10, 28);
        $line = $sale['items'][0]['id'];
        $edit = fn (array $body) => $this->putJson("/api/pharmacy/sales/{$sale['id']}", $body + ['customer_name' => 'Walk-in', 'reason' => 'Customer took more']);

        $edit(['items' => [['id' => $line, 'quantity' => 101]]])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $edit(['items' => [['id' => $line, 'quantity' => 30, 'discount_percent' => 10]], 'discount' => 5.6])->assertOk()
            ->assertJsonPath('subtotal', 75.6)->assertJsonPath('total', 70)->assertJsonPath('due', 42);
        $this->assertSame(70, $this->batch->fresh()->units_in_stock);

        $edit(['discount' => 500])->assertStatus(422)->assertJsonValidationErrors('discount');
        $edit(['discount' => 5.6])->assertStatus(422)->assertJsonValidationErrors('reason'); // nothing changed
        $edit(['items' => [['id' => $line, 'quantity' => 0]]])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertSame(70, $this->batch->fresh()->units_in_stock);
    }

    public function test_more_medicines_can_be_added_to_a_saved_bill_and_paid_for(): void
    {
        // A second medicine on the shelf: 5 packs of 10, MRP 50 a pack (5 a unit).
        $other = Medicine::create(['name' => 'Cetirizine 10mg Tablet', 'gst_percent' => 12, 'units_per_pack' => 10]);
        $second = StockBatch::find($this->postJson('/api/pharmacy/purchases', [
            'stockist_id' => $this->stockist->id, 'invoice_no' => 'INV-2', 'invoice_date' => now()->toDateString(),
            'items' => [['medicine_id' => $other->id, 'batch_no' => 'C001', 'expiry' => now()->addYear()->format('Y-m'), 'units_per_pack' => 10, 'packs' => 5, 'pack_price' => 30, 'pack_mrp' => 50, 'gst_percent' => 12]],
        ])->assertCreated()->json('batches.0.id'));
        $sale = $this->sell(10, 28); // 10 × 2.80 = 28, paid in full
        $url = "/api/pharmacy/sales/{$sale['id']}";

        // The customer comes back for 6 of the other medicine (30) and 5 more of the first (14). No reason is asked, and the money is taken with it.
        $this->putJson($url, [
            'customer_name' => 'Walk-in', 'collect' => 44, 'collect_mode' => 'upi',
            'add' => [['stock_batch_id' => $second->id, 'quantity' => 6], ['stock_batch_id' => $this->batch->id, 'quantity' => 5]],
        ])->assertOk()->assertJsonPath('total', 72)->assertJsonPath('paid', 72)->assertJsonPath('due', 0)
            ->assertJsonCount(2, 'items')->assertJsonPath('items.0.quantity', 15)->assertJsonPath('items.1.medicine_name', 'Cetirizine 10mg Tablet')
            ->assertJsonPath('items.1.quantity', 6)->assertJsonPath('items.1.amount', 30)
            ->assertJsonCount(2, 'payments')->assertJsonPath('payments.1.mode', 'upi')->assertJsonPath('payments.1.amount', 44);

        // The shelf and its ledger both follow.
        $this->assertSame(85, $this->batch->fresh()->units_in_stock);
        $this->assertSame(44, $second->fresh()->units_in_stock);
        $this->assertSame(44, (int) StockMovement::where('stock_batch_id', $second->id)->sum('units'));
        $this->assertSame(85, (int) StockMovement::where('stock_batch_id', $this->batch->id)->sum('units'));
        $change = PharmacyChange::latest('id')->first();
        $this->assertSame('Medicines added to the bill', $change->reason);
        $this->assertStringContainsString('Cetirizine 10mg Tablet (batch C001): added, 6 for ₹30', $change->summary);
        $this->assertStringContainsString('Collected ₹44 (upi)', $change->summary);

        // More than the shelf holds, or more money than is then due, is refused, and nothing moves.
        $this->putJson($url, ['customer_name' => 'Walk-in', 'add' => [['stock_batch_id' => $second->id, 'quantity' => 100]]])->assertStatus(422)->assertJsonValidationErrors('add.0.quantity');
        $this->putJson($url, ['customer_name' => 'Walk-in', 'collect' => 100, 'collect_mode' => 'cash', 'add' => [['stock_batch_id' => $second->id, 'quantity' => 1]]])->assertStatus(422)->assertJsonValidationErrors('collect');
        $this->assertSame(44, $second->fresh()->units_in_stock);

        // Added without taking money: the extra stays as the balance on the bill.
        $this->putJson($url, ['customer_name' => 'Walk-in', 'add' => [['stock_batch_id' => $second->id, 'quantity' => 2]]])->assertOk()
            ->assertJsonPath('total', 82)->assertJsonPath('due', 10)->assertJsonPath('items.1.quantity', 8);

        // Any other change still needs a reason, even when a medicine is added along with it.
        $this->putJson($url, ['customer_name' => 'Ravi Kumar'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->putJson($url, ['customer_name' => 'Walk-in', 'discount' => 20, 'add' => [['stock_batch_id' => $second->id, 'quantity' => 1]]])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(42, $second->fresh()->units_in_stock);
    }

    public function test_bills_with_returns_or_deleted_bills_cannot_be_edited(): void
    {
        $returned = $this->sell(10, 28);
        $this->postJson("/api/pharmacy/sales/{$returned['id']}/returns", ['refund_mode' => 'cash', 'items' => [['id' => $returned['items'][0]['id'], 'quantity' => 2]]])->assertCreated();
        $this->putJson("/api/pharmacy/sales/{$returned['id']}", ['customer_name' => 'A', 'reason' => 'Fix name'])->assertStatus(422);

        $deleted = $this->sell(5, 0);
        $this->deleteJson("/api/pharmacy/sales/{$deleted['id']}", ['reason' => 'Made by mistake'])->assertOk();
        $this->putJson("/api/pharmacy/sales/{$deleted['id']}", ['customer_name' => 'A', 'reason' => 'Fix name'])->assertStatus(422);

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->putJson("/api/pharmacy/sales/{$returned['id']}", ['customer_name' => 'A', 'reason' => 'Fix name'])->assertForbidden();
    }

    public function test_a_purchase_invoice_can_be_corrected_and_stock_follows(): void
    {
        $this->sell(30, 0); // 70 left of 100
        $line = ['id' => $this->batch->id, 'batch_no' => 'B001', 'expiry' => now()->addYear()->format('Y-m'), 'packs' => 10, 'pack_price' => 15, 'pack_mrp' => 28, 'gst_percent' => 12];
        $edit = fn (array $item, array $head = []) => $this->putJson("/api/pharmacy/purchases/{$this->invoice['id']}", $head + [
            'stockist_id' => $this->stockist->id, 'invoice_no' => 'INV-1', 'invoice_date' => now()->toDateString(), 'reason' => 'Entered wrongly', 'items' => [$item + $line],
        ]);

        // Only 2 packs would be 20 units, but 30 have already been sold.
        $edit(['packs' => 2])->assertStatus(422)->assertJsonValidationErrors('items.0.packs');
        $edit([])->assertStatus(422)->assertJsonValidationErrors('reason'); // nothing changed

        $edit(['packs' => 8, 'free_packs' => 1, 'batch_no' => 'B001A', 'pack_mrp' => 30], ['invoice_no' => 'INV-1A'])->assertOk()
            ->assertJsonPath('invoice_no', 'INV-1A')->assertJsonPath('subtotal', 120)->assertJsonPath('tax', 14.4)->assertJsonPath('total', 134.4)
            ->assertJsonPath('batches.0.units_received', 90)->assertJsonPath('batches.0.units_in_stock', 60)
            ->assertJsonPath('batches.0.batch_no', 'B001A')->assertJsonPath('batches.0.unit_mrp', 3);

        $rows = $this->report('invoices-modified');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Invoice no.: INV-1 → INV-1A', $rows[0]['changes']);
        $this->assertStringContainsString('packs: 10 → 8', $rows[0]['changes']);
        $this->assertStringContainsString('Invoice total ₹: 168 → 134.4', $rows[0]['changes']);

        // Money already paid cannot end up above the new total.
        $this->postJson("/api/pharmacy/purchases/{$this->invoice['id']}/payments", ['amount' => 134.4, 'mode' => 'cash'])->assertSuccessful();
        $edit(['packs' => 7, 'free_packs' => 1, 'batch_no' => 'B001A', 'pack_mrp' => 30], ['invoice_no' => 'INV-1A'])->assertStatus(422);
    }

    public function test_batch_details_and_medicine_changes_are_logged(): void
    {
        $this->putJson("/api/pharmacy/stock/{$this->batch->id}", ['batch_no' => 'B001', 'expiry' => now()->addYears(2)->format('Y-m'), 'pack_mrp' => 32, 'rack_no' => 'R4', 'reason' => 'Label read wrongly'])
            ->assertOk()->assertJsonPath('unit_mrp', 3.2)->assertJsonPath('rack_no', 'R4');
        $this->putJson("/api/pharmacy/stock/{$this->batch->id}", ['batch_no' => 'B001', 'expiry' => now()->addYears(2)->format('Y-m'), 'pack_mrp' => 32, 'rack_no' => 'R4', 'reason' => 'Again'])->assertStatus(422);
        $stock = $this->report('stock-updated');
        $this->assertCount(1, $stock);
        $this->assertStringContainsString('Pack MRP ₹: 28 → 32', $stock[0]['changes']);
        $this->assertStringContainsString('Paracetamol 650mg Tablet', $stock[0]['subject']);

        $this->putJson("/api/pharmacy/medicines/{$this->medicine->id}", ['name' => 'Paracetamol 650 Tablet', 'gst_percent' => 5, 'units_per_pack' => 10, 'is_scheduled' => true])->assertOk();
        $this->putJson("/api/pharmacy/medicines/{$this->medicine->id}", ['name' => 'Paracetamol 650 Tablet', 'gst_percent' => 5, 'units_per_pack' => 10, 'is_scheduled' => true])->assertOk();
        $medicines = $this->report('medicines-updated');
        $this->assertCount(1, $medicines); // the second save changed nothing
        $this->assertSame('Paracetamol 650mg Tablet', $medicines[0]['subject']);
        $this->assertStringContainsString('GST %: 12 → 5', $medicines[0]['changes']);
        $this->assertStringContainsString('Scheduled drug: No → Yes', $medicines[0]['changes']);
    }
}
