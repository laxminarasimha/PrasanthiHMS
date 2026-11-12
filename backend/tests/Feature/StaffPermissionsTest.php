<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Stockist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function details(User $user, array $extra = []): array
    {
        return $extra + ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'gender' => 'female', 'phone' => '9000000000'];
    }

    public function test_a_person_can_be_given_or_refused_things_their_role_does_not_decide(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $desk = User::factory()->create(['role' => 'frontdesk']);
        Sanctum::actingAs($admin);

        $list = $this->getJson('/api/staff')->assertOk()->json();
        $this->assertContains('canRunLab', array_column($list['abilities'], 'key'));
        $mine = collect($list['data'])->firstWhere('id', $desk->id);
        $this->assertTrue($mine['abilities']['canWorkFrontDesk']);
        $this->assertFalse($mine['abilities']['canRunLab']);

        // Give lab and reports, take away IPD. Only the differences from the role are kept.
        $this->putJson("/api/staff/{$desk->id}", $this->details($desk, ['permissions' => ['canRunLab' => true, 'canViewReports' => true, 'canWorkIpd' => false, 'canWorkFrontDesk' => true]]))
            ->assertOk()->assertJsonPath('abilities.canRunLab', true)->assertJsonPath('abilities.canWorkIpd', false);
        $this->assertSame(['canRunLab' => true, 'canViewReports' => true, 'canWorkIpd' => false], $desk->fresh()->permissions);
        $this->putJson("/api/staff/{$desk->id}", $this->details($desk, ['permissions' => ['canFly' => true]]))->assertStatus(422);

        Sanctum::actingAs($desk->fresh());
        $this->getJson('/api/me')->assertJsonPath('can_run_lab', true)->assertJsonPath('can_ipd', false)->assertJsonPath('can_view_reports', true);
        $this->getJson('/api/reports/summary')->assertOk();
        $this->postJson('/api/lab/tests', ['name' => 'ECG', 'kind' => 'text', 'price' => 300])->assertCreated();
        $this->postJson('/api/ipd/admissions', [])->assertForbidden();
        $this->getJson('/api/staff')->assertForbidden();

        // Saving without the permissions list leaves them as they are; an empty list puts the role's own back.
        Sanctum::actingAs($admin);
        $this->putJson("/api/staff/{$desk->id}", $this->details($desk))->assertOk()->assertJsonPath('abilities.canRunLab', true);
        $this->putJson("/api/staff/{$desk->id}", $this->details($desk, ['permissions' => []]))->assertOk()->assertJsonPath('abilities.canRunLab', false);
        $this->assertNull($desk->fresh()->permissions);
    }

    public function test_nobody_can_change_their_own_permissions_and_admins_keep_everything(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($doctor);
        $this->putJson("/api/staff/{$doctor->id}", $this->details($doctor, ['permissions' => ['canManageStaff' => false]]))->assertOk()->assertJsonPath('abilities.canManageStaff', true);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->putJson("/api/staff/{$admin->id}", $this->details($admin, ['permissions' => ['canManageStaff' => false, 'canRunLab' => false]]))->assertOk();
        $this->assertTrue($admin->fresh()->allows('canManageStaff'));
        $this->assertTrue($admin->fresh()->allows('canRunLab'));
    }

    public function test_changing_the_price_and_deleting_bills_can_be_switched_off_for_a_person(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $service = Service::create(['name' => 'Consultation', 'type' => 'appointment', 'price' => 500, 'gst_percent' => 0]);
        $book = fn (array $extra = []) => $this->postJson('/api/appointments', $extra + ['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'service_id' => $service->id, 'scheduled_at' => now()->addDay()->format('Y-m-d 11:00')]);

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk', 'permissions' => ['canChangePrice' => false]]));
        $book(['unit_price' => 300])->assertStatus(422)->assertJsonValidationErrors('unit_price');
        $bill = $book(['unit_price' => 500])->assertCreated()->json('bills.0.id');
        $this->postJson("/api/bills/{$bill}/items", ['service_id' => $service->id, 'unit_price' => 100])->assertStatus(422);
        $this->postJson("/api/bills/{$bill}/items", ['description' => 'Dressing', 'unit_price' => 100])->assertCreated();

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $book(['unit_price' => 300])->assertCreated();

        // A pharmacist who may sell but not delete bills.
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist', 'permissions' => ['canDeleteBills' => false]]));
        $medicine = Medicine::create(['name' => 'Paracetamol 650mg Tablet', 'gst_percent' => 12, 'units_per_pack' => 10]);
        $invoice = $this->postJson('/api/pharmacy/purchases', ['stockist_id' => Stockist::create(['name' => 'Sample Distributors'])->id, 'invoice_no' => 'INV-1', 'invoice_date' => now()->toDateString(), 'items' => [[
            'medicine_id' => $medicine->id, 'batch_no' => 'B1', 'expiry' => now()->addYear()->format('Y-m'), 'units_per_pack' => 10, 'packs' => 10, 'pack_price' => 15, 'pack_mrp' => 28, 'gst_percent' => 12,
        ]]])->assertCreated()->json();
        $sale = $this->postJson('/api/pharmacy/sales', ['customer_name' => 'Walk-in', 'paid' => 0, 'payment_mode' => 'cash', 'items' => [['stock_batch_id' => $invoice['batches'][0]['id'], 'quantity' => 2]]])->assertCreated()->json('id');
        $this->deleteJson("/api/pharmacy/sales/{$sale}", ['reason' => 'Made by mistake'])->assertForbidden();
    }

    public function test_signature_picture_and_doctor_hours_are_saved_with_the_person(): void
    {
        Storage::fake('local');
        $doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson("/api/users/{$doctor->id}/signature")->assertNotFound();
        $this->post("/api/staff/{$doctor->id}/signature", ['file' => UploadedFile::fake()->create('s.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/staff/{$doctor->id}/signature", ['file' => UploadedFile::fake()->image('s.png', 300, 100)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('has_signature', true)->assertJsonMissingPath('signature_path');

        // Anyone signed in can fetch it for a print.
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->get("/api/users/{$doctor->id}/signature")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->post("/api/staff/{$doctor->id}/signature", ['file' => UploadedFile::fake()->image('s.png')], ['Accept' => 'application/json'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $hours = ['mon' => [['from' => '10:30', 'to' => '14:00'], ['from' => '18:00', 'to' => '21:00']], 'tue' => [], 'sat' => [['from' => '10:30', 'to' => '13:00']]];
        $this->putJson("/api/staff/{$doctor->id}", $this->details($doctor, ['availability' => $hours]))->assertOk()
            ->assertJsonPath('availability.mon.1.to', '21:00')->assertJsonPath('availability.sat.0.from', '10:30')->assertJsonMissingPath('availability.tue');
        $this->putJson("/api/staff/{$doctor->id}", $this->details($doctor, ['availability' => ['mon' => [['from' => '14:00', 'to' => '10:00']]]]))->assertStatus(422);
        $this->getJson('/api/doctors')->assertOk()->assertJsonPath('0.availability.sat.0.to', '13:00');

        $this->deleteJson("/api/staff/{$doctor->id}/signature")->assertOk()->assertJsonPath('has_signature', false);
        $this->assertSame([], Storage::disk('local')->allFiles('staff-signatures'));
    }
}
