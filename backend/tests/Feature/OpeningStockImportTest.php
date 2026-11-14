<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OpeningStockImportTest extends TestCase
{
    use RefreshDatabase;

    private function row(array $over = []): array
    {
        return $over + ['medicine' => 'Paracetamol 500', 'batch_no' => 'PX1', 'expiry' => now()->addYear()->format('m/Y'), 'units_per_pack' => '10', 'quantity' => '120', 'cost_price' => '15', 'mrp' => '28', 'gst_percent' => '12'];
    }

    public function test_a_sheet_is_checked_line_by_line_before_anything_is_added(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $para = Medicine::create(['name' => 'Paracetamol 500', 'gst_percent' => 12, 'units_per_pack' => 10]);
        Medicine::create(['name' => 'Cetirizine 10', 'gst_percent' => 12, 'units_per_pack' => 10]);
        StockBatch::create(['medicine_id' => $para->id, 'batch_no' => 'OLD9', 'expiry' => now()->addYear(), 'units_per_pack' => 10, 'packs' => 1, 'pack_price' => 15, 'pack_mrp' => 28, 'unit_mrp' => 2.8, 'gst_percent' => 12, 'units_received' => 10, 'units_in_stock' => 10]);

        $result = $this->postJson('/api/pharmacy/opening-stock/import', ['rows' => [
            $this->row(),                                                                  // 1 good
            $this->row(['medicine' => '  paracetamol   500 ', 'batch_no' => 'px1']),       // 2 same batch again, typed differently
            $this->row(['batch_no' => 'old9']),                                            // 3 already on the shelf
            $this->row(['medicine' => 'Cetirizine 10', 'batch_no' => 'C1', 'expiry' => 'Jan-20']),          // 4 expired
            $this->row(['medicine' => 'Cetirizine 10', 'batch_no' => 'C2', 'cost_price' => '40']),          // 5 cost above MRP
            $this->row(['medicine' => 'Cetirizine 10', 'batch_no' => 'C3', 'quantity' => '0', 'expiry' => 'soon']), // 6 no count, unreadable expiry
            $this->row(['medicine' => 'Unknown Drug', 'batch_no' => 'U1']),                // 7 not in the list
            $this->row(['medicine' => 'Cetirizine 10', 'batch_no' => 'C4', 'units_per_pack' => '', 'gst_percent' => '', 'quantity' => '1,000', 'mrp' => '₹ 30', 'expiry' => now()->addMonths(2)->format('Y-m')]), // 8 good, with a warning
        ]])->assertOk()->json();

        $errors = collect($result['rows'])->mapWithKeys(fn ($r) => [$r['line'] => implode(' ', $r['errors'])]);
        $this->assertSame('', $errors[1]);
        $this->assertStringContainsString('also on row 2', $errors[2]);
        $this->assertStringContainsString('already in stock', $errors[3]);
        $this->assertStringContainsString('already expired', $errors[4]);
        $this->assertStringContainsString('more than the MRP', $errors[5]);
        $this->assertStringContainsString('whole number of units', $errors[6]);
        $this->assertStringContainsString('cannot be read', $errors[6]);
        $this->assertStringContainsString('not in the medicine list', $errors[7]);
        $this->assertSame('', $errors[8]);
        $this->assertStringContainsString('within three months', $result['rows'][7]['warnings'][0]);
        $this->assertSame([1000, 10, 12.0, 30.0], [$result['rows'][7]['quantity'], $result['rows'][7]['units_per_pack'], (float) $result['rows'][7]['gst_percent'], (float) $result['rows'][7]['mrp']]);
        $this->assertSame(8, $result['totals']['lines']);
        $this->assertSame(6, $result['totals']['with_errors']);
        $this->assertSame(1, $result['totals']['with_warnings']);

        // Nothing is added while any line is wrong.
        $this->postJson('/api/pharmacy/opening-stock/import', ['rows' => [$this->row(), $this->row(['batch_no' => 'old9'])], 'commit' => true, 'confirm' => ['lines' => 2, 'units' => 240, 'at_cost' => 360]])
            ->assertStatus(422)->assertJsonValidationErrors('rows');
        $this->assertSame(1, StockBatch::count());

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->postJson('/api/pharmacy/opening-stock/import', ['rows' => [$this->row()]])->assertForbidden();
    }

    public function test_a_clean_sheet_goes_in_whole_once_its_totals_are_agreed(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        Medicine::create(['name' => 'Paracetamol 500', 'gst_percent' => 12, 'units_per_pack' => 10]);
        $rows = [$this->row(), $this->row(['medicine' => 'New Syrup 100ml', 'batch_no' => 'S1', 'units_per_pack' => '1', 'quantity' => '24', 'cost_price' => '50', 'mrp' => '80', 'gst_percent' => '5'])];

        // 120 units at ₹1.50 + 24 bottles at ₹50 = ₹1,380 at cost.
        $totals = $this->postJson('/api/pharmacy/opening-stock/import', ['rows' => $rows, 'add_missing' => true])->assertOk()
            ->assertJsonPath('totals.with_errors', 0)->assertJsonPath('totals.new_medicines', 1)->assertJsonPath('totals.units', 144)
            ->assertJsonPath('totals.at_cost', 1380)->assertJsonPath('totals.at_mrp', 2256)->json('totals');
        $confirm = ['lines' => $totals['lines'], 'units' => $totals['units'], 'at_cost' => $totals['at_cost']];

        // Totals that are not the ones that were shown, or that disagree with the pharmacy's own sheet, stop the import.
        $this->postJson('/api/pharmacy/opening-stock/import', ['rows' => $rows, 'add_missing' => true, 'commit' => true, 'confirm' => ['units' => 100] + $confirm])->assertStatus(422)->assertJsonValidationErrors('rows');
        $this->postJson('/api/pharmacy/opening-stock/import', ['rows' => $rows, 'add_missing' => true, 'commit' => true, 'confirm' => $confirm, 'sheet_total' => 1500])->assertStatus(422)->assertJsonValidationErrors('sheet_total');
        $this->assertSame(0, StockBatch::count());

        $this->postJson('/api/pharmacy/opening-stock/import', ['rows' => $rows, 'add_missing' => true, 'commit' => true, 'confirm' => $confirm, 'sheet_total' => 1380])->assertCreated()->assertJsonPath('added', 2);
        $this->assertSame(2, StockBatch::count());
        $syrup = Medicine::where('name', 'New Syrup 100ml')->firstOrFail();
        $this->assertSame([1, 5.0], [$syrup->units_per_pack, (float) $syrup->gst_percent]);
        $batch = StockBatch::where('batch_no', 'PX1')->firstOrFail();
        $this->assertSame([120, 120, 12], [$batch->units_in_stock, $batch->units_received, $batch->packs]);
        $this->assertSame(now()->addYear()->endOfMonth()->toDateString(), \Illuminate\Support\Carbon::parse($batch->expiry)->toDateString());

        // The same sheet a second time is refused: every batch is already on the shelf.
        $this->postJson('/api/pharmacy/opening-stock/import', ['rows' => $rows, 'commit' => true, 'confirm' => $confirm])->assertStatus(422);
        $this->assertSame(2, StockBatch::count());
    }
}
