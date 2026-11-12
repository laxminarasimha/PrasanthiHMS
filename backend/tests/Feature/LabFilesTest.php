<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LabFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
    }

    public function test_result_files_can_be_attached_opened_and_removed(): void
    {
        $test = $this->postJson('/api/lab/tests', ['name' => 'ECG', 'kind' => 'text', 'price' => 300])->assertCreated()->json();
        $order = $this->postJson('/api/lab/orders', ['patient_id' => Patient::factory()->create()->id, 'test_ids' => [$test['id']]])->assertCreated()->json();
        $other = $this->postJson('/api/lab/orders', ['patient_id' => Patient::factory()->create()->id, 'test_ids' => [$test['id']]])->assertCreated()->json();
        $url = "/api/lab/orders/{$order['id']}/files";

        $this->post($url, ['file' => UploadedFile::fake()->create('virus.exe', 10)], ['Accept' => 'application/json'])->assertStatus(422);
        // A test from another order cannot be named.
        $this->post($url, ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), 'lab_order_item_id' => $other['items'][0]['id']], ['Accept' => 'application/json'])->assertStatus(422);

        $saved = $this->post($url, ['file' => UploadedFile::fake()->create('ecg strip.pdf', 120, 'application/pdf'), 'lab_order_item_id' => $order['items'][0]['id']], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(1, 'files')
            ->assertJsonPath('files.0.title', 'ecg strip')->assertJsonPath('files.0.original_name', 'ecg strip.pdf')
            ->assertJsonMissingPath('files.0.path')->json('files.0');

        $this->getJson("/api/lab/orders/{$order['id']}")->assertJsonCount(1, 'files');
        $this->get("/api/lab/files/{$saved['id']}/download")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Anyone signed in can open it; only lab staff can add or remove.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->get("/api/lab/files/{$saved['id']}/download")->assertOk();
        $this->post($url, ['file' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson("/api/lab/files/{$saved['id']}")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
        $this->deleteJson("/api/lab/files/{$saved['id']}")->assertOk()->assertJsonCount(0, 'files');
        $this->assertSame([], Storage::disk('local')->allFiles('lab-files'));
    }

    public function test_technician_signature_pictures_are_kept_when_other_preferences_are_saved(): void
    {
        $this->getJson('/api/lab/settings')->assertOk()->assertJsonPath('technician_1_signature', null)->assertJsonPath('tabular_print', false);

        $this->post('/api/lab/settings/signature', ['slot' => 3, 'file' => UploadedFile::fake()->image('s.png', 300, 100)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/lab/settings/signature', ['slot' => 1, 'file' => UploadedFile::fake()->create('s.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);

        $settings = $this->post('/api/lab/settings/signature', ['slot' => 1, 'file' => UploadedFile::fake()->image('s.png', 300, 100)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertStringStartsWith('data:image/png;base64,', $settings['technician_1_signature']);
        $this->assertNull($settings['technician_2_signature']);
        $this->assertArrayNotHasKey('technician_1_signature_path', $settings);
        $this->assertCount(1, Storage::disk('local')->allFiles('lab-signatures'));

        // Saving the form (which sends the picture back as text) must not lose or overwrite it.
        $saved = $this->putJson('/api/lab/settings', ['technician_1' => 'K. Sample', 'tabular_print' => true] + $settings)->assertOk()->json();
        $this->assertSame($settings['technician_1_signature'], $saved['technician_1_signature']);
        $this->assertTrue($saved['tabular_print']);

        // A new picture replaces the old file; removing it deletes the file.
        $this->post('/api/lab/settings/signature', ['slot' => 1, 'file' => UploadedFile::fake()->image('t.jpg', 200, 80)], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(1, Storage::disk('local')->allFiles('lab-signatures'));
        $this->deleteJson('/api/lab/settings/signature/1')->assertOk()->assertJsonPath('technician_1_signature', null)->assertJsonPath('technician_1', 'K. Sample');
        $this->assertCount(0, Storage::disk('local')->allFiles('lab-signatures'));

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->post('/api/lab/settings/signature', ['slot' => 1, 'file' => UploadedFile::fake()->image('s.png')], ['Accept' => 'application/json'])->assertForbidden();
    }
}
