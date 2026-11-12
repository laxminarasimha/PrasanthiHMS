<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PharmacySale;
use App\Models\StockBatch;
use App\Models\Stockist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyCompleteTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $medicine;

    private Stockist $stockist;

    private StockBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->medicine = Medicine::create(['name' => 'Paracetamol 650mg Tablet', 'manufacturer' => 'Sample Pharma', 'gst_percent' => 12, 'units_per_pack' => 10, 'reorder_level' => 20]);
        $this->stockist = Stockist::create(['name' => 'Sample Distributors']);
        // 10 packs of 10 at 15 a pack (1.50 a unit), MRP 28 a pack (2.80 a unit).
        $invoice = $this->postJson('/api/pharmacy/purchases', [
            'stockist_id' => $this->stockist->id, 'invoice_no' => 'INV-1', 'invoice_date' => now()->toDateString(),
            'items' => [[
                'medicine_id' => $this->medicine->id, 'batch_no' => 'B001', 'expiry' => now()->addYear()->format('Y-m'),
                'units_per_pack' => 10, 'packs' => 10, 'pack_price' => 15, 'pack_mrp' => 28, 'gst_percent' => 12,
            ]],
        ])->assertCreated()->json();
        $this->batch = StockBatch::find($invoice['batches'][0]['id']);
    }

    private function sell(int $quantity, float $paid, array $extra = []): array
    {
        return $this->postJson('/api/pharmacy/sales', $extra + [
            'customer_name' => 'Walk-in', 'paid' => $paid, 'payment_mode' => 'cash',
            'items' => [['stock_batch_id' => $this->batch->id, 'quantity' => $quantity]],
        ])->assertCreated()->json();
    }

    private function stock(): int
    {
        return $this->batch->fresh()->units_in_stock;
    }

    public function test_balance_can_be_collected_later_but_not_more_than_is_due(): void
    {
        $sale = $this->sell(10, 10); // 28 billed, 10 paid

        $this->postJson("/api/pharmacy/sales/{$sale['id']}/payments", ['amount' => 50, 'mode' => 'upi'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson("/api/pharmacy/sales/{$sale['id']}/payments", ['amount' => 18, 'mode' => 'upi'])
            ->assertCreated()->assertJsonPath('paid', 28)->assertJsonPath('due', 0)->assertJsonCount(2, 'payments');
    }

    public function test_returned_medicines_go_back_into_stock_and_overpayment_is_refunded(): void
    {
        $sale = $this->sell(20, 56); // 56 billed and paid
        $itemId = $sale['items'][0]['id'];
        $this->assertSame(80, $this->stock());

        $this->postJson("/api/pharmacy/sales/{$sale['id']}/returns", ['refund_mode' => 'cash', 'items' => [['id' => $itemId, 'quantity' => 5]]])
            ->assertCreated()
            ->assertJsonPath('returned', 14)
            ->assertJsonPath('paid', 42)
            ->assertJsonPath('due', 0)
            ->assertJsonPath('items.0.returned_quantity', 5)
            ->assertJsonPath('returns.0.refunded', 14);
        $this->assertSame(85, $this->stock());

        // Only what is left on the bill can come back.
        $this->postJson("/api/pharmacy/sales/{$sale['id']}/returns", ['refund_mode' => 'cash', 'items' => [['id' => $itemId, 'quantity' => 16]]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
    }

    public function test_return_on_an_unpaid_bill_reduces_what_is_owed_without_a_refund(): void
    {
        $sale = $this->sell(10, 0);

        $this->postJson("/api/pharmacy/sales/{$sale['id']}/returns", ['refund_mode' => 'cash', 'items' => [['id' => $sale['items'][0]['id'], 'quantity' => 5]]])
            ->assertCreated()->assertJsonPath('returned', 14)->assertJsonPath('paid', 0)->assertJsonPath('due', 14)->assertJsonPath('returns.0.refunded', 0);
    }

    public function test_deleting_a_bill_restocks_everything_refunds_the_money_and_keeps_a_record(): void
    {
        $sale = $this->sell(10, 28);

        $this->deleteJson("/api/pharmacy/sales/{$sale['id']}", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->deleteJson("/api/pharmacy/sales/{$sale['id']}", ['reason' => 'Billed to the wrong customer'])
            ->assertOk()->assertJsonPath('paid', 0)->assertJsonPath('due', 0)->assertJsonPath('void_reason', 'Billed to the wrong customer');

        $this->assertSame(100, $this->stock());
        $this->assertNotNull(PharmacySale::find($sale['id'])->voided_at);
        $this->deleteJson("/api/pharmacy/sales/{$sale['id']}", ['reason' => 'Again'])->assertStatus(422);
        $this->postJson("/api/pharmacy/sales/{$sale['id']}/payments", ['amount' => 1, 'mode' => 'cash'])->assertStatus(422);

        $this->getJson('/api/pharmacy/reports/deleted-bills')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.reason', 'Billed to the wrong customer');
        $this->getJson('/api/pharmacy/dashboard')->assertOk()->assertJsonPath('sales', 0)->assertJsonPath('billed', 0)->assertJsonPath('collected', 0);
    }

    public function test_dashboard_shows_sales_money_by_mode_inventory_and_fast_movers(): void
    {
        $this->sell(10, 28);
        $this->sell(20, 20, ['payment_mode' => 'card']);

        $this->getJson('/api/pharmacy/dashboard')->assertOk()
            ->assertJsonPath('sales', 2)->assertJsonPath('billed', 84)->assertJsonPath('collected', 48)
            ->assertJsonPath('collected_cash', 28)->assertJsonPath('collected_card', 20)->assertJsonPath('collected_other', 0)
            ->assertJsonPath('statistics.medicines', 1)->assertJsonPath('statistics.manufacturers', 0)
            ->assertJsonPath('statistics.units_in_stock', 70)->assertJsonPath('statistics.inventory_cost', 105)->assertJsonPath('statistics.inventory_mrp', 196)
            ->assertJsonPath('statistics.out_of_stock', 0)
            ->assertJsonCount(2, 'latest_sales')
            ->assertJsonPath('fast_moving.0.medicine_name', 'Paracetamol 650mg Tablet')->assertJsonPath('fast_moving.0.sold', 30);
    }

    public function test_opening_stock_inventory_tabs_and_stock_adjustment_with_its_report(): void
    {
        $other = Medicine::create(['name' => 'Cetirizine 10mg Tablet', 'gst_percent' => 12, 'units_per_pack' => 10, 'reorder_level' => 50]);
        $empty = Medicine::create(['name' => 'Not Stocked Syrup', 'gst_percent' => 12, 'units_per_pack' => 1]);

        $this->postJson('/api/pharmacy/opening-stock', ['items' => [[
            'medicine_id' => $other->id, 'batch_no' => 'OPEN1', 'expiry' => now()->addMonths(2)->format('Y-m'),
            'units_per_pack' => 10, 'quantity' => 30, 'mrp' => 18, 'cost_price' => 9, 'gst_percent' => 12,
        ]]])->assertCreated()->assertJsonPath('added', 1);

        $inventory = $this->getJson('/api/pharmacy/inventory')->assertOk()
            ->assertJsonPath('counts.expiring', 1)->assertJsonPath('counts.low', 1)->assertJsonPath('counts.zero', 1)->json('all');
        $this->assertSame(30, collect($inventory)->firstWhere('id', $other->id)['units_in_stock']);
        $this->assertSame(0, collect($inventory)->firstWhere('id', $empty->id)['units_in_stock']);

        $this->postJson("/api/pharmacy/stock/{$this->batch->id}/adjust", ['units_in_stock' => 96])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/pharmacy/stock/{$this->batch->id}/adjust", ['units_in_stock' => 96, 'reason' => '4 tablets damaged'])
            ->assertOk()->assertJsonPath('units_in_stock', 96);
        $this->getJson('/api/pharmacy/reports/stock-adjustments')->assertOk()
            ->assertJsonPath('rows.0.before', 100)->assertJsonPath('rows.0.after', 96)->assertJsonPath('rows.0.difference', -4)->assertJsonPath('totals.difference', -4);
    }

    public function test_manufacturers_are_remembered_from_medicines_and_renaming_updates_them(): void
    {
        $this->postJson('/api/pharmacy/medicines', ['name' => 'Azithromycin 500', 'manufacturer' => 'New Labs', 'gst_percent' => 12, 'units_per_pack' => 3, 'is_scheduled' => true])
            ->assertCreated()->assertJsonPath('is_scheduled', true);
        $id = $this->getJson('/api/pharmacy/manufacturers')->assertOk()->assertJsonCount(1)->json('0.id');

        $this->putJson("/api/pharmacy/manufacturers/{$id}", ['name' => 'New Laboratories Ltd'])->assertOk();
        $this->assertSame('New Laboratories Ltd', Medicine::where('name', 'Azithromycin 500')->value('manufacturer'));
        $this->postJson('/api/pharmacy/manufacturers', ['name' => 'New Laboratories Ltd'])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_stockist_can_be_paid_and_stock_returned_to_them(): void
    {
        $invoiceId = $this->batch->purchase_invoice_id; // 150 + 12% GST = 168

        $this->postJson("/api/pharmacy/purchases/{$invoiceId}/payments", ['amount' => 500])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson("/api/pharmacy/purchases/{$invoiceId}/payments", ['amount' => 100])->assertOk()->assertJsonPath('paid', 100);

        $this->postJson('/api/pharmacy/stock-returns', ['stockist_id' => $this->stockist->id, 'returned_on' => now()->toDateString(), 'items' => [['stock_batch_id' => $this->batch->id, 'quantity' => 500]]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');

        // 20 units at 1.50 plus 12% GST = 33.60 credited.
        $this->postJson('/api/pharmacy/stock-returns', [
            'stockist_id' => $this->stockist->id, 'returned_on' => now()->toDateString(), 'remarks' => 'Near expiry',
            'items' => [['stock_batch_id' => $this->batch->id, 'quantity' => 20]],
        ])->assertCreated()->assertJsonPath('total', 33.6)->assertJsonPath('items.0.quantity', 20);
        $this->assertSame(80, $this->stock());
        $this->getJson('/api/pharmacy/stock-returns')->assertOk()->assertJsonCount(1);
    }

    public function test_purchase_orders_can_be_raised_listed_and_marked_received(): void
    {
        $order = $this->postJson('/api/pharmacy/purchase-orders', [
            'stockist_id' => $this->stockist->id, 'order_date' => now()->toDateString(),
            'items' => [['medicine_id' => $this->medicine->id, 'units_per_pack' => 10, 'packs' => 25]],
        ])->assertCreated()->assertJsonPath('status', 'open')->assertJsonPath('items.0.medicine_name', 'Paracetamol 650mg Tablet')->json();

        $this->assertMatchesRegularExpression('/^PO\d{5}$/', $order['order_no']);
        $this->getJson('/api/pharmacy/purchase-orders')->assertOk()->assertJsonCount(1)->assertJsonPath('0.items_count', 1)->assertJsonPath('0.status', 'open');
        $this->putJson("/api/pharmacy/purchase-orders/{$order['id']}", ['status' => 'received'])->assertOk()->assertJsonPath('status', 'received');
        $this->postJson('/api/pharmacy/purchase-orders', ['stockist_id' => $this->stockist->id, 'order_date' => now()->toDateString(), 'items' => []])->assertStatus(422);
    }

    public function test_prescription_queue_shows_todays_prescriptions_and_whether_they_were_billed(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::factory()->create();
        $appointment = Appointment::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now(), 'status' => 'arrived']);
        Sanctum::actingAs($doctor);
        $this->putJson("/api/appointments/{$appointment->id}/consultation", ['complete' => true, 'medicines' => [['medicine' => 'Paracetamol 650mg Tablet', 'frequency' => '1-1-1']]])->assertOk();

        $this->getJson('/api/pharmacy/orders')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.patient.id', $patient->id)->assertJsonPath('0.medicines.0.medicine', 'Paracetamol 650mg Tablet')->assertJsonPath('0.bill', null);

        $sale = $this->sell(3, 8, ['patient_id' => $patient->id, 'customer_name' => $patient->name]);
        $this->getJson('/api/pharmacy/orders')->assertOk()->assertJsonPath('0.bill.bill_no', $sale['bill_no']);
    }

    public function test_reports_cover_billing_tax_items_margin_returns_schedule_and_movement(): void
    {
        $this->medicine->update(['is_scheduled' => true]);
        $idle = Medicine::create(['name' => 'Slow Mover Capsule', 'gst_percent' => 5, 'units_per_pack' => 10]);
        StockBatch::create([
            'medicine_id' => $idle->id, 'batch_no' => 'S1', 'expiry' => now()->addYear(), 'units_per_pack' => 10, 'packs' => 1, 'pack_price' => 50,
            'pack_mrp' => 100, 'unit_mrp' => 10, 'gst_percent' => 5, 'units_received' => 10, 'units_in_stock' => 10,
        ]);
        $sale = $this->sell(20, 56, ['referred_doctor' => 'Dr Sample']);
        $this->postJson("/api/pharmacy/sales/{$sale['id']}/returns", ['refund_mode' => 'cash', 'items' => [['id' => $sale['items'][0]['id'], 'quantity' => 10]]])->assertCreated();
        $report = fn (string $type) => $this->getJson("/api/pharmacy/reports/{$type}")->assertOk();

        $report('billing')->assertJsonCount(1, 'rows')->assertJsonPath('totals.total', 56)->assertJsonPath('totals.returned', 28)->assertJsonPath('totals.paid', 28);
        $report('monthly-billing')->assertJsonPath('rows.0.bills', 1)->assertJsonPath('rows.0.total', 56);
        $report('item-wise')->assertJsonPath('rows.0.sold', 20)->assertJsonPath('rows.0.returned', 10)->assertJsonPath('rows.0.net', 10);
        // 10 units kept at 2.80 = 28.00 including 12% GST: 25.00 taxable + 3.00 tax.
        $report('sales-tax')->assertJsonPath('rows.0.rate', 12)->assertJsonPath('rows.0.taxable', 25)->assertJsonPath('rows.0.tax', 3)->assertJsonPath('rows.0.cgst', 1.5);
        $report('purchase-tax')->assertJsonPath('rows.0.taxable', 150)->assertJsonPath('rows.0.tax', 18)->assertJsonPath('rows.0.balance', 168);
        $report('returns')->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.quantity', 10)->assertJsonPath('rows.0.amount', 28);
        // Sold for 25.00 ex GST, cost 10 x 1.50 = 15.00, so 10.00 margin, 40%.
        $report('product-margin')->assertJsonPath('rows.0.sale', 25)->assertJsonPath('rows.0.cost', 15)->assertJsonPath('rows.0.margin', 10)->assertJsonPath('rows.0.margin_percent', 40);
        $report('date-margin')->assertJsonPath('rows.0.date', now()->toDateString())->assertJsonPath('rows.0.margin', 10);
        $report('schedule-drugs')->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.doctor', 'Dr Sample')->assertJsonPath('rows.0.quantity', 10);
        $moving = $report('moving')->json('rows');
        $this->assertSame(['Moving', 'Not moving'], array_column($moving, 'status'));

        $this->getJson('/api/pharmacy/reports/no-such-report')->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/pharmacy/reports/billing')->assertForbidden();
    }
}
