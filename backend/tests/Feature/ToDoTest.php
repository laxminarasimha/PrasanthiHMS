<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StaffTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Each person's To do screen: the work waiting for them, and the tasks written for them. */
class ToDoTest extends TestCase
{
    use RefreshDatabase;

    private function items(array $screen): array
    {
        return collect($screen['waiting'])->flatMap(fn ($section) => collect($section['items'])->mapWithKeys(fn ($i) => [$section['key'].'.'.$i['key'] => $i]))->all();
    }

    public function test_a_person_writes_their_own_tasks_and_ticks_them_off(): void
    {
        Sanctum::actingAs($desk = User::factory()->create(['role' => 'frontdesk']));

        $this->postJson('/api/tasks', ['title' => ''])->assertStatus(422)->assertJsonValidationErrors('title');
        $task = $this->postJson('/api/tasks', ['title' => 'Ring the oxygen supplier', 'due_on' => today()->toDateString(), 'important' => true])
            ->assertCreated()->assertJsonPath('0.assignee.id', $desk->id)->assertJsonPath('0.giver.id', $desk->id)->json('0.id');
        $this->postJson('/api/tasks', ['title' => 'Tidy the records shelf'])->assertCreated();
        $late = $this->postJson('/api/tasks', ['title' => 'Send the lift for service', 'due_on' => today()->subDays(2)->toDateString()])->assertCreated()->json('0.id');

        // Late first, then today's, then the one with no day; the menu counts what is due today or late.
        $screen = $this->getJson('/api/todo')->assertOk()->assertJsonPath('can_give', false)->assertJsonCount(3, 'tasks')->json();
        $this->assertSame([$late, $task], array_slice(array_column($screen['tasks'], 'id'), 0, 2));
        $this->getJson('/api/todo/count')->assertOk()->assertJsonPath('pressing', 2);

        $this->putJson("/api/tasks/{$task}", ['title' => 'Ring the oxygen supplier before noon', 'due_on' => today()->toDateString()])->assertOk()->assertJsonPath('important', false);
        $this->postJson("/api/tasks/{$task}/done", ['note' => 'Coming tomorrow'])->assertOk()->assertJsonPath('task.finisher.id', $desk->id)->assertJsonPath('next', null);
        $this->postJson("/api/tasks/{$task}/done")->assertStatus(422);
        $this->getJson('/api/todo')->assertJsonCount(2, 'tasks')->assertJsonCount(1, 'done')->assertJsonPath('done.0.done_note', 'Coming tomorrow');
        $this->getJson('/api/todo/count')->assertJsonPath('pressing', 1);

        // A tick put by mistake comes off again; a task no longer wanted is withdrawn.
        $this->postJson("/api/tasks/{$task}/reopen")->assertOk()->assertJsonPath('done_at', null);
        $this->deleteJson("/api/tasks/{$late}")->assertNoContent();
        $this->getJson('/api/todo')->assertJsonCount(2, 'tasks')->assertJsonCount(0, 'done');
        $this->assertNotNull(StaffTask::find($late)->cancelled_at);
    }

    public function test_a_repeating_task_comes_back_for_its_next_day(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'nurse']));

        $daily = $this->postJson('/api/tasks', ['title' => 'Check the crash cart', 'due_on' => today()->subDays(3)->toDateString(), 'repeats' => 'daily'])->assertCreated()->json('0.id');
        // Missed for three days and done today: it comes back once, for tomorrow.
        $next = $this->postJson("/api/tasks/{$daily}/done")->assertOk()->assertJsonPath('next.due_on', today()->addDay()->toDateString())->assertJsonPath('next.repeats', 'daily')->json('next.id');
        $this->assertSame(1, StaffTask::open()->count());

        // Taking the tick off takes the next turn away again.
        $this->postJson("/api/tasks/{$daily}/reopen")->assertOk();
        $this->assertNull(StaffTask::find($next));

        // A repeating task written without a day starts today.
        $this->postJson('/api/tasks', ['title' => 'Empty the sharps box', 'repeats' => 'daily'])->assertCreated()->assertJsonPath('0.due_on', today()->toDateString());

        $weekly = $this->postJson('/api/tasks', ['title' => 'Fire extinguisher round', 'due_on' => today()->addDays(2)->toDateString(), 'repeats' => 'weekly'])->json('0.id');
        $this->postJson("/api/tasks/{$weekly}/done")->assertJsonPath('next.due_on', today()->addDays(9)->toDateString());
        // Withdrawn, a repeating task stops.
        $this->deleteJson('/api/tasks/'.StaffTask::open()->where('title', 'Fire extinguisher round')->value('id'))->assertNoContent();
        $this->assertSame(0, StaffTask::open()->where('title', 'Fire extinguisher round')->count());
    }

    public function test_tasks_are_given_only_by_those_allowed_and_each_person_sees_only_their_own(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'manager']);
        $nurse = User::factory()->create(['role' => 'nurse']);
        $desk = User::factory()->create(['role' => 'frontdesk']);
        $ayah = User::factory()->create(['role' => 'staff']);

        // Without the permission a task cannot be put on someone else's list.
        Sanctum::actingAs($desk);
        $this->postJson('/api/tasks', ['title' => 'Do my work', 'for' => [$nurse->id]])->assertForbidden();
        $this->getJson('/api/tasks/people')->assertForbidden();
        $this->getJson('/api/tasks/given')->assertForbidden();
        $own = $this->postJson('/api/tasks', ['title' => 'My own note'])->assertCreated()->json('0.id');

        // A manager gives one task to two people: each gets their own copy.
        Sanctum::actingAs($manager);
        $this->getJson('/api/todo')->assertJsonPath('can_give', true);
        $people = array_column($this->getJson('/api/tasks/people')->assertOk()->json(), 'id');
        $this->assertContains($nurse->id, $people);
        $this->assertNotContains($ayah->id, $people);
        $this->postJson('/api/tasks', ['title' => 'Clean the nebuliser', 'for' => [$ayah->id]])->assertStatus(422)->assertJsonValidationErrors('for');
        $given = $this->postJson('/api/tasks', ['title' => 'Count the linen', 'due_on' => today()->toDateString(), 'for' => [$nurse->id, $desk->id]])->assertCreated()->assertJsonCount(2)->json();
        $this->getJson('/api/todo')->assertJsonCount(0, 'tasks');
        $this->getJson('/api/tasks/given')->assertOk()->assertJsonCount(2);

        // The nurse sees it, can tick it, cannot change or withdraw it, and never sees the desk's own note.
        Sanctum::actingAs($nurse);
        $mine = collect($given)->firstWhere('assigned_to', $nurse->id)['id'];
        $this->getJson('/api/todo')->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.giver.name', $manager->name);
        $this->putJson("/api/tasks/{$mine}", ['title' => 'Something easier'])->assertForbidden();
        $this->deleteJson("/api/tasks/{$mine}")->assertForbidden();
        $this->postJson("/api/tasks/{$own}/done")->assertNotFound();
        $this->postJson("/api/tasks/{$mine}/done")->assertOk();

        // The manager sees it was done and by whom; another manager's list is their own; the admin sees all given tasks.
        Sanctum::actingAs($manager);
        $done = collect($this->getJson('/api/tasks/given')->json())->firstWhere('id', $mine);
        $this->assertSame($nurse->name, $done['finisher']['name']);
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->getJson('/api/tasks/given')->assertJsonCount(0);
        Sanctum::actingAs($admin);
        $this->getJson('/api/tasks/given')->assertJsonCount(2);

        // An own-record login has no To do screen at all.
        Sanctum::actingAs($ayah);
        $this->getJson('/api/todo')->assertForbidden();
    }

    public function test_the_waiting_work_is_what_the_person_may_do_and_a_doctor_sees_only_their_own_patients(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $other = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::factory()->create(['name' => 'Sample Father Tester']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $visit = $this->postJson('/api/appointments', [
            'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i'), 'status' => 'arrived',
            'service_id' => Service::factory()->create(['price' => 200])->id,
        ])->assertCreated()->json();
        $test = LabTest::create(['name' => 'CBP', 'price' => 300]);
        $test->parameters()->create(['name' => 'Haemoglobin']);
        $this->postJson('/api/lab/orders', ['patient_id' => $patient->id, 'test_ids' => [$test->id]])->assertCreated();

        // The doctor it is for: the patient is waiting for them. The other doctor has nothing.
        Sanctum::actingAs($doctor);
        $mine = $this->items($this->getJson('/api/todo')->assertOk()->json());
        $this->assertSame(1, $mine['doctor.waiting']['count']);
        $this->assertTrue($mine['doctor.waiting']['urgent']);
        $this->assertStringContainsString('Sample Father Tester', $mine['doctor.waiting']['rows'][0]['text']);
        $this->assertSame("/visits/{$visit['id']}", $mine['doctor.waiting']['rows'][0]['to']);
        Sanctum::actingAs($other);
        $this->assertArrayNotHasKey('doctor.waiting', $this->items($this->getJson('/api/todo')->json()));

        // The front desk: today's unpaid bill. The lab bill is the lab's, and so is the sample.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $desk = $this->items($this->getJson('/api/todo')->json());
        $this->assertSame(1, $desk['front_desk.desk_dues']['count']);
        $this->assertStringContainsString(Bill::find($visit['bills'][0]['id'])->bill_no, $desk['front_desk.desk_dues']['rows'][0]['text']);
        $this->assertSame([], array_filter(array_keys($desk), fn ($key) => str_starts_with($key, 'pharmacy.') || str_starts_with($key, 'doctor.')));

        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
        $lab = $this->items($this->getJson('/api/todo')->json());
        $this->assertSame(1, $lab['lab.samples']['count']);
        $this->assertStringContainsString('CBP', $lab['lab.samples']['rows'][0]['detail']);
        $this->assertSame([], array_filter(array_keys($lab), fn ($key) => ! str_starts_with($key, 'lab.') && ! str_starts_with($key, 'office.')));

        // Once the bill is paid it leaves the desk's list by itself.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->postJson("/api/bills/{$visit['bills'][0]['id']}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'cash'])->assertCreated();
        $this->assertArrayNotHasKey('front_desk.desk_dues', $this->items($this->getJson('/api/todo')->json()));

        // A pharmacist, a nurse, a manager and an accountant each get a screen without error.
        foreach (['admin', 'pharmacist', 'nurse', 'manager', 'accountant', 'hr', 'dept_manager'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/todo')->assertOk()->assertJsonStructure(['today', 'can_give', 'waiting', 'tasks', 'done']);
        }
    }
}
