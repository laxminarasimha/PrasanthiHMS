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

    public function test_a_scheme_with_free_packs_shows_the_real_cost_and_margin(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\User::factory()->create(['role' => 'pharmacist']));

        // "10 + 2": ten strips paid at ₹50, two free, MRP ₹72 a strip of 10 tablets, 12% GST.
        $invoice = $this->receive([$this->line(['packs' => 10, 'free_packs' => 2, 'pack_price' => 50, 'pack_mrp' => 72, 'gst_percent' => 12, 'hsn_code' => '30049099', 'rack_no' => 'R3', 'box_no' => 'B2'])]);
        $batch = $invoice['batches'][0];
        $this->assertEquals([500, 60, 560], [$invoice['subtotal'], $invoice['tax'], $invoice['total']]); // only the ten paid strips are charged
        $this->assertSame(120, $batch['units_received']);                                                  // all twelve go into stock
        $this->assertEquals(4.17, $batch['unit_cost']);                                                    // ₹500 over 120 tablets, not over 100
        $this->assertEquals(7.2, $batch['unit_mrp']);
        $this->assertEquals(42.1, $batch['margin_percent']);
        $this->assertSame(['30049099', 'B2'], [$batch['hsn_code'], $batch['box_no']]);

        // Selling it measures profit against the real cost.
        $sale = $this->sell([['stock_batch_id' => $batch['id'], 'quantity' => 10]])->assertCreated()->json();
        $this->assertEquals(4.17, \App\Models\PharmacySaleItem::where('pharmacy_sale_id', $sale['id'])->value('unit_cost'));
    }

    public function test_rupee_discounts_tax_on_free_packs_and_bill_level_figures_reach_the_total(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\User::factory()->create(['role' => 'pharmacist']));
        $line = $this->line(['packs' => 10, 'free_packs' => 2, 'pack_price' => 100, 'pack_mrp' => 150, 'discount_percent' => 10, 'discount_amount' => 50, 'gst_percent' => 12, 'tax_on_free' => true]);

        // 10 x 100 less 10% = 900, less ₹50 = 850. GST on 850 plus the two free strips (200) = 126.
        // Then freight 40, cash discount 16, rounded up by 0.00: 850 + 126 + 40 - 16 = 1000.
        $stockist = $this->postJson('/api/pharmacy/stockists', ['name' => 'Scheme Agency'])->json('id');
        $body = ['stockist_id' => $stockist, 'invoice_no' => 'S-1', 'invoice_date' => today()->toDateString(), 'items' => [$line], 'other_charges' => 40, 'cash_discount' => 16, 'round_off' => 0];
        $invoice = $this->postJson('/api/pharmacy/purchases', $body)->assertCreated()->json();
        $this->assertEquals([850, 126, 1000], [$invoice['subtotal'], $invoice['tax'], $invoice['total']]);
        $this->assertEquals(7.08, $invoice['batches'][0]['unit_cost']); // 850 over 120 units

        $this->postJson('/api/pharmacy/purchases', ['invoice_no' => 'S-2', 'items' => [['discount_amount' => 5000] + $line]] + $body)->assertStatus(422)->assertJsonValidationErrors('items.0.discount_amount');
        $this->postJson('/api/pharmacy/purchases', ['invoice_no' => 'S-3', 'cash_discount' => 99999] + $body)->assertStatus(422)->assertJsonValidationErrors('cash_discount');
        $this->postJson('/api/pharmacy/purchases', ['invoice_no' => 'S-4', 'round_off' => 50] + $body)->assertStatus(422)->assertJsonValidationErrors('round_off');

        // Correcting the invoice keeps the same arithmetic, and the change is written down.
        $b = $invoice['batches'][0];
        $edited = $this->putJson("/api/pharmacy/purchases/{$invoice['id']}", [
            'stockist_id' => $stockist, 'invoice_no' => 'S-1', 'invoice_date' => today()->toDateString(), 'reason' => 'Freight was typed wrongly', 'other_charges' => 60, 'cash_discount' => 16, 'round_off' => -0.5,
            'items' => [['id' => $b['id'], 'batch_no' => $b['batch_no'], 'expiry' => substr($b['expiry'], 0, 7), 'packs' => 10, 'free_packs' => 2, 'pack_price' => 100, 'pack_mrp' => 150, 'discount_percent' => 10, 'discount_amount' => 50, 'gst_percent' => 12, 'tax_on_free' => false]],
        ])->assertOk()->json();
        // Without GST on the free strips: 850 + 102 + 60 - 16 - 0.5 = 995.5
        $this->assertEquals([850, 102, 995.5], [$edited['subtotal'], $edited['tax'], $edited['total']]);
        $summary = \App\Models\PharmacyChange::where('kind', 'invoice')->latest('id')->value('summary');
        $this->assertStringContainsString('Other charges', $summary);
        $this->assertStringContainsString('GST on free packs', $summary);
    }
}
