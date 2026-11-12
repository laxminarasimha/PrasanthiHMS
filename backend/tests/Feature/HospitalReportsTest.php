<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HospitalReportController;
use App\Models\Appointment;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HospitalReportsTest extends TestCase
{
    use RefreshDatabase;

    private function rows(string $type, string $query = ''): array
    {
        return $this->getJson("/api/reports/list/{$type}{$query}")->assertOk()->json();
    }

    public function test_front_desk_and_doctor_reports(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::factory()->create();
        $service = Service::factory()->create(['name' => 'Consultation', 'price' => 500]);

        // A visit today: billed 500 less 50, 300 paid in cash, seen and completed.
        $visit = $this->postJson('/api/appointments', [
            'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'service_id' => $service->id, 'scheduled_at' => now()->format('Y-m-d H:i'),
            'status' => 'arrived', 'discount' => 50, 'paid' => 300, 'payment_mode' => 'cash',
        ])->assertCreated()->json();
        $this->putJson("/api/appointments/{$visit['id']}/consultation", [
            'diagnosis' => "Type 2 diabetes\nHypertension", 'tests_advised' => 'HbA1c, Lipid profile', 'follow_up_on' => now()->addDays(3)->toDateString(),
            'medicines' => [['medicine' => 'Metformin 500mg'], ['medicine' => 'Telmisartan 40mg']], 'complete' => true,
        ])->assertOk();

        // A second patient with the same diagnosis line, a missed booking yesterday and a booking tomorrow.
        $second = $this->postJson('/api/appointments', ['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i'), 'status' => 'arrived'])->json('id');
        $this->putJson("/api/appointments/{$second}/consultation", ['diagnosis' => 'hypertension', 'medicines' => [['medicine' => 'telmisartan 40mg']], 'complete' => true])->assertOk();
        Appointment::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->subDay()->format('Y-m-d 11:00'), 'status' => 'booked']);
        Appointment::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->addDay()->format('Y-m-d 11:00'), 'status' => 'booked']);

        // A lab order with one high result.
        $test = LabTest::create(['name' => 'Random Blood Sugar', 'price' => 100]);
        $test->parameters()->create(['name' => 'RBS', 'reference_range' => '70 - 140']);
        $order = $this->postJson('/api/lab/orders', ['patient_id' => $patient->id, 'test_ids' => [$test->id]])->assertCreated()->json();
        $item = $order['items'][0];
        $this->putJson("/api/lab/orders/{$order['id']}/items/{$item['id']}", ['complete' => true, 'results' => [['id' => $item['results'][0]['id'], 'value' => '182', 'flag' => 'high']]])->assertOk();

        $bills = $this->rows('bills');
        $this->assertCount(2, $bills['rows']); // the visit and the lab order
        $this->assertEquals(450, $bills['rows'][0]['total']);
        $this->assertEquals(150, $bills['rows'][0]['due']);
        $this->assertEquals(550, $bills['totals']['total']);
        $this->assertArrayHasKey('bills', $bills['reports']);

        $this->assertSame('Consultation', $this->rows('bill-lines')['rows'][0]['service']);
        $collections = $this->rows('collections');
        $this->assertEquals(300, $collections['totals']['amount']);
        $this->assertSame('cash', $collections['rows'][0]['mode']);
        $this->assertEquals(450, collect($this->rows('services')['rows'])->firstWhere('service', 'Consultation')['amount']);

        $week = '?from='.now()->subDays(2)->toDateString().'&to='.now()->addDays(4)->toDateString();
        $this->assertCount(4, $this->rows('appointments', $week)['rows']);
        $this->assertCount(1, $this->rows('upcoming', $week)['rows']);
        $this->assertCount(1, $this->rows('missed', $week)['rows']);
        $advised = $this->rows('advised-visits', $week)['rows'];
        $this->assertCount(1, $advised);
        $this->assertSame('Not booked', $advised[0]['booked']);
        $this->assertSame('Type 2 diabetes', $advised[0]['diagnosis']);
        $this->assertCount(2, $this->rows('waiting-time')['rows']);

        $consultations = $this->rows('consultations')['rows'];
        $this->assertSame(2, $consultations[0]['total']);
        $this->assertSame(2, $consultations[0]['new']);

        $medicines = $this->rows('prescriptions')['rows'];
        $this->assertSame(['Telmisartan 40mg', 2], [$medicines[0]['medicine'], $medicines[0]['times']]); // same medicine, different capitals
        $diagnoses = $this->rows('diagnoses')['rows'];
        $this->assertSame(['Hypertension', 2], [$diagnoses[0]['name'], $diagnoses[0]['times']]);

        $tests = collect($this->rows('tests-advised')['rows'])->keyBy('test');
        $this->assertSame(1, $tests['Random Blood Sugar']['ordered']);
        $this->assertSame(1, $tests['HbA1c']['advised']);
        $outcome = $this->rows('test-outcomes')['rows'][0];
        $this->assertSame(['RBS', 1, 0], [$outcome['parameter'], $outcome['high'], $outcome['normal']]);

        // Every report in the list answers.
        foreach (array_keys(HospitalReportController::REPORTS) as $type) {
            $this->getJson("/api/reports/list/{$type}{$week}")->assertOk()->assertJsonStructure(['title', 'columns', 'rows', 'totals']);
        }
        $this->getJson('/api/reports/list/nothing')->assertNotFound();

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/reports/list/bills')->assertForbidden();
    }
}
