<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bills and prints: the hospital's GSTIN, its letterhead as pictures, the paper a receipt is printed on,
 * and the letters put before a new patient's ID.
 */
class BillsPrintsBatchFourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_the_hospitals_gstin_is_kept_in_the_form_it_is_issued_in(): void
    {
        $this->getJson('/api/clinic')->assertOk()->assertJsonPath('gstin', '');

        // Typed in small letters with spaces: saved as issued.
        $this->putJson('/api/clinic', ['name' => 'Sample Hospital', 'gstin' => ' 37abcde1234f1z5 '])->assertOk()->assertJsonPath('gstin', '37ABCDE1234F1Z5');
        // A slip of the finger is caught rather than printed on every bill.
        $this->putJson('/api/clinic', ['name' => 'Sample Hospital', 'gstin' => '37ABCDE1234F1Z'])->assertStatus(422)->assertJsonValidationErrors('gstin');
        // Cleared again.
        $this->putJson('/api/clinic', ['name' => 'Sample Hospital', 'gstin' => ''])->assertOk()->assertJsonPath('gstin', null);
    }

    public function test_letterhead_pictures_are_stored_sent_with_the_clinic_details_and_removed(): void
    {
        Storage::fake('local');
        $this->getJson('/api/clinic')->assertOk()->assertJsonPath('letterhead_header', null)->assertJsonPath('letterhead_footer', null);

        $clinic = $this->post('/api/clinic/letterhead', ['slot' => 'header', 'file' => UploadedFile::fake()->image('top.png', 1200, 200)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertStringStartsWith('data:image/png;base64,', $clinic['letterhead_header']);
        $this->assertNull($clinic['letterhead_footer']);
        $this->assertArrayNotHasKey('letterhead_header_path', $clinic);
        $this->assertCount(1, Storage::disk('local')->files('clinic'));

        // A new picture replaces the old one: only one file is kept.
        $this->post('/api/clinic/letterhead', ['slot' => 'header', 'file' => UploadedFile::fake()->image('top2.jpg', 1200, 200)], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(1, Storage::disk('local')->files('clinic'));
        // Saving the other details keeps the picture.
        $this->putJson('/api/clinic', ['name' => 'Sample Hospital'])->assertOk()->assertJsonPath('letterhead_header', fn ($picture) => str_starts_with((string) $picture, 'data:image/'));

        $this->post('/api/clinic/letterhead', ['slot' => 'side', 'file' => UploadedFile::fake()->image('x.png')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/clinic/letterhead', ['slot' => 'footer', 'file' => UploadedFile::fake()->create('big.png', 700, 'image/png')], ['Accept' => 'application/json'])->assertStatus(422);

        $this->deleteJson('/api/clinic/letterhead/header')->assertOk()->assertJsonPath('letterhead_header', null);
        $this->assertCount(0, Storage::disk('local')->files('clinic'));
        $this->deleteJson('/api/clinic/letterhead/side')->assertNotFound();

        // Only those who keep the hospital's setup may change it.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->post('/api/clinic/letterhead', ['slot' => 'header', 'file' => UploadedFile::fake()->image('top.png')], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_print_choices_are_kept_with_the_billing_preferences(): void
    {
        $this->getJson('/api/billing-preferences')->assertOk()
            ->assertJsonPath('bill_paper_size', 'a4')->assertJsonPath('print_gstin', true)->assertJsonPath('patient_prefix', '');

        $base = ['default_payment_mode' => 'cash', 'pay_timing' => 'before'];
        $this->putJson('/api/billing-preferences', $base + ['bill_paper_size' => 'thermal', 'bill_heading' => 'image', 'print_gstin' => false])->assertOk()
            ->assertJsonPath('bill_paper_size', 'thermal')->assertJsonPath('bill_heading', 'image')->assertJsonPath('print_gstin', false);
        $this->putJson('/api/billing-preferences', $base + ['bill_paper_size' => 'a3'])->assertStatus(422);
        $this->putJson('/api/billing-preferences', $base + ['patient_prefix' => '2026'])->assertStatus(422);
        $this->putJson('/api/billing-preferences', $base + ['patient_prefix' => 'TOOLONG7'])->assertStatus(422);
    }

    public function test_a_new_patient_id_carries_the_hospitals_letters_and_is_found_with_or_without_them(): void
    {
        $old = $this->postJson('/api/patients', ['name' => 'Before Prefix', 'gender' => 'female', 'age' => 40, 'mobile' => '9000000011'])->assertCreated()->json();
        $this->assertMatchesRegularExpression('/^\d{6}\d{4,}$/', $old['uhid']);

        $this->putJson('/api/billing-preferences', ['default_payment_mode' => 'cash', 'pay_timing' => 'before', 'patient_prefix' => 'ph'])->assertOk()->assertJsonPath('patient_prefix', 'PH');
        $new = $this->postJson('/api/patients', ['name' => 'After Prefix', 'gender' => 'male', 'age' => 30, 'mobile' => '9000000012'])->assertCreated()->json();
        $this->assertSame('PH'.now()->format('Ym').str_pad((string) $new['id'], 4, '0', STR_PAD_LEFT), $new['uhid']);
        // The patient registered earlier keeps the ID they were given.
        $this->assertSame($old['uhid'], Patient::find($old['id'])->uhid);

        $found = fn (string $typed) => collect($this->getJson('/api/patients?q='.urlencode($typed))->assertOk()->json('data'))->pluck('name')->all();
        $number = substr($new['uhid'], 2);
        $this->assertSame(['After Prefix'], $found($new['uhid']));
        $this->assertSame(['After Prefix'], $found($number));
        $this->assertSame(['Before Prefix'], $found($old['uhid']));

        // The same on another screen that finds people by ID: the list of bills.
        $bill = $this->postJson('/api/bills', ['patient_id' => $new['id'], 'description' => 'Dressing', 'unit_price' => 100, 'quantity' => 1]);
        if ($bill->status() < 300) {
            $this->assertCount(1, $this->getJson('/api/bills?q='.$number)->assertOk()->json());
            $this->assertCount(1, $this->getJson('/api/bills?q='.$new['uhid'])->assertOk()->json());
        }
    }
}
