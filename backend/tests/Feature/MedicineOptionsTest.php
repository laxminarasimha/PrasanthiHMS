<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\Stockist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MedicineOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_brands_of_one_composition_show_where_each_can_be_ordered_from(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $medicine = fn (string $name, array $more = []) => Medicine::create($more + ['name' => $name, 'gst_percent' => 12, 'units_per_pack' => 10, 'is_active' => true]);
        $dolo = $medicine('DOLO 650 TAB', ['manufacturer' => 'MICRO LABS']);
        $calpol = $medicine('CALPOL 650 TAB', ['manufacturer' => 'GSK', 'composition' => 'Paracetamol 650 mg']);
        $augmentin = $medicine('AUGMENTIN 625 TAB');
        $medicine('OLD BRAND', ['is_active' => false, 'composition' => 'Paracetamol 650 mg']);
        $sri = Stockist::create(['name' => 'Sri Medicals']);
        $balaji = Stockist::create(['name' => 'Balaji Agencies']);

        // What a medicine contains is set without opening the whole form, and tidied as it is typed.
        $this->putJson("/api/pharmacy/medicines/{$dolo->id}/composition", ['composition' => '  Paracetamol   650 mg '])->assertOk()->assertJsonPath('composition', 'Paracetamol 650 mg');
        $this->putJson("/api/pharmacy/medicines/{$dolo->id}", ['name' => 'DOLO 650 TAB', 'composition' => 'Paracetamol 650 mg', 'gst_percent' => 12, 'units_per_pack' => 15])->assertOk()->assertJsonPath('composition', 'Paracetamol 650 mg');
        $this->getJson('/api/pharmacy/medicines?q=paracetamol')->assertOk()->assertJsonCount(2);

        // Dolo bought twice from Sri Medicals and once, later and cheaper, from Balaji. Calpol never bought.
        $buy = fn (Stockist $from, string $no, string $date, float $rate) => $this->postJson('/api/pharmacy/purchases', [
            'stockist_id' => $from->id, 'invoice_no' => $no, 'invoice_date' => $date,
            'items' => [['medicine_id' => $dolo->id, 'batch_no' => 'B'.$no, 'expiry' => now()->addYear()->format('Y-m'), 'units_per_pack' => 15, 'packs' => 2, 'pack_price' => $rate, 'pack_mrp' => 34, 'gst_percent' => 12]],
        ])->assertCreated();
        $buy($sri, 'S1', now()->subDays(40)->toDateString(), 24);
        $buy($sri, 'S2', now()->subDays(20)->toDateString(), 25);
        $buy($balaji, 'B1', now()->subDays(5)->toDateString(), 22.5);

        $data = $this->getJson('/api/pharmacy/medicines/options')->assertOk()->json();
        $this->assertSame(['Paracetamol 650 mg'], $data['compositions']);
        $byName = collect($data['medicines'])->keyBy('name');
        $this->assertSame(['AUGMENTIN 625 TAB', 'CALPOL 650 TAB', 'DOLO 650 TAB'], $byName->keys()->all());

        $option = $byName['DOLO 650 TAB'];
        $this->assertSame(['Paracetamol 650 mg', 'MICRO LABS', 90], [$option['composition'], $option['manufacturer'], $option['on_shelf']]);
        // The supplier used most comes first and is the usual one; each shows its own last rate and date.
        $this->assertSame([['Sri Medicals', 2, 25.0], ['Balaji Agencies', 1, 22.5]], array_map(fn ($s) => [$s['name'], $s['times'], (float) $s['last_rate']], $option['suppliers']));
        $this->assertSame(now()->subDays(20)->toDateString(), $option['suppliers'][0]['last_on']);
        $this->assertSame('Sri Medicals', $option['usual_stockist']);
        $this->assertSame([[], null, 0, null], [$byName['CALPOL 650 TAB']['suppliers'], $byName['CALPOL 650 TAB']['usual_stockist'], $byName['CALPOL 650 TAB']['on_shelf'], $byName['AUGMENTIN 625 TAB']['composition']]);

        // A supplier named on the medicine itself is the usual one, whatever the history says.
        $dolo->update(['preferred_stockist_id' => $balaji->id]);
        $this->assertSame('Balaji Agencies', collect($this->getJson('/api/pharmacy/medicines/options')->json('medicines'))->firstWhere('id', $dolo->id)['usual_stockist']);

        // The inventory lists only what is on the shelf; the rest are counted and left to the medicines list.
        $this->getJson('/api/pharmacy/inventory')->assertOk()->assertJsonCount(1, 'all')->assertJsonPath('all.0.name', 'DOLO 650 TAB')->assertJsonPath('counts.zero', 2);

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->putJson("/api/pharmacy/medicines/{$augmentin->id}/composition", ['composition' => 'x'])->assertForbidden();
    }
}
