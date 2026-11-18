<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockCount;
use App\Models\Stockist;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $pharmacist;

    private User $owner;

    private Stockist $stockist;

    private StockBatch $para;

    private StockBatch $panto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pharmacist = User::factory()->create(['role' => 'pharmacist']);
        $this->owner = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($this->pharmacist);
        $this->stockist = Stockist::create(['name' => 'Sample Distributors']);
        // Paracetamol: 100 units at 1.50 each. Pantoprazole: 50 units at 4.00 each, kept on rack B.
        $this->para = $this->receive('Paracetamol 650mg Tablet', 'P001', 10, 15, 28, 'A');
        $this->panto = $this->receive('Pantoprazole 40mg Tablet', 'N001', 5, 40, 70, 'B');
    }

    private function receive(string $name, string $batchNo, int $packs, float $price, float $mrp, string $rack): StockBatch
    {
        $medicine = Medicine::create(['name' => $name, 'gst_percent' => 12, 'units_per_pack' => 10, 'reorder_level' => 10]);
        $invoice = $this->postJson('/api/pharmacy/purchases', [
            'stockist_id' => $this->stockist->id, 'invoice_no' => "INV-{$batchNo}", 'invoice_date' => now()->toDateString(),
            'items' => [['medicine_id' => $medicine->id, 'batch_no' => $batchNo, 'expiry' => now()->addYear()->format('Y-m'), 'units_per_pack' => 10, 'packs' => $packs, 'pack_price' => $price, 'pack_mrp' => $mrp, 'gst_percent' => 12, 'rack_no' => $rack]],
        ])->assertCreated()->json();

        return StockBatch::find($invoice['batches'][0]['id']);
    }

    private function sell(StockBatch $batch, int $quantity): array
    {
        return $this->postJson('/api/pharmacy/sales', ['customer_name' => 'Walk-in', 'paid' => 0, 'payment_mode' => 'cash', 'items' => [['stock_batch_id' => $batch->id, 'quantity' => $quantity]]])->assertCreated()->json();
    }

    /** @return array<int, array<string, mixed>> */
    private function ledger(StockBatch $batch): array
    {
        return StockMovement::where('stock_batch_id', $batch->id)->orderBy('id')->get(['kind', 'units', 'balance_after', 'reference'])->toArray();
    }

    public function test_every_change_to_stock_is_written_in_the_ledger_and_adds_up(): void
    {
        $sale = $this->sell($this->para, 12);
        $this->postJson("/api/pharmacy/sales/{$sale['id']}/returns", ['refund_mode' => 'cash', 'items' => [['id' => $sale['items'][0]['id'], 'quantity' => 2]]])->assertCreated();
        $second = $this->sell($this->para, 5);
        $this->deleteJson("/api/pharmacy/sales/{$second['id']}", ['reason' => 'Billed twice'])->assertSuccessful();
        $this->postJson('/api/pharmacy/stock-returns', ['stockist_id' => $this->stockist->id, 'returned_on' => now()->toDateString(), 'items' => [['stock_batch_id' => $this->para->id, 'quantity' => 20]]])->assertCreated();

        $this->assertSame([
            ['kind' => 'purchase', 'units' => 100, 'balance_after' => 100, 'reference' => 'INV-P001'],
            ['kind' => 'sale', 'units' => -12, 'balance_after' => 88, 'reference' => $sale['bill_no']],
            ['kind' => 'sale_return', 'units' => 2, 'balance_after' => 90, 'reference' => $sale['bill_no']],
            ['kind' => 'sale', 'units' => -5, 'balance_after' => 85, 'reference' => $second['bill_no']],
            ['kind' => 'sale_void', 'units' => 5, 'balance_after' => 90, 'reference' => $second['bill_no']],
            ['kind' => 'stockist_return', 'units' => -20, 'balance_after' => 70, 'reference' => 'Return 1 to stockist'],
        ], $this->ledger($this->para));

        $history = $this->getJson("/api/pharmacy/audit/history?medicine_id={$this->para->medicine_id}")->assertOk()
            ->assertJsonPath('batches.0.units_in_stock', 70)->assertJsonPath('batches.0.adds_up', true)->assertJsonCount(6, 'batches.0.movements')
            ->assertJsonPath('batches.0.movements.1.label', 'Sold')->json();
        $this->assertSame($this->pharmacist->name, $history['batches'][0]['movements'][1]['by']);

        // Stock changed behind the screens shows up at once: the record no longer equals what the ledger adds up to.
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/pharmacy/audit/overview')->assertOk()->assertJsonCount(0, 'adrift')->assertJsonPath('stock.batches', 2);
        DB::table('stock_batches')->where('id', $this->para->id)->update(['units_in_stock' => 60]);
        $this->getJson('/api/pharmacy/audit/overview')->assertOk()->assertJsonCount(1, 'adrift')
            ->assertJsonPath('adrift.0.on_record', 60)->assertJsonPath('adrift.0.ledger', 70);
    }

    public function test_a_blind_count_is_checked_and_approved_and_the_shortage_is_reported(): void
    {
        $count = $this->postJson('/api/pharmacy/audit/counts', ['scope' => 'all'])->assertCreated()
            ->assertJsonPath('title', 'Whole pharmacy')->assertJsonPath('status', 'counting')->assertJsonPath('lines', 2)->assertJsonPath('sees_records', false)->json();
        $this->assertStringStartsWith('SC', $count['count_no']);
        // The person counting is not shown what the records say.
        $this->assertArrayNotHasKey('on_record', $count['rows'][0]);
        $this->assertArrayNotHasKey('short_value', $count);
        $line = fn (StockBatch $b) => collect($count['rows'])->firstWhere('stock_batch_id', $b->id)['id'];
        $url = "/api/pharmacy/audit/counts/{$count['id']}";

        // The same batch cannot be put on a second count while this one is open.
        $this->postJson('/api/pharmacy/audit/counts', ['scope' => 'rack', 'rack' => 'A'])->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->postJson("{$url}/submit")->assertStatus(422); // nothing counted yet

        // Paracetamol is 3 short on the shelf; pantoprazole is as recorded.
        $this->putJson("{$url}/lines/{$line($this->para)}", ['counted_units' => 97])->assertOk()->assertJsonPath('counted_units', 97)->assertJsonMissingPath('difference');
        $this->putJson("{$url}/lines/{$line($this->panto)}", ['counted_units' => 50])->assertOk();
        // Ten more are sold after the shelf was counted and before the count is approved.
        $this->sell($this->para, 10);

        $this->postJson("{$url}/approve")->assertForbidden(); // a pharmacist cannot approve
        $this->postJson("{$url}/submit")->assertOk()->assertJsonPath('status', 'submitted');
        $this->putJson("{$url}/lines/{$line($this->para)}", ['counted_units' => 100])->assertStatus(422); // handed in: no more changes
        $this->postJson("{$url}/approve")->assertForbidden();
        $this->getJson('/api/pharmacy/audit/missing')->assertForbidden();
        $this->getJson('/api/pharmacy/audit/overview')->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/pharmacy/audit/overview')->assertOk()->assertJsonPath('waiting.0.count_no', $count['count_no'])->assertJsonPath('waiting.0.short_units', 3);
        $seen = $this->getJson($url)->assertOk()->assertJsonPath('sees_records', true)->assertJsonPath('short_lines', 1)->assertJsonPath('short_units', 3)->assertJsonPath('short_value', 4.5)->json();
        $paraRow = collect($seen['rows'])->firstWhere('stock_batch_id', $this->para->id);
        $this->assertSame(100, $paraRow['on_record']); // what the records said when it was counted
        $this->assertSame(-3, $paraRow['difference']);

        // A difference needs a reason before it can be approved.
        $this->postJson("{$url}/approve")->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->postJson("{$url}/approve", ['lines' => [['id' => $paraRow['id'], 'reason' => 'missing', 'note' => 'Not on the shelf or in the drawer']]])
            ->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('approved_by', $this->owner->name);

        // The difference is taken off, not the counted number put in: 100 − 10 sold − 3 missing.
        $this->assertSame(87, $this->para->fresh()->units_in_stock);
        $this->assertSame(50, $this->panto->fresh()->units_in_stock);
        $this->assertSame(['kind' => 'count', 'units' => -3, 'balance_after' => 87, 'reference' => $count['count_no']], collect($this->ledger($this->para))->last());
        $this->assertDatabaseHas('stock_adjustments', ['stock_batch_id' => $this->para->id, 'before' => 90, 'after' => 87, 'category' => 'missing', 'stock_count_id' => $count['id']]);
        $this->postJson("{$url}/approve")->assertStatus(422); // only once

        $this->getJson('/api/pharmacy/audit/missing')->assertOk()
            ->assertJsonPath('loss_value', 4.5)->assertJsonPath('unexplained_value', 4.5)
            ->assertJsonPath('by_medicine.0.medicine', 'Paracetamol 650mg Tablet')->assertJsonPath('by_medicine.0.units', 3)
            ->assertJsonPath('rows.0.count_no', $count['count_no'])->assertJsonPath('rows.0.category_label', 'Missing, not explained');
        $this->getJson('/api/pharmacy/audit/overview')->assertOk()->assertJsonPath('this_month.unexplained_value', 4.5)->assertJsonCount(0, 'waiting')->assertJsonPath('coverage.counted_batches', 2);
        // The existing stock adjustments report shows it too.
        $this->getJson('/api/pharmacy/reports/stock-adjustments')->assertOk()->assertJsonPath('rows.0.before', 90)->assertJsonPath('rows.0.after', 87);
    }

    public function test_a_count_can_be_sent_back_for_a_recount_or_cancelled_and_found_stock_is_added(): void
    {
        $count = $this->postJson('/api/pharmacy/audit/counts', ['scope' => 'rack', 'rack' => 'B', 'blind' => false])->assertCreated()
            ->assertJsonPath('title', 'Rack B')->assertJsonPath('lines', 1)->assertJsonPath('rows.0.on_record', 50)->json(); // an open count shows the records
        $url = "/api/pharmacy/audit/counts/{$count['id']}";
        $line = $count['rows'][0]['id'];

        // A batch found on the shelf that this count did not list is added to it.
        $found = $this->getJson('/api/pharmacy/audit/batches?q=parac')->assertOk()->assertJsonPath('0.batch_no', 'P001')->json('0.id');
        $rows = $this->postJson("{$url}/lines", ['stock_batch_id' => $found])->assertCreated()->assertJsonPath('lines', 2)->json('rows');
        $this->postJson("{$url}/lines", ['stock_batch_id' => $found])->assertStatus(422);
        $paraLine = collect($rows)->firstWhere('stock_batch_id', $this->para->id)['id'];

        $this->putJson("{$url}/lines/{$line}", ['counted_units' => 40])->assertOk()->assertJsonPath('difference', -10);
        $this->putJson("{$url}/lines/{$paraLine}", ['counted_units' => 104])->assertOk()->assertJsonPath('difference', 4);
        $this->postJson("{$url}/submit")->assertOk();
        $this->postJson("{$url}/reopen")->assertForbidden();

        Sanctum::actingAs($this->owner);
        // Ten short looks like a miscount: it goes back to the shelf, and that line is cleared.
        $this->postJson("{$url}/reopen", ['lines' => [['id' => $line, 'recount' => true]]])->assertOk()->assertJsonPath('status', 'counting')->assertJsonPath('counted', 1);
        Sanctum::actingAs($this->pharmacist);
        $this->putJson("{$url}/lines/{$line}", ['counted_units' => 50])->assertOk()->assertJsonPath('recount', false);
        $this->postJson("{$url}/submit")->assertOk();

        Sanctum::actingAs($this->owner);
        $this->postJson("{$url}/approve", ['lines' => [['id' => $paraLine, 'reason' => 'found']]])->assertOk();
        $this->assertSame(104, $this->para->fresh()->units_in_stock);
        $this->assertSame(50, $this->panto->fresh()->units_in_stock);
        $this->getJson('/api/pharmacy/audit/missing')->assertOk()->assertJsonPath('loss_value', 0)->assertJsonPath('totals.6.category', 'found')->assertJsonPath('totals.6.units', 4);

        // A count still being done can be cancelled by the person who started it; nothing changes in stock.
        Sanctum::actingAs($this->pharmacist);
        $other = $this->postJson('/api/pharmacy/audit/counts', ['scope' => 'letters', 'from' => 'p', 'to' => 'p'])->assertCreated()->assertJsonPath('lines', 2)->json('id');
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->postJson("/api/pharmacy/audit/counts/{$other}/cancel")->assertForbidden();
        Sanctum::actingAs($this->pharmacist);
        $this->postJson("/api/pharmacy/audit/counts/{$other}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->getJson('/api/pharmacy/audit/counts?status=approved')->assertOk()->assertJsonCount(1);
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/pharmacy/audit/counts')->assertForbidden();
    }

    public function test_a_correction_from_the_counter_waits_for_approval_and_shows_against_their_name(): void
    {
        // The pharmacist finds 8 pantoprazole gone to the ward without a bill.
        $this->postJson("/api/pharmacy/stock/{$this->panto->id}/adjust", ['units_in_stock' => 42, 'category' => 'unbilled', 'reason' => 'Given to the ward at night'])
            ->assertOk()->assertJsonPath('applied', false)->assertJsonPath('batch.units_in_stock', 50);
        $this->assertSame(0, StockAdjustment::count());

        Sanctum::actingAs($this->owner);
        $overview = $this->getJson('/api/pharmacy/audit/overview')->assertOk()->assertJsonPath('waiting.0.scope', 'reported')->assertJsonPath('waiting.0.short_value', 32)->json();
        $this->assertSame(1, collect($overview['signals'])->firstWhere('user', $this->pharmacist->name)['reported']);

        $count = StockCount::first();
        $this->postJson("/api/pharmacy/audit/counts/{$count->id}/approve")->assertOk(); // the reason came with the report
        $this->assertSame(42, $this->panto->fresh()->units_in_stock);
        $this->getJson('/api/pharmacy/audit/missing')->assertOk()->assertJsonPath('loss_value', 32)->assertJsonPath('unexplained_value', 0)
            ->assertJsonPath('rows.0.category', 'unbilled')->assertJsonPath('rows.0.reason', 'Given without a bill (ward, OT, staff): Given to the ward at night');

        // Stock cannot be written off below nothing.
        $this->postJson("/api/pharmacy/stock/{$this->panto->id}/adjust", ['units_in_stock' => 42, 'category' => 'miscount', 'reason' => 'Same count'])->assertStatus(422);
        $this->postJson("/api/pharmacy/stock/{$this->panto->id}/adjust", ['units_in_stock' => 0, 'category' => 'expired', 'reason' => 'Removed from the shelf'])->assertOk()->assertJsonPath('applied', true);
        $this->assertSame('correction', collect($this->ledger($this->panto))->last()['kind']);
    }

    public function test_a_spot_check_picks_a_few_batches_and_leaves_out_what_was_just_counted(): void
    {
        foreach (range(1, 8) as $i) {
            $this->receive("Sample Medicine {$i}", "S00{$i}", 2, 10 * $i, 20 * $i, 'C');
        }
        $this->getJson('/api/pharmacy/audit/scopes')->assertOk()->assertJsonPath('in_stock', 10)->assertJsonPath('racks.2.rack', 'C')->assertJsonPath('racks.2.batches', 8);

        $first = $this->postJson('/api/pharmacy/audit/counts', ['scope' => 'spot', 'size' => 4])->assertCreated()->assertJsonPath('title', 'Spot check')->assertJsonPath('lines', 4)->json();
        // The costliest stock is always among those picked: sample medicine 8 holds the most value.
        $this->assertContains('Sample Medicine 8', array_column($first['rows'], 'medicine'));
        foreach ($first['rows'] as $row) {
            $this->putJson("/api/pharmacy/audit/counts/{$first['id']}/lines/{$row['id']}", ['counted_units' => 20])->assertOk();
        }
        $this->postJson("/api/pharmacy/audit/counts/{$first['id']}/submit")->assertOk();
        Sanctum::actingAs($this->owner);
        $reasons = collect($this->getJson("/api/pharmacy/audit/counts/{$first['id']}")->json('rows'))->filter(fn ($r) => $r['difference'] !== 0)->map(fn ($r) => ['id' => $r['id'], 'reason' => 'miscount'])->values()->all();
        $this->postJson("/api/pharmacy/audit/counts/{$first['id']}/approve", ['lines' => $reasons])->assertOk();

        // The next spot check goes to shelves not counted in the last two weeks.
        $second = $this->postJson('/api/pharmacy/audit/counts', ['scope' => 'spot', 'size' => 4])->assertCreated()->json();
        $this->assertEmpty(array_intersect(array_column($first['rows'], 'stock_batch_id'), array_column($second['rows'], 'stock_batch_id')));
    }
}
