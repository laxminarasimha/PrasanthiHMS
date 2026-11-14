<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\DayClose;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DayCloseTest extends TestCase
{
    use RefreshDatabase;

    /** A booked visit with a ₹200 bill (₹150 paid in cash) and an unpaid ₹300 lab order. */
    private function busyDay(): array
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::factory()->create();
        $visit = $this->postJson('/api/appointments', [
            'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i'),
            'service_id' => Service::factory()->create(['price' => 200])->id,
        ])->assertCreated()->json();
        $this->postJson("/api/bills/{$visit['bills'][0]['id']}/payments", ['type' => 'payment', 'amount' => 150, 'mode' => 'cash'])->assertCreated();

        $test = LabTest::create(['name' => 'CBP', 'price' => 300]);
        $test->parameters()->create(['name' => 'Haemoglobin']);
        $labBill = $this->postJson('/api/lab/orders', ['patient_id' => $patient->id, 'test_ids' => [$test->id]])->assertCreated()->json('bill.id');

        return ['patient' => $patient, 'visit' => $visit['id'], 'bill' => $visit['bills'][0]['id'], 'labBill' => $labBill];
    }

    private function check(array $book, string $key): array
    {
        return collect($book['sections'])->flatMap(fn ($s) => $s['checks'])->firstWhere('key', $key);
    }

    public function test_the_day_book_lists_unfinished_work_and_the_cash_expected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $made = $this->busyDay();

        $book = $this->getJson('/api/day-close')->assertOk()
            ->assertJsonPath('day', today()->toDateString())
            ->assertJsonPath('expected_cash', 150)
            ->assertJsonPath('blocking', 3)
            ->assertJsonPath('closed', null)
            ->assertJsonPath('can_close', true)
            ->assertJsonPath('departments.consultation.due', 50)
            ->assertJsonPath('departments.lab.due', 300)
            ->assertJsonCount(7, 'recent')
            ->json();

        $open = $this->check($book, 'appointments_open');
        $this->assertTrue($open['blocking']);
        $this->assertSame(1, $open['count']);
        $this->assertSame("/patients/{$made['patient']->id}", $open['rows'][0]['to']);
        $this->assertSame(1, $this->check($book, 'desk_dues')['count']);
        $this->assertSame(1, $this->check($book, 'lab_dues')['count']);
        $this->assertSame(0, $this->check($book, 'lab_unbilled')['count']);
        $this->assertFalse($this->check($book, 'lab_pending')['blocking']);
        $this->assertSame(1, $this->check($book, 'lab_pending')['count']);

        // A refund shows under the money section and comes off the cash expected.
        $this->postJson("/api/bills/{$made['bill']}/payments", ['type' => 'refund', 'amount' => 20, 'mode' => 'cash'])->assertCreated();
        $book = $this->getJson('/api/day-close')->assertJsonPath('expected_cash', 130)->assertJsonPath('departments.consultation.refunded', 20)->json();
        $this->assertSame(1, $this->check($book, 'refunds')['count']);

        // A day that has not come cannot be looked at for closing, and the desk cannot see the screen.
        $this->getJson('/api/day-close?day='.now()->addDay()->toDateString())->assertStatus(422);
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/day-close')->assertForbidden();
    }

    public function test_closing_needs_reasons_and_locks_the_days_money(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $made = $this->busyDay();

        // Unfinished work and a cash difference both have to be explained.
        $this->postJson('/api/day-close', [])->assertStatus(422)->assertJsonValidationErrors('counted_cash');
        $this->postJson('/api/day-close', ['counted_cash' => 100])->assertStatus(422)->assertJsonValidationErrors(['variance_note', 'override_reason']);
        $this->postJson('/api/day-close', ['counted_cash' => 150])->assertStatus(422)->assertJsonValidationErrors('override_reason')->assertJsonMissingValidationErrors('variance_note');
        $this->assertSame(0, DayClose::count());

        $this->postJson('/api/day-close', ['counted_cash' => 140, 'variance_note' => 'Ten rupees given as change by mistake', 'override_reason' => 'Patient will pay the balance tomorrow'])
            ->assertCreated()
            ->assertJsonPath('closed.closed_by_name', $admin->name)
            ->assertJsonPath('closed.expected_cash', 150)
            ->assertJsonPath('closed.variance', -10)
            ->assertJsonPath('closed.exceptions', 3)
            ->assertJsonPath('can_close', false)
            ->assertJsonPath('can_reopen', true);
        $this->assertSame(3, DayClose::first()->snapshot['blocking']);
        $this->assertSame(1, AuditLog::where('action', 'day.closed')->count());
        $this->postJson('/api/day-close', ['counted_cash' => 150, 'override_reason' => 'Closing twice'])->assertStatus(422)->assertJsonValidationErrors('day');

        // Nothing that carries money can be added to or changed in the closed day.
        $this->postJson("/api/bills/{$made['bill']}/payments", ['type' => 'payment', 'amount' => 50, 'mode' => 'cash'])->assertStatus(423);
        $this->putJson("/api/bills/{$made['labBill']}", ['discount' => 300])->assertStatus(423);
        $this->postJson('/api/lab/orders', ['patient_id' => $made['patient']->id, 'test_ids' => [LabTest::first()->id]])->assertStatus(423);
        $this->assertEquals(150, Bill::find($made['bill'])->paid);
        $this->assertEquals(300, Bill::find($made['labBill'])->total);
        // Work that is not money goes on.
        $this->putJson("/api/appointments/{$made['visit']}", ['status' => 'arrived'])->assertOk();

        // Only an admin reopens, and has to say why.
        $close = DayClose::first();
        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $this->getJson('/api/day-close')->assertOk()->assertJsonPath('can_reopen', false);
        $this->postJson("/api/day-close/{$close->id}/reopen", ['reason' => 'Wrong amount entered'])->assertForbidden();
        Sanctum::actingAs($admin);
        $this->postJson("/api/day-close/{$close->id}/reopen", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/day-close/{$close->id}/reopen", ['reason' => 'Balance was paid after closing'])->assertOk()
            ->assertJsonPath('closed', null)->assertJsonPath('can_close', true)->assertJsonPath('history.0.reopen_reason', 'Balance was paid after closing');
        $this->assertSame(1, AuditLog::where('action', 'day.reopened')->count());

        // Open again: the money can be taken, and the day closed a second time. Both closings stay on record.
        $this->postJson("/api/bills/{$made['bill']}/payments", ['type' => 'payment', 'amount' => 50, 'mode' => 'cash'])->assertCreated();
        $this->postJson('/api/day-close', ['counted_cash' => 200, 'override_reason' => 'Lab bill to be paid on collection of report'])
            ->assertCreated()->assertJsonPath('closed.exceptions', 2)->assertJsonCount(2, 'history');
    }

    public function test_a_closed_day_keeps_its_bills_but_later_days_carry_on(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->travelTo(now()->subDay()->setTime(11, 0));
        $made = $this->busyDay();
        $this->postJson('/api/day-close', ['counted_cash' => 150, 'override_reason' => 'Balances to be paid tomorrow'])->assertCreated();
        $this->travelBack();

        $yesterday = now()->subDay()->toDateString();
        $this->getJson('/api/day-close')->assertOk()->assertJsonPath('closed', null)->assertJsonPath('recent.0.day', $yesterday)->assertJsonPath('recent.0.closed', true);
        $this->getJson("/api/day-close?day={$yesterday}")->assertOk()->assertJsonPath('closed.counted_cash', 150);

        // Yesterday's bill cannot be made worth something else...
        $this->putJson("/api/bills/{$made['labBill']}", ['discount' => 100])->assertStatus(423);
        // ...but the money owed on it can be collected today, and counts as today's cash.
        $this->postJson("/api/bills/{$made['labBill']}/payments", ['type' => 'payment', 'amount' => 300, 'mode' => 'cash'])->assertCreated();
        $this->getJson('/api/day-close')->assertJsonPath('expected_cash', 300);
        $this->getJson("/api/day-close?day={$yesterday}")->assertJsonPath('expected_cash', 150);
        // And today's own work is not held up.
        $this->postJson('/api/lab/orders', ['patient_id' => $made['patient']->id, 'test_ids' => [LabTest::first()->id]])->assertCreated();
    }
}
