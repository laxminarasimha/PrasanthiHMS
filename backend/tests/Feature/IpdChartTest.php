<?php

namespace Tests\Feature;

use App\Models\Bed;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IpdChartTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private User $nurse;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(14));
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        $this->nurse = User::factory()->create(['role' => 'nurse']);
        Sanctum::actingAs($this->nurse);
        $bed = Bed::create(['ward' => 'General Ward', 'bed_no' => '1', 'daily_charge' => 800]);
        $case = $this->postJson('/api/ipd/admissions', ['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $this->doctor->id, 'bed_id' => $bed->id, 'admitted_at' => now()->subDay()->format('Y-m-d H:i'), 'reason' => 'Fever'])->assertCreated()->json('id');
        $this->url = "/api/ipd/admissions/{$case}/chart";
    }

    public function test_nurses_chart_observations_and_fluids(): void
    {
        $now = now()->format('Y-m-d H:i');
        $this->getJson($this->url)->assertOk()->assertJsonPath('open', true)->assertJsonCount(0, 'vitals');

        $this->postJson("{$this->url}/vitals", ['recorded_at' => $now])->assertStatus(422); // nothing measured
        $this->postJson("{$this->url}/vitals", ['recorded_at' => $now, 'bp_systolic' => 120])->assertStatus(422)->assertJsonValidationErrors('bp_diastolic');
        $this->postJson("{$this->url}/vitals", ['recorded_at' => now()->addHour()->format('Y-m-d H:i'), 'pulse' => 80])->assertStatus(422)->assertJsonValidationErrors('recorded_at');
        $this->postJson("{$this->url}/vitals", ['recorded_at' => now()->subDays(3)->format('Y-m-d H:i'), 'pulse' => 80])->assertStatus(422)->assertJsonValidationErrors('recorded_at'); // before admission
        $this->postJson("{$this->url}/vitals", ['recorded_at' => now()->subHours(4)->format('Y-m-d H:i'), 'temperature_f' => 101.4, 'pulse' => 104])->assertCreated();
        $chart = $this->postJson("{$this->url}/vitals", ['recorded_at' => $now, 'temperature_f' => 99.2, 'pulse' => 88, 'bp_systolic' => 118, 'bp_diastolic' => 76, 'spo2' => 98, 'blood_sugar' => 142])->assertCreated()->json();
        $this->assertEquals([99.2, 101.4], array_column($chart['vitals'], 'temperature_f')); // newest first
        $this->assertSame($this->nurse->name, $chart['vitals'][0]['recorder']['name']);

        $this->postJson("{$this->url}/fluids", ['recorded_at' => $now, 'direction' => 'in', 'kind' => 'IV fluid', 'amount_ml' => 0])->assertStatus(422);
        $this->postJson("{$this->url}/fluids", ['recorded_at' => $now, 'direction' => 'in', 'kind' => 'IV fluid', 'amount_ml' => 500])->assertCreated();
        $this->postJson("{$this->url}/fluids", ['recorded_at' => $now, 'direction' => 'in', 'kind' => 'Oral', 'amount_ml' => 250])->assertCreated();
        $this->postJson("{$this->url}/fluids", ['recorded_at' => now()->subDay()->addHour()->format('Y-m-d H:i'), 'direction' => 'out', 'kind' => 'Urine', 'amount_ml' => 300])->assertCreated();
        $chart = $this->postJson("{$this->url}/fluids", ['recorded_at' => $now, 'direction' => 'out', 'kind' => 'Urine', 'amount_ml' => 400])->assertCreated()->json();
        $today = collect($chart['fluid_days'])->firstWhere('date', today()->toDateString());
        $this->assertSame([750, 400, 350], [$today['in'], $today['out'], $today['balance']]);
        $this->assertCount(2, $chart['fluid_days']);

        // A slip can be removed the same day by whoever made it, and by nobody else.
        $entry = $chart['fluids'][0]['id'];
        Sanctum::actingAs($this->doctor);
        $this->deleteJson("{$this->url}/fluids/{$entry}")->assertStatus(422);
        Sanctum::actingAs($this->nurse);
        $this->travel(1)->days();
        $this->deleteJson("{$this->url}/fluids/{$entry}")->assertStatus(422);
        $this->travelBack();
        $this->travelTo(now()->startOfDay()->addHours(14));
        $this->deleteJson("{$this->url}/fluids/{$entry}")->assertOk()->assertJsonCount(3, 'fluids');
    }

    public function test_doctors_order_and_nurses_record_each_dose(): void
    {
        $now = now()->format('Y-m-d H:i');
        // A nurse charts but does not order.
        $this->postJson("{$this->url}/orders", ['kind' => 'medicine', 'title' => 'Inj Ceftriaxone 1g'])->assertForbidden();

        Sanctum::actingAs($this->doctor);
        $this->postJson("{$this->url}/orders", ['kind' => 'medicine', 'title' => ''])->assertStatus(422);
        $chart = $this->postJson("{$this->url}/orders", ['kind' => 'medicine', 'title' => 'Inj Ceftriaxone 1g', 'dose' => '1 g', 'route' => 'IV', 'frequency' => '12 hourly'])->assertCreated()->json();
        $medicine = $chart['orders'][0]['id'];
        $diet = collect($this->postJson("{$this->url}/orders", ['kind' => 'instruction', 'title' => 'Soft diet, plenty of fluids'])->assertCreated()->json('orders'))->firstWhere('kind', 'instruction')['id'];

        Sanctum::actingAs($this->nurse);
        $this->postJson("{$this->url}/orders/{$diet}/doses", ['given_at' => $now, 'status' => 'given'])->assertStatus(422); // not a medicine
        $this->postJson("{$this->url}/orders/{$medicine}/doses", ['given_at' => $now, 'status' => 'held'])->assertStatus(422)->assertJsonValidationErrors('remarks');
        $this->postJson("{$this->url}/orders/{$medicine}/doses", ['given_at' => now()->subHours(12)->format('Y-m-d H:i'), 'status' => 'given'])->assertCreated();
        $this->postJson("{$this->url}/orders/{$medicine}/doses", ['given_at' => $now, 'status' => 'given'])->assertCreated();
        // The same dose entered twice is stopped unless it is said to be a real second dose.
        $this->postJson("{$this->url}/orders/{$medicine}/doses", ['given_at' => now()->subMinutes(10)->format('Y-m-d H:i'), 'status' => 'given'])->assertStatus(422)->assertJsonValidationErrors('given_at');
        $this->postJson("{$this->url}/orders/{$medicine}/doses", ['given_at' => now()->subMinutes(10)->format('Y-m-d H:i'), 'status' => 'given', 'confirm_repeat' => true])->assertCreated();
        $chart = $this->postJson("{$this->url}/orders/{$medicine}/doses", ['given_at' => $now, 'status' => 'refused', 'remarks' => 'Patient vomiting'])->assertCreated()->json();
        $order = collect($chart['orders'])->firstWhere('id', $medicine);
        $this->assertCount(4, $order['doses']);
        $this->assertSame($this->nurse->name, $order['doses'][0]['giver']['name']);
        $this->postJson("{$this->url}/orders/{$medicine}/stop")->assertForbidden();

        // Once the doctor stops it, it stays on the chart but cannot be given.
        Sanctum::actingAs($this->doctor);
        $stopped = collect($this->postJson("{$this->url}/orders/{$medicine}/stop")->assertOk()->json('orders'))->firstWhere('id', $medicine);
        $this->assertNotNull($stopped['stopped_at']);
        $this->assertSame($this->doctor->name, $stopped['stopper']['name']);
        $this->postJson("{$this->url}/orders/{$medicine}/stop")->assertStatus(422);
        $this->postJson("{$this->url}/orders/{$medicine}/doses", ['given_at' => $now, 'status' => 'given'])->assertStatus(422);
        $this->assertSame('instruction', $this->getJson($this->url)->json('orders.0.kind')); // running orders come first
    }

    public function test_the_chart_is_for_doctors_and_nurses_and_closes_at_discharge(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson($this->url)->assertForbidden();
        $this->postJson("{$this->url}/vitals", ['recorded_at' => now()->format('Y-m-d H:i'), 'pulse' => 80])->assertForbidden();

        Sanctum::actingAs($this->nurse);
        $this->postJson("{$this->url}/vitals", ['recorded_at' => now()->format('Y-m-d H:i'), 'pulse' => 80])->assertCreated();
        $this->postJson(str_replace('/chart', '/discharge', $this->url), ['status' => 'discharged', 'discharged_at' => now()->format('Y-m-d H:i')])->assertOk();

        $this->getJson($this->url)->assertOk()->assertJsonPath('open', false)->assertJsonCount(1, 'vitals');
        $this->postJson("{$this->url}/vitals", ['recorded_at' => now()->format('Y-m-d H:i'), 'pulse' => 80])->assertStatus(422)->assertJsonValidationErrors('admission');
        Sanctum::actingAs($this->doctor);
        $this->postJson("{$this->url}/orders", ['kind' => 'medicine', 'title' => 'Paracetamol'])->assertStatus(422);
    }
}
