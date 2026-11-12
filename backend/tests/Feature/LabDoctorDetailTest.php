<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LabDoctorDetailTest extends TestCase
{
    use RefreshDatabase;

    private function labTest(string $name, float $price, array $parameters = [['name' => 'Value']], array $extra = []): array
    {
        return $this->postJson('/api/lab/tests', $extra + ['name' => $name, 'price' => $price, 'parameters' => $parameters])->assertCreated()->json();
    }

    private function asLab(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
    }

    // ---- Lab ----

    public function test_report_templates_can_have_sub_headings_default_comment_and_written_reports(): void
    {
        $this->asLab();

        $cbp = $this->labTest('Complete Blood Picture', 300, [
            ['name' => 'Differential count', 'is_heading' => true],
            ['name' => 'Neutrophils', 'unit' => '%', 'reference_range' => '40 - 75'],
        ], ['default_comment' => 'Correlate clinically.']);
        $this->assertTrue($cbp['parameters'][0]['is_heading']);

        // A written report needs no parameters.
        $ecg = $this->postJson('/api/lab/tests', ['name' => 'ECG', 'kind' => 'text', 'price' => 300])->assertCreated()->assertJsonPath('kind', 'text')->assertJsonCount(0, 'parameters')->json();

        $order = $this->postJson('/api/lab/orders', ['patient_id' => Patient::factory()->create()->id, 'test_ids' => [$cbp['id'], $ecg['id']]])->assertCreated()->json();
        $byName = collect($order['items'])->keyBy('test_name');
        $this->assertSame('Correlate clinically.', $byName['Complete Blood Picture']['remarks']);
        $this->assertTrue($byName['Complete Blood Picture']['results'][0]['is_heading']);
        $this->assertSame('text', $byName['ECG']['kind']);

        $this->putJson("/api/lab/orders/{$order['id']}/items/{$byName['ECG']['id']}", ['complete' => true, 'report_text' => 'Normal sinus rhythm.', 'results' => []])
            ->assertOk()->assertJsonPath('status', 'partial');
        $this->assertSame('Normal sinus rhythm.', collect($this->getJson("/api/lab/orders/{$order['id']}")->json('items'))->firstWhere('test_name', 'ECG')['report_text']);
    }

    public function test_each_test_moves_from_booked_to_collected_to_reported_to_approved(): void
    {
        $this->asLab();
        $order = $this->postJson('/api/lab/orders', ['patient_id' => Patient::factory()->create()->id, 'test_ids' => [$this->labTest('RBS', 80)['id']]])->json();
        $item = $order['items'][0];
        $url = "/api/lab/orders/{$order['id']}/items/{$item['id']}";
        $this->assertSame('booked', $item['stage']);

        $this->putJson("{$url}/approve", ['approved' => true])->assertStatus(422)->assertJsonValidationErrors('approved');
        $this->putJson("{$url}/collect", ['collected' => true])->assertOk()->assertJsonPath('items.0.stage', 'collected');
        $this->putJson($url, ['complete' => true, 'results' => [['id' => $item['results'][0]['id'], 'value' => '110']]])->assertOk()->assertJsonPath('items.0.stage', 'reported');
        $this->putJson("{$url}/collect", ['collected' => false])->assertStatus(422);
        $this->putJson("{$url}/approve", ['approved' => true])->assertOk()->assertJsonPath('items.0.stage', 'approved')->assertJsonPath('status', 'completed');

        // An approved result is locked until it is reopened, which also withdraws the approval.
        $this->putJson($url, ['complete' => true, 'results' => [['id' => $item['results'][0]['id'], 'value' => '999']]])->assertStatus(422);
        $this->putJson($url, ['complete' => false, 'results' => []])->assertOk()->assertJsonPath('items.0.stage', 'collected')->assertJsonPath('items.0.approved_at', null);
    }

    public function test_packages_bill_one_price_and_do_not_repeat_a_test_ordered_twice(): void
    {
        $this->asLab();
        $rbs = $this->labTest('RBS', 80);
        $creatinine = $this->labTest('Serum Creatinine', 150);
        $tsh = $this->labTest('TSH', 350);

        $this->postJson('/api/lab/packages', ['name' => 'Basic check', 'price' => 200, 'test_ids' => [$rbs['id']]])->assertStatus(422)->assertJsonValidationErrors('test_ids');
        $package = $this->postJson('/api/lab/packages', ['name' => 'Basic check', 'price' => 200, 'test_ids' => [$rbs['id'], $creatinine['id']]])->assertCreated()->assertJsonCount(2, 'tests')->json();
        $this->getJson('/api/lab/packages')->assertOk()->assertJsonCount(1);

        // The package (200) plus TSH (350). RBS is in the package, so asking for it again adds nothing.
        $order = $this->postJson('/api/lab/orders', ['patient_id' => Patient::factory()->create()->id, 'package_ids' => [$package['id']], 'test_ids' => [$rbs['id'], $tsh['id']]])
            ->assertCreated()->assertJsonCount(3, 'items')->assertJsonPath('bill.total', 550)->json();
        $this->assertSame('Basic check', collect($order['items'])->firstWhere('test_name', 'RBS')['package_name']);

        $this->postJson('/api/lab/orders', ['patient_id' => Patient::factory()->create()->id])->assertStatus(422)->assertJsonValidationErrors('test_ids');
    }

    public function test_lab_preferences_have_defaults_and_only_lab_roles_can_change_them(): void
    {
        $this->asLab();
        $this->getJson('/api/lab/settings')->assertOk()->assertJsonPath('letterhead_top_mm', 40)->assertJsonPath('allow_print_without_payment', true);

        $settings = [
            'lab_name' => 'Prasanthi Diagnostics', 'technician_1' => 'Sample Tech', 'letterhead_top_mm' => 55, 'letterhead_bottom_mm' => 25,
            'each_test_on_new_page' => true, 'allow_print_without_payment' => false, 'print_sample_date' => true, 'print_approved_date' => false, 'approval_required' => true,
        ];
        $this->putJson('/api/lab/settings', $settings)->assertOk()->assertJsonPath('lab_name', 'Prasanthi Diagnostics')->assertJsonPath('each_test_on_new_page', true);
        $this->getJson('/api/lab/settings')->assertJsonPath('letterhead_top_mm', 55)->assertJsonPath('approval_required', true);

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->putJson('/api/lab/settings', $settings)->assertForbidden();
        $this->getJson('/api/lab/settings')->assertOk();
    }

    // ---- Doctor ----

    private function visit(User $doctor): Appointment
    {
        return Appointment::create(['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now(), 'status' => 'arrived']);
    }

    public function test_completing_a_visit_remembers_phrases_and_how_medicines_were_given(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($doctor);

        foreach ([1, 2] as $n) {
            $this->putJson('/api/appointments/'.$this->visit($doctor)->id.'/consultation', [
                'complaints' => "Fever x 3 days\nCough", 'diagnosis' => 'Viral fever', 'advice' => 'Plenty of fluids', 'complete' => true,
                'medicines' => [['medicine' => 'Paracetamol 650mg Tablet', 'dose' => '1 tablet', 'frequency' => '1-1-1', 'duration' => "{$n} days"]],
            ])->assertOk();
        }

        $library = $this->getJson('/api/doctor/library')->assertOk()->json();
        $this->assertEqualsCanonicalizing(['Fever x 3 days', 'Cough'], array_column($library['phrases']['complaint'], 'text'));
        $this->assertSame(2, $library['phrases']['diagnosis'][0]['uses']);
        $this->assertSame('Plenty of fluids', $library['phrases']['advice'][0]['text']);
        // The most recent way of giving it wins.
        $this->assertSame('2 days', $library['medicine_defaults'][0]['duration']);
        $this->assertSame('1-1-1', $library['medicine_defaults'][0]['frequency']);

        // Another doctor starts with an empty library.
        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $this->getJson('/api/doctor/library')->assertOk()->assertJsonCount(0, 'phrases')->assertJsonCount(0, 'medicine_defaults');
    }

    public function test_phrases_can_be_added_edited_and_deleted_in_bulk(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));

        $a = $this->postJson('/api/doctor/phrases', ['kind' => 'complaint', 'text' => 'Low backache'])->assertCreated()->json('id');
        $b = $this->postJson('/api/doctor/phrases', ['kind' => 'complaint', 'text' => 'Loose motions'])->assertCreated()->json('id');
        $this->postJson('/api/doctor/phrases', ['kind' => 'complaint', 'text' => 'Low backache'])->assertCreated()->assertJsonPath('id', $a); // no duplicate
        $this->postJson('/api/doctor/phrases', ['kind' => 'nonsense', 'text' => 'x'])->assertStatus(422);

        $this->putJson("/api/doctor/phrases/{$a}", ['text' => 'Low back pain'])->assertOk()->assertJsonPath('text', 'Low back pain');
        $this->deleteJson('/api/doctor/phrases', ['ids' => [$a, $b]])->assertOk()->assertJsonPath('deleted', 2);
        $this->getJson('/api/doctor/library')->assertJsonCount(0, 'phrases');
    }

    public function test_rx_groups_and_templates_are_saved_per_doctor(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($doctor);
        $medicines = [['medicine' => 'Paracetamol 650mg Tablet', 'frequency' => '1-1-1', 'duration' => '3 days'], ['medicine' => 'Cetirizine 10mg Tablet', 'frequency' => '0-0-1']];

        $group = $this->postJson('/api/doctor/rx-groups', ['name' => 'Basic fever', 'medicines' => $medicines])->assertCreated()->assertJsonCount(2, 'medicines')->json();
        $this->postJson('/api/doctor/rx-groups', ['name' => 'Empty', 'medicines' => []])->assertStatus(422)->assertJsonValidationErrors('medicines');
        $this->putJson("/api/doctor/rx-groups/{$group['id']}", ['name' => 'Fever', 'medicines' => [$medicines[0]]])->assertOk()->assertJsonPath('name', 'Fever')->assertJsonCount(1, 'medicines');

        $template = $this->postJson('/api/doctor/templates', ['name' => 'Viral fever', 'content' => ['complaints' => 'Fever', 'diagnosis' => 'Viral fever', 'advice' => 'Rest', 'medicines' => $medicines]])
            ->assertCreated()->assertJsonPath('content.diagnosis', 'Viral fever')->json();

        $this->getJson('/api/doctor/library')->assertJsonCount(1, 'rx_groups')->assertJsonCount(1, 'templates');

        // Another doctor cannot change or delete them.
        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $this->deleteJson("/api/doctor/rx-groups/{$group['id']}")->assertForbidden();
        $this->deleteJson("/api/doctor/templates/{$template['id']}")->assertForbidden();

        Sanctum::actingAs($doctor);
        $this->deleteJson("/api/doctor/rx-groups/{$group['id']}")->assertOk();
        $this->deleteJson("/api/doctor/templates/{$template['id']}")->assertOk();
    }

    public function test_doctor_chooses_which_pad_sections_to_hide(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($doctor);

        $this->putJson('/api/doctor/pad-sections', ['hidden' => ['past_medication', 'refer_to']])->assertOk();
        $this->getJson('/api/me')->assertJsonPath('pad_hidden_sections', ['past_medication', 'refer_to']);
        $this->putJson('/api/doctor/pad-sections', ['hidden' => ['diagnosis']])->assertStatus(422); // core sections cannot be hidden
    }

    public function test_consults_list_shows_completed_visits_in_a_date_range_for_that_doctor(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $other = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($other);
        $this->putJson('/api/appointments/'.$this->visit($other)->id.'/consultation', ['complete' => true])->assertOk();
        Sanctum::actingAs($doctor);
        $this->putJson('/api/appointments/'.$this->visit($doctor)->id.'/consultation', ['diagnosis' => 'Gastritis', 'complete' => true])->assertOk();
        $this->putJson('/api/appointments/'.$this->visit($doctor)->id.'/consultation', ['complaints' => 'draft only'])->assertOk();

        $this->getJson('/api/doctor/consults')->assertOk()->assertJsonCount(1)->assertJsonPath('0.diagnosis', 'Gastritis');
        $this->getJson('/api/doctor/consults?from='.now()->subDays(9)->toDateString().'&to='.now()->subDays(2)->toDateString())->assertOk()->assertJsonCount(0);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/doctor/consults')->assertOk()->assertJsonCount(2);
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->getJson('/api/doctor/library')->assertForbidden();
    }
}
