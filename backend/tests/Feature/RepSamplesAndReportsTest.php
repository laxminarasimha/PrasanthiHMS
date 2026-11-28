<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\RepSample;
use App\Models\RepVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Samples left by medical representatives, and the reports about their visits. */
class RepSamplesAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $desk;

    private User $doctor;

    private array $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = User::factory()->create(['role' => 'frontdesk']);
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($this->desk);
        $this->rep = $this->postJson('/api/reps/people', ['name' => 'Sample Rep One', 'company' => 'Sample Pharma', 'mobile' => '9000011111'])->assertCreated()->json();
    }

    private function sample(array $more = []): array
    {
        return $this->postJson('/api/reps/samples', $more + [
            'rep_company_id' => $this->rep['company']['id'], 'medical_rep_id' => $this->rep['id'], 'name' => 'Samplepan 40', 'quantity' => 20, 'unit' => 'strips',
            'expiry' => today()->addMonths(6)->toDateString(), 'received_on' => today()->toDateString(),
        ])->assertCreated()->json();
    }

    public function test_samples_are_received_given_out_and_counted(): void
    {
        $this->postJson('/api/reps/products', ['rep_company_id' => $this->rep['company']['id'], 'name' => 'Samplepan 40', 'composition' => 'Pantoprazole 40 mg'])->assertCreated();
        $other = $this->postJson('/api/reps/people', ['name' => 'Sample Rep Two', 'company' => 'Other Labs', 'mobile' => '9000022222'])->json();

        $this->postJson('/api/reps/samples', ['rep_company_id' => $this->rep['company']['id'], 'medical_rep_id' => $other['id'], 'name' => 'Samplepan 40', 'quantity' => 5, 'received_on' => today()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('medical_rep_id');
        $this->postJson('/api/reps/samples', ['rep_company_id' => $this->rep['company']['id'], 'name' => 'Samplepan 40', 'quantity' => 0, 'received_on' => today()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['quantity', 'received_on']);
        $sample = $this->sample();
        // Tied to the brand in the company's catalogue, and nothing has left the shelf yet.
        $this->assertNotNull($sample['rep_product_id']);
        $this->assertSame(20, $sample['in_hand']);

        $url = "/api/reps/samples/{$sample['id']}";
        $patient = Patient::factory()->create();
        $this->postJson("{$url}/issues", ['kind' => 'given', 'quantity' => 25])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->postJson("{$url}/issues", ['kind' => 'given', 'quantity' => 2, 'given_on' => today()->subDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('given_on');
        $given = $this->postJson("{$url}/issues", ['kind' => 'given', 'quantity' => 6, 'patient_id' => $patient->id, 'note' => 'To start treatment'])
            ->assertCreated()->assertJsonPath('given', 6)->assertJsonPath('in_hand', 14)->assertJsonPath('issues.0.patient.id', $patient->id)->json('issues.0.id');
        $this->postJson("{$url}/issues", ['kind' => 'discarded', 'quantity' => 4, 'note' => 'Strips torn'])->assertCreated()->assertJsonPath('discarded', 4)->assertJsonPath('in_hand', 10);

        // The number received cannot be put below what has already gone; an entry with history stays on record.
        $this->putJson($url, ['rep_company_id' => $this->rep['company']['id'], 'name' => 'Samplepan 40', 'quantity' => 8, 'received_on' => today()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->deleteJson($url)->assertStatus(422);
        // A giving-out entered by mistake is taken back.
        $this->deleteJson("/api/reps/samples/issues/{$given}")->assertOk()->assertJsonPath('in_hand', 16);

        // Expired samples can only be thrown away; and they show on the desk's To do.
        $old = $this->sample(['name' => 'Samplecef 200', 'expiry' => today()->subDays(3)->toDateString(), 'received_on' => today()->subMonth()->toDateString(), 'quantity' => 3]);
        $this->postJson("/api/reps/samples/{$old['id']}/issues", ['kind' => 'given', 'quantity' => 1])->assertStatus(422)->assertJsonValidationErrors('kind');
        $todo = collect($this->getJson('/api/todo')->json('waiting'))->firstWhere('key', 'front_desk');
        $line = collect($todo['items'])->firstWhere('key', 'samples_expiring');
        $this->assertSame(1, $line['count']);
        $this->assertStringContainsString('Samplecef 200', $line['rows'][0]['text']);
        $this->postJson("/api/reps/samples/{$old['id']}/issues", ['kind' => 'discarded', 'quantity' => 3])->assertCreated()->assertJsonPath('in_hand', 0);

        // The list shows what is on the shelf; all of it on request.
        $this->getJson('/api/reps/samples')->assertOk()->assertJsonCount(1, 'samples');
        $this->getJson('/api/reps/samples?show=all')->assertJsonCount(2, 'samples');
        $this->assertSame(0, \App\Models\StockMovement::count());
    }

    public function test_a_doctor_sees_their_own_samples_and_the_pharmacy_has_no_part_in_them(): void
    {
        $other = User::factory()->create(['role' => 'doctor']);
        $mine = $this->sample(['doctor_id' => $this->doctor->id]);
        $theirs = $this->sample(['doctor_id' => $other->id, 'name' => 'Samplecef 200']);
        $anyones = $this->sample(['name' => 'Samplevit']);

        Sanctum::actingAs($this->doctor);
        $this->assertEqualsCanonicalizing([$mine['id'], $anyones['id']], array_column($this->getJson('/api/reps/samples')->json('samples'), 'id'));
        $this->postJson("/api/reps/samples/{$theirs['id']}/issues", ['kind' => 'given', 'quantity' => 1])->assertNotFound();
        $this->postJson("/api/reps/samples/{$mine['id']}/issues", ['kind' => 'given', 'quantity' => 1])->assertCreated();

        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->getJson('/api/reps/samples')->assertForbidden();
        $this->postJson('/api/reps/samples', ['rep_company_id' => $this->rep['company']['id'], 'name' => 'Samplepan 40', 'quantity' => 5, 'received_on' => today()->toDateString()])->assertForbidden();
    }

    public function test_the_reports_on_representatives(): void
    {
        $quiet = $this->postJson('/api/reps/people', ['name' => 'Sample Rep Quiet', 'company' => 'Other Labs', 'mobile' => '9000022222'])->json();
        $visit = $this->postJson('/api/reps/visits', ['medical_rep_id' => $this->rep['id'], 'doctor_id' => $this->doctor->id])->assertCreated()->json('id');
        $this->putJson("/api/reps/visits/{$visit}", ['status' => 'met', 'discussed' => 'Samplepan 40', 'offers' => 'Ten plus one'])->assertOk();
        // The other representative last came 50 days ago and was not met.
        RepVisit::create(['medical_rep_id' => $quiet['id'], 'doctor_id' => $this->doctor->id, 'arrived_at' => now()->subDays(50), 'status' => 'not_met']);
        $this->sample();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $today = today()->toDateString();
        $range = "from={$today}&to={$today}";

        $this->getJson("/api/reports/list/rep-visits?{$range}")->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.rep', 'Sample Rep One')->assertJsonPath('rows.0.company', 'Sample Pharma')->assertJsonPath('rows.0.status', 'Met')->assertJsonPath('rows.0.offers', 'Ten plus one');

        $companies = collect($this->getJson("/api/reports/list/rep-companies?{$range}")->assertOk()->json('rows'))->keyBy('company');
        $this->assertSame(1, $companies['Sample Pharma']['visits']);
        $this->assertSame(1, $companies['Sample Pharma']['met']);
        $this->assertSame(0, $companies['Other Labs']['visits']);
        $this->assertSame(today()->subDays(50)->toDateString(), $companies['Other Labs']['last_visit']);

        $this->getJson("/api/reports/list/rep-not-seen?{$range}")->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.rep', 'Sample Rep Quiet')->assertJsonPath('rows.0.days', 50)->assertJsonPath('rows.0.why', 'Not come for 50 days');
        $this->getJson("/api/reports/list/rep-offers?{$range}")->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.offers', 'Ten plus one')->assertJsonPath('rows.0.mobile', '9000011111');
        $this->getJson("/api/reports/list/rep-samples?{$range}")->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.sample', 'Samplepan 40')->assertJsonPath('rows.0.received', 20)->assertJsonPath('rows.0.in_hand', 20)->assertJsonPath('totals.received', 20);

        // They are in the list of reports the screen offers.
        $this->assertArrayHasKey('rep-not-seen', $this->getJson("/api/reports/list/bills?{$range}")->json('reports'));
        $this->assertSame(1, RepSample::count());
    }
}
