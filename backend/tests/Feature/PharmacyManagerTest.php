<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\StockBatch;
use App\Models\Stockist;
use App\Models\StockReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyManagerTest extends TestCase
{
    use RefreshDatabase;

    private function medicine(string $name, array $over = []): Medicine
    {
        return Medicine::create($over + ['name' => $name, 'gst_percent' => 12, 'units_per_pack' => 10]);
    }

    private function batch(Medicine $medicine, int $units, array $over = []): StockBatch
    {
        return StockBatch::create($over + [
            'medicine_id' => $medicine->id, 'batch_no' => 'B'.$medicine->id.'-'.StockBatch::count(), 'expiry' => now()->addYear(), 'units_per_pack' => 10,
            'packs' => (int) ceil($units / 10), 'pack_price' => 20, 'pack_mrp' => 30, 'unit_mrp' => 3, 'gst_percent' => 12, 'units_received' => $units, 'units_in_stock' => $units,
        ]);
    }

    private function sell(StockBatch $batch, int $units): void
    {
        $this->postJson('/api/pharmacy/sales', ['customer_name' => 'Walk-in', 'paid' => $units * 3, 'payment_mode' => 'cash', 'items' => [['stock_batch_id' => $batch->id, 'quantity' => $units]]])->assertCreated();
    }

    public function test_the_reorder_list_says_what_to_order_and_how_much(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $slowSupplier = Stockist::create(['name' => 'Far Distributors', 'lead_days' => 5]);
        $supplier = Stockist::create(['name' => 'City Pharma', 'lead_days' => 2]);

        // Kept by a fixed minimum, no sales yet: 20 on the shelf against a minimum of 50.
        $low = $this->medicine('Atorvastatin 10', ['reorder_level' => 50, 'preferred_stockist_id' => $supplier->id]);
        $this->batch($low, 20);
        // Sells 2 a day and is finished.
        $out = $this->medicine('Paracetamol 500', ['preferred_stockist_id' => $supplier->id]);
        $this->sell($sold = $this->batch($out, 60), 60);
        // Sells 3 a day, 9 left, and the supplier takes 5 days.
        $urgent = $this->medicine('Metformin 500', ['preferred_stockist_id' => $slowSupplier->id]);
        $this->sell($this->batch($urgent, 99), 90);
        // Plenty on the shelf.
        $fine = $this->medicine('Cetirizine 10', ['reorder_level' => 20]);
        $this->batch($fine, 500);
        // The only stock expires in three weeks, so it is not counted as cover.
        $dying = $this->medicine('Amoxicillin 250', ['reorder_level' => 30, 'desired_stock' => 120]);
        $this->batch($dying, 40, ['expiry' => now()->addDays(20)]);
        // Never sold, nothing kept: not something to order.
        $this->medicine('Rare Drug');
        // In the list with a minimum, but never once on the shelf: opening stock is still to be done, so it is not "out".
        $this->medicine('Never Stocked', ['reorder_level' => 10]);

        $data = $this->getJson('/api/pharmacy/manager')->assertOk()->json();
        $rows = collect($data['reorder'])->keyBy('name');
        // Most pressing first: out of stock, then about to run out, then low.
        $this->assertSame(['Amoxicillin 250', 'Paracetamol 500', 'Metformin 500', 'Atorvastatin 10'], array_column($data['reorder'], 'name'));
        $this->assertFalse($rows->has('Cetirizine 10'));
        $this->assertFalse($rows->has('Rare Drug'));
        $this->assertFalse($rows->has('Never Stocked'));
        $this->assertSame(2, $data['never_stocked']);
        $this->assertSame('stock', collect($data['actions'])->last()['key']);

        // Out: 2 a day, 2 days to deliver. Minimum = 2 x (2 + 3) = 10; fill up to the minimum plus 15 days of sales = 40 units = 4 packs.
        $this->assertSame(['out', 0, 60, 10, 40, 4], [$rows['Paracetamol 500']['class'], $rows['Paracetamol 500']['usable'], $rows['Paracetamol 500']['sold_30'], $rows['Paracetamol 500']['minimum'], $rows['Paracetamol 500']['desired'], $rows['Paracetamol 500']['suggest_packs']]);
        // Urgent: 9 left at 3 a day is 3 days, and the delivery takes 5. Minimum 3 x (5 + 3) = 24; fill up to 24 + 45 = 69, less the 9 = 60 units = 6 packs.
        $this->assertSame(['urgent', 9, 3, 5, 6, 'Far Distributors'], [$rows['Metformin 500']['class'], $rows['Metformin 500']['usable'], $rows['Metformin 500']['days_cover'], $rows['Metformin 500']['lead_days'], $rows['Metformin 500']['suggest_packs'], $rows['Metformin 500']['stockist']]);
        // Low by a fixed minimum with no sales: fill to twice the minimum. 100 - 20 = 80 units = 8 packs.
        $this->assertSame(['low', 50, 100, 8], [$rows['Atorvastatin 10']['class'], $rows['Atorvastatin 10']['minimum'], $rows['Atorvastatin 10']['desired'], $rows['Atorvastatin 10']['suggest_packs']]);
        // Stock about to expire is set aside: none usable, fill to the 120 asked for = 12 packs.
        $this->assertSame(['out', 0, 40, 12], [$rows['Amoxicillin 250']['class'], $rows['Amoxicillin 250']['usable'], $rows['Amoxicillin 250']['near_expiry'], $rows['Amoxicillin 250']['suggest_packs']]);

        $this->assertSame('order', $data['actions'][0]['key']);
        $this->assertSame(3, $data['actions'][0]['count']);
        $this->assertSame(1, collect($data['expiring'])->where('medicine', 'Amoxicillin 250')->count());

        // Ticked lines become one draft order for each supplier, and stop being suggested.
        $this->postJson('/api/pharmacy/manager/orders', ['lines' => [['medicine_id' => $low->id, 'packs' => 8]]])->assertStatus(422)->assertJsonValidationErrors('lines.0.stockist_id');
        $made = $this->postJson('/api/pharmacy/manager/orders', ['lines' => [
            ['medicine_id' => $low->id, 'stockist_id' => $supplier->id, 'packs' => 8],
            ['medicine_id' => $out->id, 'stockist_id' => $supplier->id, 'packs' => 4],
            ['medicine_id' => $urgent->id, 'stockist_id' => $slowSupplier->id, 'packs' => 6],
        ]])->assertCreated()->assertJsonCount(2, 'orders')->json('orders');
        $order = PurchaseOrder::with('items')->find(collect($made)->firstWhere('stockist', 'City Pharma')['id']);
        $this->assertSame(['draft', 2, today()->addDays(2)->toDateString()], [$order->status, $order->items->count(), $order->expected_on->toDateString()]);

        // The draft is approved after its quantity is corrected, and only then counts as ordered.
        $lines = $order->items->map(fn ($i) => ['id' => $i->id, 'packs' => $i->medicine_id === $out->id ? 5 : $i->packs])->all();
        $this->putJson("/api/pharmacy/purchase-orders/{$order->id}", ['status' => 'received'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->putJson("/api/pharmacy/purchase-orders/{$order->id}", ['status' => 'open', 'items' => $lines])->assertOk()->assertJsonPath('status', 'open');
        $this->assertSame(5, $order->items()->where('medicine_id', $out->id)->value('packs'));
        $this->assertNotNull($order->fresh()->approved_at);

        $after = $this->getJson('/api/pharmacy/manager')->json();
        $left = collect($after['reorder'])->keyBy('name');
        $this->assertFalse($left->has('Atorvastatin 10'));
        $this->assertSame(0, $left['Paracetamol 500']['suggest_packs']); // still out, but already on order
        $this->assertSame(50, $left['Paracetamol 500']['on_order']);
        $this->assertSame(1, collect($after['unmatched']['orders'])->where('status', 'draft')->count());

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/pharmacy/manager')->assertForbidden();
    }

    public function test_supplier_bills_show_when_they_fall_due_how_old_they_are_and_a_statement(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $supplier = Stockist::create(['name' => 'City Pharma', 'credit_days' => 30]);
        $old = PurchaseInvoice::create(['stockist_id' => $supplier->id, 'invoice_no' => 'INV-1', 'invoice_date' => today()->subDays(45), 'subtotal' => 1000, 'tax' => 0, 'total' => 1000, 'paid' => 0]);
        $new = PurchaseInvoice::create(['stockist_id' => $supplier->id, 'invoice_no' => 'INV-2', 'invoice_date' => today()->subDays(25), 'subtotal' => 500, 'tax' => 0, 'total' => 500, 'paid' => 0]);
        $this->assertSame(today()->subDays(15)->toDateString(), $old->fresh()->due_date->toDateString());

        // Part payment by bank transfer, dated; then too much is refused.
        $this->postJson("/api/pharmacy/purchases/{$old->id}/payments", ['amount' => 400, 'mode' => 'bank_transfer', 'reference' => 'UTR123', 'paid_on' => today()->subDays(3)->toDateString()])->assertOk();
        $this->postJson("/api/pharmacy/purchases/{$old->id}/payments", ['amount' => 700])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson("/api/pharmacy/purchases/{$old->id}/payments", ['amount' => 100, 'paid_on' => today()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('paid_on');
        StockReturn::create(['stockist_id' => $supplier->id, 'returned_on' => today()->subDays(2), 'total' => 150]);

        $data = $this->getJson('/api/pharmacy/manager')->assertOk()->json();
        $bills = collect($data['payables']['invoices'])->keyBy('invoice_no');
        $this->assertEquals([600, 15, 'd1_30'], [$bills['INV-1']['owed'], $bills['INV-1']['days_overdue'], $bills['INV-1']['bucket']]);
        $this->assertSame(['not_due', today()->addDays(5)->toDateString()], [$bills['INV-2']['bucket'], $bills['INV-2']['due_date']]);
        $row = $data['payables']['suppliers'][0];
        $this->assertEquals([1100, 600, 500, 150, 950], [$row['owed'], $row['d1_30'], $row['not_due'], $row['returns_credit'], $row['net']]);
        $this->assertSame(['pay', 'pay'], [$data['actions'][0]['key'], $data['actions'][1]['key']]);
        $this->assertStringContainsString('₹600.00', $data['actions'][0]['text']);

        $statement = $this->getJson("/api/pharmacy/stockists/{$supplier->id}/statement?from=".today()->subDays(30)->toDateString())->assertOk()->json();
        $this->assertEquals(1000, $statement['opening']); // INV-1 came before the period
        $this->assertSame(['invoice', 'payment', 'return'], array_column($statement['lines'], 'kind'));
        $this->assertStringContainsString('bank transfer (UTR123)', $statement['lines'][1]['text']);
        $this->assertEquals([1500, 1100, 950], array_column($statement['lines'], 'balance'));
        $this->assertEquals(950, $statement['closing']);
    }
}
