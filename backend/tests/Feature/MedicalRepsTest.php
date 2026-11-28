<?php

namespace Tests\Feature;

use App\Models\MedicalRep;
use App\Models\RepCompany;
use App\Models\RepVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Medical representatives: logged at the front desk as they arrive, seen by the doctor they came to meet. */
class MedicalRepsTest extends TestCase
{
    use RefreshDatabase;

    private User $desk;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = User::factory()->create(['role' => 'frontdesk']);
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($this->desk);
    }

    private function rep(array $more = []): array
    {
        return $this->postJson('/api/reps/people', $more + ['name' => 'Sample Rep One', 'company' => 'Sample Pharma', 'mobile' => '9000011111', 'division' => 'Cardiac'])->assertCreated()->json();
    }

    public function test_the_desk_keeps_the_list_of_companies_and_their_people(): void
    {
        $rep = $this->rep();
        $this->assertSame('Sample Pharma', $rep['company']['name']);

        // The same company typed again, in other letters, is the same company; the same person is not added twice.
        $second = $this->rep(['name' => 'Sample Rep Two', 'company' => 'sample  pharma', 'mobile' => '9000022222']);
        $this->assertSame($rep['company']['id'], $second['company']['id']);
        $this->assertSame(1, RepCompany::count());
        $this->postJson('/api/reps/people', ['name' => 'Someone Else', 'company' => 'Other Labs', 'mobile' => '9000011111'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/reps/people', ['name' => 'sample rep one', 'company' => 'Sample Pharma'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/reps/people', ['name' => 'X', 'company' => 'Sample Pharma', 'mobile' => 'abc'])->assertStatus(422)->assertJsonValidationErrors(['name', 'mobile']);

        $this->putJson("/api/reps/people/{$rep['id']}", ['name' => 'Sample Rep One', 'company' => 'Sample Pharma', 'mobile' => '9000011111', 'area' => 'Vijayawada', 'manager_name' => 'Sample Manager'])
            ->assertOk()->assertJsonPath('area', 'Vijayawada');
        $this->getJson('/api/reps/people?q=90000111')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Sample Rep One');
        $this->getJson('/api/reps/people?q=cardiac')->assertJsonCount(2);
        $this->getJson('/api/reps/people?q=sample pharma')->assertJsonCount(2);
        $this->getJson('/api/reps/companies')->assertOk()->assertJsonCount(1)->assertJsonPath('0.reps_count', 2);

        // The pharmacy may read the list; it does not change it or log visits. Others have no way in.
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->getJson('/api/reps/companies')->assertOk();
        $this->postJson('/api/reps/people', ['name' => 'Another Rep', 'company' => 'Sample Pharma'])->assertForbidden();
        $this->getJson('/api/reps/visits')->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
        $this->getJson('/api/reps/companies')->assertForbidden();
    }

    public function test_a_visit_is_logged_at_the_desk_and_closed_by_the_doctor_it_was_for(): void
    {
        $rep = $this->rep();
        $other = User::factory()->create(['role' => 'doctor']);

        $this->postJson('/api/reps/visits', ['medical_rep_id' => $rep['id'], 'doctor_id' => $this->desk->id])->assertStatus(422)->assertJsonValidationErrors('doctor_id');
        $visit = $this->postJson('/api/reps/visits', ['medical_rep_id' => $rep['id'], 'doctor_id' => $this->doctor->id, 'purpose' => 'New product'])
            ->assertCreated()->assertJsonPath('status', 'waiting')->assertJsonPath('rep.company.name', 'Sample Pharma')->assertJsonPath('doctor.name', $this->doctor->name)->json('id');
        // Not twice on the same waiting list.
        $this->postJson('/api/reps/visits', ['medical_rep_id' => $rep['id'], 'doctor_id' => $this->doctor->id])->assertStatus(422)->assertJsonValidationErrors('medical_rep_id');
        $this->getJson('/api/reps/visits')->assertOk()->assertJsonCount(1, 'visits')->assertJsonPath('can_log', true);

        // The doctor it is for sees it waiting, on their own list and on their To do; another doctor sees nothing of it.
        Sanctum::actingAs($this->doctor);
        $this->getJson('/api/reps/waiting')->assertOk()->assertJsonCount(1)->assertJsonPath('0.rep.name', 'Sample Rep One');
        $todo = collect($this->getJson('/api/todo')->json('waiting'))->firstWhere('key', 'doctor');
        $line = collect($todo['items'])->firstWhere('key', 'reps');
        $this->assertSame(1, $line['count']);
        $this->assertStringContainsString('Sample Rep One · Sample Pharma', $line['rows'][0]['text']);
        Sanctum::actingAs($other);
        $this->getJson('/api/reps/waiting')->assertJsonCount(0);
        $this->getJson('/api/reps/visits')->assertJsonCount(0, 'visits');
        $this->putJson("/api/reps/visits/{$visit}", ['status' => 'met'])->assertNotFound();

        // After the meeting the doctor writes what was spoken about.
        Sanctum::actingAs($this->doctor);
        $this->putJson("/api/reps/visits/{$visit}", ['status' => 'come_back'])->assertStatus(422)->assertJsonValidationErrors('next_on');
        $this->putJson("/api/reps/visits/{$visit}", ['status' => 'met', 'discussed' => 'Sample tablet 40 mg', 'offers' => 'Ten plus one', 'next_on' => today()->addMonth()->toDateString()])
            ->assertOk()->assertJsonPath('status', 'met')->assertJsonPath('discussed', 'Sample tablet 40 mg');
        $saved = RepVisit::find($visit);
        $this->assertNotNull($saved->closed_at);
        $this->assertSame($this->doctor->id, $saved->closed_by);
        $this->getJson('/api/reps/waiting')->assertJsonCount(0);

        // The representative's page keeps the history; a visit that was written up cannot be taken off.
        Sanctum::actingAs($this->desk);
        $this->getJson("/api/reps/people/{$rep['id']}")->assertOk()->assertJsonCount(1, 'visits')->assertJsonPath('visits.0.offers', 'Ten plus one');
        $this->getJson('/api/reps/people')->assertJsonPath('0.last_visit.status', 'met');
        $this->deleteJson("/api/reps/visits/{$visit}")->assertStatus(422);
        $mistake = $this->postJson('/api/reps/visits', ['medical_rep_id' => $rep['id'], 'doctor_id' => $other->id])->assertCreated()->json('id');
        $this->deleteJson("/api/reps/visits/{$mistake}")->assertNoContent();
        $this->assertSame(1, RepVisit::count());
    }

    public function test_a_doctors_own_hours_for_representatives_warn_the_desk(): void
    {
        $rep = $this->rep();
        $second = $this->rep(['name' => 'Sample Rep Two', 'mobile' => '9000022222']);

        // Only the doctor, or an admin, sets them.
        $this->putJson('/api/reps/hours', ['doctor_id' => $this->doctor->id, 'most' => 1])->assertForbidden();
        Sanctum::actingAs($this->doctor);
        $this->putJson('/api/reps/hours', ['doctor_id' => $this->doctor->id, 'from' => '14:00', 'to' => '13:00'])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->putJson('/api/reps/hours', ['doctor_id' => $this->doctor->id, 'most' => 1])->assertOk()->assertJsonPath("hours.{$this->doctor->id}.most", 1);

        Sanctum::actingAs($this->desk);
        $this->postJson('/api/reps/visits', ['medical_rep_id' => $rep['id'], 'doctor_id' => $this->doctor->id])->assertCreated();
        // The second of the day is over the doctor's limit: the desk is told why, and may still log it.
        $this->postJson('/api/reps/visits', ['medical_rep_id' => $second['id'], 'doctor_id' => $this->doctor->id])->assertStatus(422)->assertJsonValidationErrors('outside_hours');
        $this->postJson('/api/reps/visits', ['medical_rep_id' => $second['id'], 'doctor_id' => $this->doctor->id, 'anyway' => true])->assertCreated();

        // An hour that has already gone by today is outside the hours too.
        Sanctum::actingAs($this->doctor);
        $this->travelTo(today()->setTime(16, 0));
        $this->putJson('/api/reps/hours', ['doctor_id' => $this->doctor->id, 'from' => '13:00', 'to' => '14:00'])->assertOk();
        Sanctum::actingAs($this->desk);
        $third = $this->rep(['name' => 'Sample Rep Three', 'mobile' => '9000033333']);
        $this->postJson('/api/reps/visits', ['medical_rep_id' => $third['id'], 'doctor_id' => $this->doctor->id])->assertStatus(422)
            ->assertJsonPath('errors.outside_hours.0', "{$this->doctor->name} sees medical representatives from 1:00 PM to 2:00 PM. It is 4:00 PM now.");

        // Cleared, the doctor sees them at any time again.
        Sanctum::actingAs($this->doctor);
        $this->putJson('/api/reps/hours', ['doctor_id' => $this->doctor->id])->assertOk();
        Sanctum::actingAs($this->desk);
        $this->postJson('/api/reps/visits', ['medical_rep_id' => $third['id'], 'doctor_id' => $this->doctor->id])->assertCreated();
    }

    public function test_a_companys_catalogue_is_kept_as_a_file(): void
    {
        Storage::fake('local');
        $company = $this->rep()['company']['id'];

        $this->postJson("/api/reps/companies/{$company}/files", ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertStatus(422)->assertJsonValidationErrors('file');
        $file = $this->post("/api/reps/companies/{$company}/files", ['title' => 'Price list 2026', 'file' => UploadedFile::fake()->create('prices.pdf', 120, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('files.0.title', 'Price list 2026')->assertJsonMissingPath('files.0.path')->json('files.0.id');

        // The pharmacy can open it; only the desk can take it off.
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->get("/api/reps/files/{$file}/download")->assertOk();
        $this->deleteJson("/api/reps/files/{$file}")->assertForbidden();
        Sanctum::actingAs($this->desk);
        $this->putJson("/api/reps/companies/{$company}", ['name' => 'Sample Pharma Ltd', 'notes' => 'Cardiac and diabetes'])->assertOk()->assertJsonPath('name', 'Sample Pharma Ltd');
        $this->deleteJson("/api/reps/files/{$file}")->assertNoContent();
        $this->assertSame(0, MedicalRep::first()->company->files()->count());
    }
}
