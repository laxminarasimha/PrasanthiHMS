<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_clinic_details_and_logo_are_used_for_prints(): void
    {
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/clinic')->assertOk()->assertJsonPath('logo', null)->assertJsonStructure(['name', 'address', 'phone', 'footer']);
        $this->putJson('/api/clinic', ['name' => 'Sample Hospital'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson('/api/clinic', ['name' => ''])->assertStatus(422);
        $this->putJson('/api/clinic', ['name' => 'Sample Hospital', 'address' => '1 Main Road, Sampletown', 'phone' => '0866 000000', 'footer' => 'Get well soon'])
            ->assertOk()->assertJsonPath('name', 'Sample Hospital')->assertJsonPath('footer', 'Get well soon');

        $this->post('/api/clinic/logo', ['file' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $logo = $this->post('/api/clinic/logo', ['file' => UploadedFile::fake()->image('logo.png', 200, 200)], ['Accept' => 'application/json'])->assertOk()->json('logo');
        $this->assertStringStartsWith('data:image/png;base64,', $logo);

        // Saving the details again keeps the logo; removing it deletes the file.
        $this->putJson('/api/clinic', ['name' => 'Sample Hospital', 'phone' => '0866 111111'])->assertOk()->assertJsonPath('logo', $logo)->assertJsonPath('address', '1 Main Road, Sampletown');
        $this->deleteJson('/api/clinic/logo')->assertOk()->assertJsonPath('logo', null);
        $this->assertSame([], Storage::disk('local')->allFiles('clinic'));
    }

    public function test_referral_doctors_are_listed_by_direction(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $id = $this->postJson('/api/referral-doctors', ['direction' => 'by', 'name' => 'Dr Sample Referrer', 'specialization' => 'General Medicine'])->assertCreated()->json('id');
        $this->postJson('/api/referral-doctors', ['direction' => 'by', 'name' => 'Dr Sample Referrer'])->assertStatus(422);
        // The same doctor can also be someone we refer to.
        $this->postJson('/api/referral-doctors', ['direction' => 'to', 'name' => 'Dr Sample Referrer'])->assertCreated();
        $this->postJson('/api/referral-doctors', ['direction' => 'sideways', 'name' => 'X'])->assertStatus(422);
        $this->putJson("/api/referral-doctors/{$id}", ['direction' => 'by', 'name' => 'Dr Sample Referrer', 'place' => 'Sampletown'])->assertOk()->assertJsonPath('place', 'Sampletown');

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/referral-doctors')->assertOk()->assertJsonCount(2);
        $this->deleteJson("/api/referral-doctors/{$id}")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->deleteJson("/api/referral-doctors/{$id}")->assertNoContent();
    }

    public function test_merging_moves_the_history_and_hides_the_duplicate(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $doctor = User::factory()->create(['role' => 'doctor']);
        $keep = Patient::factory()->create(['name' => 'Ravi Kumar', 'mobile' => '9000000001', 'allergies' => null, 'email' => null]);
        $duplicate = Patient::factory()->create(['name' => 'Ravi Kumar K', 'mobile' => '9000000002', 'allergies' => 'Penicillin', 'email' => 'sample@example.test']);
        $this->postJson('/api/appointments', ['patient_id' => $duplicate->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i')])->assertCreated();
        $this->postJson('/api/appointments', ['patient_id' => $keep->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i')])->assertCreated();

        $this->postJson('/api/patient-merges', ['from' => $duplicate->uhid, 'to' => $duplicate->uhid])->assertStatus(422);
        $this->postJson('/api/patient-merges', ['from' => '0000', 'to' => $keep->uhid])->assertStatus(422)->assertJsonValidationErrors('from');

        $this->postJson('/api/patient-merges', ['from' => $duplicate->uhid, 'to' => $keep->uhid])->assertCreated()
            ->assertJsonPath('moved.appointments', 1)->assertJsonPath('from_name', 'Ravi Kumar K')->assertJsonPath('to_uhid', $keep->uhid);

        $this->assertDatabaseMissing('appointments', ['patient_id' => $duplicate->id]);
        $this->assertSame(2, \App\Models\Appointment::where('patient_id', $keep->id)->count());
        $kept = $keep->fresh();
        $this->assertSame(['Penicillin', 'sample@example.test', '9000000002'], [$kept->allergies, $kept->email, $kept->mobile_alt]);

        // The duplicate no longer shows in searches, and cannot be merged again or merged into.
        $this->getJson('/api/patients')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/patients?mobile=9000000002')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $keep->id);
        $this->postJson('/api/patient-merges', ['from' => $duplicate->uhid, 'to' => $keep->uhid])->assertStatus(422);
        $this->postJson('/api/patient-merges', ['from' => $keep->uhid, 'to' => $duplicate->uhid])->assertStatus(422);
        $this->getJson('/api/patient-merges')->assertOk()->assertJsonCount(1);

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->postJson('/api/patient-merges', ['from' => 'a', 'to' => 'b'])->assertForbidden();
    }

    public function test_departments_vendors_and_payers_are_kept_as_lists(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin-lists/planets', ['name' => 'Mars'])->assertNotFound();

        $dept = $this->postJson('/api/admin-lists/department', ['name' => 'General Medicine', 'details' => ['head' => 'Dr Sample', 'phone' => 'not a department box']])
            ->assertCreated()->assertJsonPath('details.head', 'Dr Sample')->assertJsonMissingPath('details.phone')->json('id');
        $this->postJson('/api/admin-lists/department', ['name' => 'General Medicine'])->assertStatus(422);
        // The same name can be used in another list.
        $this->postJson('/api/admin-lists/vendor', ['name' => 'General Medicine', 'details' => ['phone' => '9000000000']])->assertCreated();
        $payer = $this->postJson('/api/admin-lists/payer', ['name' => 'Sample Insurance', 'details' => ['billing_mode' => 'credit']])->assertCreated()->json('id');

        $this->putJson("/api/admin-lists/department/{$dept}", ['name' => 'General Medicine', 'is_active' => false])->assertOk();
        $this->putJson("/api/admin-lists/vendor/{$dept}", ['name' => 'X'])->assertNotFound();
        $this->getJson('/api/admin-lists/department')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/admin-lists/department?all=1')->assertJsonCount(1);

        // A payer can be named on an IPD case; a department cannot stand in for one.
        $doctor = User::factory()->create(['role' => 'doctor']);
        $admit = fn ($payerId) => $this->postJson('/api/ipd/admissions', ['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'payer_id' => $payerId, 'admitted_at' => now()->format('Y-m-d H:i')]);
        $admit($dept)->assertStatus(422)->assertJsonValidationErrors('payer_id');
        $admit($payer)->assertCreated()->assertJsonPath('payer.name', 'Sample Insurance');

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/admin-lists/payer')->assertOk()->assertJsonCount(1);
        $this->postJson('/api/admin-lists/payer', ['name' => 'Another'])->assertForbidden();
    }

    public function test_new_ipd_cases_take_the_chosen_prefix(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $doctor = User::factory()->create(['role' => 'doctor']);
        $admit = fn () => $this->postJson('/api/ipd/admissions', ['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'admitted_at' => now()->format('Y-m-d H:i')])->assertCreated()->json('ipd_no');

        $this->assertStringStartsWith('IP0', $admit());
        $prefs = ['default_payment_mode' => 'cash', 'pay_timing' => 'before'];
        $this->putJson('/api/billing-preferences', $prefs + ['ipd_prefix' => 'not valid!'])->assertStatus(422);
        $this->putJson('/api/billing-preferences', $prefs + ['ipd_prefix' => 'PH/26-'])->assertOk()->assertJsonPath('ipd_prefix', 'PH/26-');
        $this->assertStringStartsWith('PH/26-0', $admit());
        // Saving the other preferences without the prefix keeps it.
        $this->putJson('/api/billing-preferences', $prefs)->assertOk()->assertJsonPath('ipd_prefix', 'PH/26-');
    }
}
