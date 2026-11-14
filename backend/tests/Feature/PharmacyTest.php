<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\StockBatch;
use App\Models\Stockist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $medicine;

    private Stockist $stockist;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->medicine = Medicine::create(['name' => 'Paracetamol 650 Tablet', 'gst_percent' => 12, 'units_per_pack' => 10, 'reorder_level' => 50]);
        $this->stockist = Stockist::create(['name' => 'Sample Distributors']);
    }

    private function line(array $overrides = []): array
    {
        return $overrides + [
            'medicine_id' => $this->medicine->id, 'batch_no' => 'B001', 'expiry' => now()->addYear()->format('Y-m'),
            'units_per_pack' => 10, 'packs' => 20, 'free_packs' => 2, 'pack_price' => 15, 'pack_mrp' => 28, 'gst_percent' => 12,
        ];
    }

    private function receive(array $lines, string $invoiceNo = 'INV-1'): array
    {
        return $this->postJson('/api/pharmacy/purchases', [
            'stockist_id' => $this->stockist->id, 'invoice_no' => $invoiceNo, 'invoice_date' => now()->toDateString(), 'items' => $lines,
        ])->assertCreated()->json();
    }

    private function sell(array $items, array $overrides = [])
    {
        return $this->postJson('/api/pharmacy/sales', $overrides + [
            'customer_name' => 'Walk-in Customer', 'paid' => 0, 'payment_mode' => 'cash', 'items' => $items,
        ]);
    }

    public function test_medicines_and_stockists_can_be_added_and_duplicates_are_refused(): void
    {
        $this->postJson('/api/pharmacy/medicines', ['name' => 'Cetirizine 10 Tablet', 'gst_percent' => 12, 'units_per_pack' => 10])->assertCreated();
        $this->postJson('/api/pharmacy/medicines', ['name' => 'Cetirizine 10 Tablet', 'gst_percent' => 12, 'units_per_pack' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/pharmacy/stockists', ['name' => 'Another Agency', 'phone' => '9000000002'])->assertCreated();

        $this->getJson('/api/pharmacy/medicines')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/pharmacy/stockists')->assertOk()->assertJsonCount(2);
    }

    public function test_purchase_invoice_puts_batches_into_stock_with_free_packs_and_totals(): void
    {
        $invoice = $this->receive([$this->line()]);

        // 20 paid packs x 15 = 300, plus 12% GST = 336. Stock is (20 + 2 free) x 10 units.
        $this->assertEquals(300, $invoice['subtotal']);
        $this->assertEquals(36, $invoice['tax']);
        $this->assertEquals(336, $invoice['total']);
        $this->assertSame(220, $invoice['batches'][0]['units_in_stock']);
        $this->assertEquals(2.8, $invoice['batches'][0]['unit_mrp']);
        $this->assertSame(now()->addYear()->endOfMonth()->toDateString(), $invoice['batches'][0]['expiry']);

        $this->getJson('/api/pharmacy/stock')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/pharmacy/medicines')->assertJsonPath('0.units_in_stock', 220);
    }

    public function test_duplicate_invoice_numbers_and_expired_lines_are_refused(): void
    {
        $this->receive([$this->line()]);

        $this->postJson('/api/pharmacy/purchases', [
            'stockist_id' => $this->stockist->id, 'invoice_no' => 'INV-1', 'invoice_date' => now()->toDateString(), 'items' => [$this->line()],
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_no');

        $this->postJson('/api/pharmacy/purchases', [
            'stockist_id' => $this->stockist->id, 'invoice_no' => 'INV-2', 'invoice_date' => now()->toDateString(),
            'items' => [$this->line(['expiry' => now()->subMonths(2)->format('Y-m')])],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.expiry');
    }

    public function test_sale_reduces_stock_and_totals_include_discounts_gst_and_rounding(): void
    {
        $batchId = $this->receive([$this->line()])['batches'][0]['id'];

        // 15 units x 2.80 = 42.00, less 10% line discount = 37.80, less 0.50 bill discount = 37.30, rounded to 37.
        $sale = $this->sell([['stock_batch_id' => $batchId, 'quantity' => 15, 'discount_percent' => 10]], ['discount' => 0.5, 'paid' => 37])
            ->assertCreated()->json();

        $this->assertMatchesRegularExpression('/^P\d{5}$/', $sale['bill_no']);
        $this->assertEquals(37.8, $sale['subtotal']);
        $this->assertEquals(-0.3, $sale['round_off']);
        $this->assertEquals(37, $sale['total']);
        $this->assertEquals(0, $sale['due']);
        $this->assertEquals(4, $sale['tax']); // 12% GST contained in 37.30
        $this->assertSame('Paracetamol 650 Tablet', $sale['items'][0]['medicine_name']);
        $this->assertSame(205, StockBatch::find($batchId)->units_in_stock);

        $this->getJson('/api/pharmacy/sales')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/pharmacy/sales?q='.$sale['bill_no'])->assertOk()->assertJsonCount(1);
    }

    public function test_cannot_sell_more_than_stock_or_an_expired_batch_and_nothing_is_deducted(): void
    {
        $batchId = $this->receive([$this->line(['packs' => 1, 'free_packs' => 0])])['batches'][0]['id'];

        $this->sell([['stock_batch_id' => $batchId, 'quantity' => 11]])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $this->assertSame(10, StockBatch::find($batchId)->units_in_stock);

        StockBatch::whereKey($batchId)->update(['expiry' => now()->subDay()->toDateString()]);
        $this->sell([['stock_batch_id' => $batchId, 'quantity' => 1]])->assertStatus(422)->assertJsonValidationErrors('items.0.stock_batch_id');
        $this->sell([['stock_batch_id' => $batchId, 'quantity' => 1]], ['paid' => 500])->assertStatus(422);
    }

    public function test_sell_search_offers_only_sellable_batches_soonest_expiry_first(): void
    {
        $later = $this->receive([$this->line(['batch_no' => 'LATER', 'expiry' => now()->addYears(2)->format('Y-m')])], 'INV-A')['batches'][0];
        $sooner = $this->receive([$this->line(['batch_no' => 'SOONER', 'expiry' => now()->addMonths(3)->format('Y-m')])], 'INV-B')['batches'][0];
        $empty = $this->receive([$this->line(['batch_no' => 'EMPTY'])], 'INV-C')['batches'][0];
        StockBatch::whereKey($empty['id'])->update(['units_in_stock' => 0]);

        $found = $this->getJson('/api/pharmacy/sell-search?q=parac')->assertOk()->assertJsonCount(1)->json();

        $this->assertSame([$sooner['id'], $later['id']], array_column($found[0]['sellable_batches'], 'id'));
        $this->getJson('/api/pharmacy/sell-search?q=zzz')->assertOk()->assertJsonCount(0);
    }

    public function test_only_pharmacy_roles_can_receive_stock_or_sell(): void
    {
        $batchId = $this->receive([$this->line()])['batches'][0]['id'];
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));

        $this->sell([['stock_batch_id' => $batchId, 'quantity' => 1]])->assertForbidden();
        $this->postJson('/api/pharmacy/medicines', ['name' => 'X', 'gst_percent' => 5, 'units_per_pack' => 1])->assertForbidden();
        $this->getJson('/api/pharmacy/stock')->assertOk();
    }

    public function test_medicines_carry_inventory_classes_and_stockists_show_what_is_owed(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\User::factory()->create(['role' => 'pharmacist']));

        $base = ['gst_percent' => 12, 'units_per_pack' => 10];
        $this->postJson('/api/pharmacy/medicines', $base + ['name' => 'Adrenaline 1mg Injection', 'category' => 'Emergency Medicine', 'sub_category' => 'Cardiac', 'ved' => 'V', 'sde' => 'S', 'fsn' => 'S'])
            ->assertCreated()->assertJsonPath('ved', 'V')->assertJsonPath('sub_category', 'Cardiac');
        $this->postJson('/api/pharmacy/medicines', $base + ['name' => 'Vitamin C 500mg Tablet', 'category' => 'General', 'ved' => 'D', 'fsn' => 'F'])->assertCreated();
        $this->postJson('/api/pharmacy/medicines', $base + ['name' => 'Wrong Class', 'ved' => 'X'])->assertStatus(422)->assertJsonValidationErrors('ved');

        $this->assertSame(['Adrenaline 1mg Injection'], array_column($this->getJson('/api/pharmacy/medicines?all=1&ved=V')->json(), 'name'));
        $this->assertSame(['Vitamin C 500mg Tablet'], array_column($this->getJson('/api/pharmacy/medicines?all=1&fsn=F')->json(), 'name'));
        $this->assertSame(['Adrenaline 1mg Injection'], array_column($this->getJson('/api/pharmacy/medicines?all=1&category=Emergency%20Medicine')->json(), 'name'));

        $stockist = $this->postJson('/api/pharmacy/stockists', ['name' => 'Sample Agency', 'email' => 'not-an-email'])->assertStatus(422);
        $stockist = $this->postJson('/api/pharmacy/stockists', ['name' => 'Sample Agency', 'email' => 'orders@agency.example'])->assertCreated()->json();
        \App\Models\PurchaseInvoice::create(['stockist_id' => $stockist['id'], 'invoice_no' => 'A1', 'invoice_date' => today(), 'total' => 5000, 'paid' => 2000]);
        \App\Models\PurchaseInvoice::create(['stockist_id' => $stockist['id'], 'invoice_no' => 'A2', 'invoice_date' => today(), 'total' => 1500, 'paid' => 0]);
        $return = new \App\Models\StockReturn(['stockist_id' => $stockist['id'], 'returned_on' => today()]);
        $return->forceFill(['total' => 500])->save();

        $row = collect($this->getJson('/api/pharmacy/stockists')->assertOk()->json())->firstWhere('id', $stockist['id']);
        $this->assertEquals([2, 6500, 2000, 500, 4000], [$row['invoices'], $row['billed'], $row['paid'], $row['returned'], $row['balance']]);
        $this->assertSame('orders@agency.example', $row['email']);
    }
}
