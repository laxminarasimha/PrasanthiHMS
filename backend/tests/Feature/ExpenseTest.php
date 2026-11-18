<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DayClose;
use App\Models\Expense;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Stockist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private function expense(array $overrides = [])
    {
        return $this->postJson('/api/expenses', $overrides + [
            'spent_on' => today()->toDateString(), 'category' => 'Tea and refreshments', 'paid_to' => 'Canteen', 'amount' => 50, 'mode' => 'cash',
        ]);
    }

    /** A ₹100 purchase invoice from a supplier, with nothing paid on it yet. */
    private function invoice(): int
    {
        return $this->postJson('/api/pharmacy/purchases', [
            'stockist_id' => Stockist::create(['name' => 'Sample Distributors'])->id, 'invoice_no' => 'INV-1', 'invoice_date' => today()->toDateString(),
            'items' => [[
                'medicine_id' => Medicine::create(['name' => 'Paracetamol 650mg Tablet', 'gst_percent' => 0, 'units_per_pack' => 10])->id,
                'batch_no' => 'B001', 'expiry' => now()->addYear()->format('Y-m'), 'units_per_pack' => 10, 'packs' => 10, 'pack_price' => 10, 'pack_mrp' => 20, 'gst_percent' => 0,
            ]],
        ])->assertCreated()->json('id');
    }

    public function test_the_cashier_writes_down_expenses_and_can_correct_or_cancel_one(): void
    {
        // Not the pharmacist's or the lab's work.
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->expense()->assertForbidden();
        $this->getJson('/api/expenses')->assertForbidden();

        Sanctum::actingAs($desk = User::factory()->create(['role' => 'frontdesk']));
        $this->postJson('/api/expenses', ['spent_on' => today()->addDay()->toDateString(), 'amount' => 0, 'mode' => 'barter'])
            ->assertStatus(422)->assertJsonValidationErrors(['spent_on', 'category', 'amount', 'mode']);

        $tea = $this->expense()->assertCreated()->assertJsonPath('expense_no', 'EX00001')->assertJsonPath('creator.name', $desk->name)->json('id');
        $repair = $this->expense(['category' => 'Repairs and maintenance', 'paid_to' => 'Ravi Electricals', 'amount' => 1200, 'mode' => 'upi', 'reference' => 'UPI 4411'])->assertCreated()->json('id');
        $this->expense(['category' => 'Oxygen cylinders', 'amount' => 300])->assertCreated();

        // The list for the day, with what went out in all, in cash, and under each heading. A heading of the hospital's own is offered next time.
        $list = $this->getJson('/api/expenses')->assertOk()->assertJsonCount(3, 'rows')
            ->assertJsonPath('paid_out.expenses', 1550)->assertJsonPath('paid_out.expenses_cash', 350)->assertJsonPath('paid_out.by_mode.upi', 1200)
            ->assertJsonPath('paid_out.by_category.Repairs and maintenance', 1200)->json();
        $this->assertContains('Oxygen cylinders', $list['categories']);
        $this->assertContains('Staff salary or advance', $list['categories']);
        $this->getJson('/api/expenses?q=ravi')->assertOk()->assertJsonCount(1, 'rows');

        // A wrong amount is corrected, and what it said before is kept.
        $this->putJson("/api/expenses/{$tea}", ['spent_on' => today()->toDateString(), 'category' => 'Tea and refreshments', 'paid_to' => 'Canteen', 'amount' => 80, 'mode' => 'cash'])
            ->assertOk()->assertJsonPath('amount', 80);
        $this->assertStringContainsString('₹50 by cash', AuditLog::where('action', 'expense.changed')->sole()->summary);

        // An entry made by mistake is cancelled with a reason: it stays in the list and stops counting.
        $this->postJson("/api/expenses/{$repair}/cancel", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/expenses/{$repair}/cancel", ['reason' => 'Entered twice'])->assertOk()->assertJsonPath('cancel_reason', 'Entered twice')->assertJsonPath('canceller.name', $desk->name);
        $this->postJson("/api/expenses/{$repair}/cancel", ['reason' => 'Again'])->assertStatus(422);
        $this->putJson("/api/expenses/{$repair}", ['spent_on' => today()->toDateString(), 'category' => 'Rent', 'amount' => 1, 'mode' => 'cash'])->assertStatus(422);
        $this->getJson('/api/expenses')->assertJsonCount(3, 'rows')->assertJsonPath('paid_out.expenses', 380)->assertJsonPath('paid_out.by_mode.cash', 380);
        $this->assertSame(3, AuditLog::where('action', 'expense.added')->count());
    }

    public function test_cash_paid_out_comes_off_the_cash_expected_at_the_day_close(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        // ₹200 taken in cash at the front desk.
        $visit = $this->postJson('/api/appointments', [
            'patient_id' => Patient::factory()->create()->id, 'doctor_id' => User::factory()->create(['role' => 'doctor'])->id,
            'scheduled_at' => now()->format('Y-m-d H:i'), 'service_id' => Service::factory()->create(['price' => 200])->id,
        ])->assertCreated()->json();
        $this->postJson("/api/bills/{$visit['bills'][0]['id']}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'cash'])->assertCreated();
        $this->getJson('/api/day-close')->assertOk()->assertJsonPath('expected_cash', 200)->assertJsonPath('cash_collected', 200)->assertJsonPath('paid_out.cash', 0);

        // ₹50 of tea in cash, ₹30 of courier by UPI, ₹40 in cash to a medicine supplier, and an expense dated yesterday.
        $this->expense()->assertCreated();
        $this->expense(['category' => 'Transport and courier', 'amount' => 30, 'mode' => 'upi'])->assertCreated();
        $this->expense(['spent_on' => today()->subDay()->toDateString(), 'amount' => 999])->assertCreated();
        $this->postJson("/api/pharmacy/purchases/{$this->invoice()}/payments", ['amount' => 40, 'mode' => 'cash'])->assertOk();

        // Only today's cash comes out of today's drawer: 200 − 50 − 40.
        $book = $this->getJson('/api/day-close')->assertOk()->assertJsonPath('cash_collected', 200)->assertJsonPath('expected_cash', 110)
            ->assertJsonPath('paid_out.expenses', 80)->assertJsonPath('paid_out.suppliers', 40)->assertJsonPath('paid_out.cash', 90)->json();
        $section = collect($book['sections'])->firstWhere('key', 'paid_out');
        $this->assertSame([2, 1, 0], array_column($section['checks'], 'count'));
        $this->assertSame('Sample Distributors · invoice INV-1', $section['checks'][1]['rows'][0]['text']);
        $this->getJson('/api/day-close?day='.today()->subDay()->toDateString())->assertJsonPath('expected_cash', -999);

        // The period summary carries the same figures.
        $this->getJson('/api/reports/summary')->assertOk()->assertJsonPath('paid_out.total', 120)->assertJsonPath('paid_out.cash', 90);

        // Counting 110 closes the day with no difference to explain. More cannot be kept back than was counted.
        $this->postJson('/api/day-close', ['counted_cash' => 110, 'kept_cash' => 500, 'override_reason' => 'The visit is still open'])->assertStatus(422)->assertJsonValidationErrors('kept_cash');
        $this->postJson('/api/day-close', ['counted_cash' => 110, 'kept_cash' => 60, 'override_reason' => 'The visit is still open'])->assertCreated()
            ->assertJsonPath('closed.variance', 0)->assertJsonPath('closed.expected_cash', 110)->assertJsonPath('closed.kept_cash', 60);

        // The ₹60 left in the drawer is what the next day starts with, so ₹25 of tea next morning leaves ₹35 expected, not a shortage.
        $this->travel(1)->days();
        $this->getJson('/api/day-close')->assertOk()->assertJsonPath('opening_cash', 60)->assertJsonPath('opening_from', today()->subDay()->toDateString())->assertJsonPath('expected_cash', 60);
        $this->expense(['amount' => 25])->assertCreated();
        $this->getJson('/api/day-close')->assertJsonPath('expected_cash', 35);
        $this->postJson('/api/day-close', ['counted_cash' => 35])->assertCreated()->assertJsonPath('closed.opening_cash', 60)->assertJsonPath('closed.kept_cash', 0);

        // Nothing was kept that time, so the day after starts from nothing.
        $this->travel(1)->days();
        $this->getJson('/api/day-close')->assertJsonPath('opening_cash', 0)->assertJsonPath('opening_from', null)->assertJsonPath('expected_cash', 0);
    }

    public function test_nothing_can_be_paid_out_of_a_day_whose_accounts_are_closed(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $yesterday = today()->subDay()->toDateString();
        $old = $this->expense(['spent_on' => $yesterday])->assertCreated()->json('id');
        $invoice = $this->invoice();
        DayClose::create(['day' => $yesterday, 'closed_by_name' => 'Admin', 'closed_at' => now(), 'expected_cash' => -50, 'counted_cash' => 0, 'variance' => 50, 'snapshot' => []]);

        // Not a new expense, not a change or a cancellation of one, not moving one into it, and not a supplier payment dated into it.
        $this->expense(['spent_on' => $yesterday])->assertStatus(423);
        $this->putJson("/api/expenses/{$old}", ['spent_on' => $yesterday, 'category' => 'Tea and refreshments', 'amount' => 5, 'mode' => 'cash'])->assertStatus(423);
        $this->postJson("/api/expenses/{$old}/cancel", ['reason' => 'Not really spent'])->assertStatus(423);
        $today = $this->expense()->assertCreated()->json('id');
        $this->putJson("/api/expenses/{$today}", ['spent_on' => $yesterday, 'category' => 'Tea and refreshments', 'amount' => 50, 'mode' => 'cash'])->assertStatus(423);
        $this->postJson("/api/pharmacy/purchases/{$invoice}/payments", ['amount' => 40, 'mode' => 'cash', 'paid_on' => $yesterday])->assertStatus(423);

        $this->assertSame(50.0, Expense::find($old)->amount);
        $this->assertNull(Expense::find($old)->cancelled_at);
        // The heading of an old entry can still be put right: that moves no money.
        $this->putJson("/api/expenses/{$old}", ['spent_on' => $yesterday, 'category' => 'Housekeeping and laundry', 'amount' => 50, 'mode' => 'cash'])->assertOk();
        // And today is open as usual.
        $this->postJson("/api/pharmacy/purchases/{$invoice}/payments", ['amount' => 40, 'mode' => 'cash'])->assertOk();
    }
}
