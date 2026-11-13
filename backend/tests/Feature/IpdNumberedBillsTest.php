<?php

namespace Tests\Feature;

use App\Models\AdmissionBill;
use App\Models\Bed;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IpdNumberedBillsTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs(User::factory()->create(['role' => 'nurse']));
        $bed = Bed::create(['ward' => 'General Ward', 'bed_no' => '1', 'daily_charge' => 800]);
        $case = $this->postJson('/api/ipd/admissions', [
            'patient_id' => Patient::factory()->create()->id, 'doctor_id' => $this->doctor->id, 'bed_id' => $bed->id,
            'admitted_at' => now()->subDays(3)->format('Y-m-d H:i'), 'reason' => 'Fever for evaluation',
        ])->assertCreated()->json('id');
        $this->url = "/api/ipd/admissions/{$case}";
    }

    private function charge(string $what, float $price, int $daysAgo = 0): int
    {
        $charges = $this->postJson("{$this->url}/charges", ['description' => $what, 'charged_on' => now()->subDays($daysAgo)->toDateString(), 'quantity' => 1, 'unit_price' => $price])->assertCreated()->json('charges');

        return collect($charges)->firstWhere('description', $what)['id'];
    }

    public function test_charges_are_grouped_into_numbered_bills_and_locked(): void
    {
        $this->postJson("{$this->url}/bills")->assertStatus(422)->assertJsonValidationErrors('up_to'); // nothing to bill yet

        $old = $this->charge('Bed charges day 1', 800, 2);
        $this->charge('Doctor visit', 500, 2);
        $today = $this->charge('Bed charges day 3', 800);

        // An interim bill up to two days ago takes only the older charges.
        $case = $this->postJson("{$this->url}/bills", ['up_to' => now()->subDays(2)->toDateString(), 'notes' => 'First interim bill'])->assertCreated()->assertJsonCount(1, 'bills')->json();
        $first = $case['bills'][0];
        $this->assertSame('IPB00001', $first['bill_no']);
        $this->assertEquals(1300, $first['total']);
        $this->assertSame('First interim bill', $first['notes']);
        $byId = collect($case['charges'])->keyBy('id');
        $this->assertSame($first['id'], $byId[$old]['admission_bill_id']);
        $this->assertNull($byId[$today]['admission_bill_id']);
        $this->assertEquals(2100, $case['total']); // the case total is unchanged

        // Billed charges cannot be corrected or removed.
        $this->putJson("{$this->url}/charges/{$old}", ['charged_on' => now()->toDateString(), 'quantity' => 1, 'unit_price' => 1])->assertStatus(422)->assertJsonValidationErrors('charge');
        $this->deleteJson("{$this->url}/charges/{$old}")->assertStatus(422)->assertJsonValidationErrors('charge');
        $this->deleteJson("{$this->url}/charges/{$today}")->assertOk();
        $today = $this->charge('Bed charges day 3 again', 900);

        // The next bill takes the rest and gets the next number.
        $case = $this->postJson("{$this->url}/bills")->assertCreated()->assertJsonCount(2, 'bills')->json();
        $this->assertSame('IPB00002', $case['bills'][1]['bill_no']);
        $this->assertEquals(900, $case['bills'][1]['total']);
        $this->postJson("{$this->url}/bills")->assertStatus(422);

        // Cancelling a bill frees its charges; its number is not used again.
        $case = $this->deleteJson("{$this->url}/bills/{$first['id']}")->assertOk()->assertJsonCount(1, 'bills')->json();
        $this->assertNull(collect($case['charges'])->firstWhere('id', $old)['admission_bill_id']);
        $this->assertSame(1, AdmissionBill::onlyTrashed()->count());
        $this->deleteJson("{$this->url}/charges/{$old}")->assertOk();
        $this->assertSame('IPB00003', $this->postJson("{$this->url}/bills")->assertCreated()->json('bills.1.bill_no'));
    }

    public function test_bill_numbers_use_the_chosen_prefix_and_an_approved_case_keeps_its_bills(): void
    {
        Sanctum::actingAs($this->doctor);
        $this->putJson('/api/billing-preferences', ['default_payment_mode' => 'cash', 'pay_timing' => 'before', 'ipd_bill_prefix' => 'bad prefix!'])->assertStatus(422);
        $this->putJson('/api/billing-preferences', ['default_payment_mode' => 'cash', 'pay_timing' => 'before', 'ipd_bill_prefix' => 'PH/IP-'])->assertOk()->assertJsonPath('ipd_bill_prefix', 'PH/IP-');

        $this->charge('Bed charges', 800);
        $bill = $this->postJson("{$this->url}/bills")->assertCreated()->json('bills.0');
        $this->assertSame('PH/IP-00001', $bill['bill_no']);

        $this->postJson("{$this->url}/approve-bill", ['approved' => true])->assertOk();
        $this->deleteJson("{$this->url}/bills/{$bill['id']}")->assertStatus(422)->assertJsonValidationErrors('bill');

        // Someone without IPD work cannot make bills.
        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
        $this->postJson("{$this->url}/bills")->assertForbidden();
    }
}
