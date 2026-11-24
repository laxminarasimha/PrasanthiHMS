<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Workforce\Models\Attendance;
use App\Workforce\Models\Employee;
use App\Workforce\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A night duty runs across midnight and belongs to the day it began, in a hospital that has set up no shifts at all.
 * And a check-out that was simply forgotten does not stand in the way of the next check-in.
 */
class NightDutyTest extends TestCase
{
    use RefreshDatabase;

    private Branch $here;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->here = Branch::create(['name' => 'Sample Hospital']);
        $this->admin = User::factory()->create(['role' => 'admin', 'branch_id' => $this->here->id]);
        $this->travelTo('2026-03-10 20:02:00'); // a Tuesday evening
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/workforce/setup/settings', ['attendance' => ['self_check_in' => true]])->assertOk();
    }

    private function person(string $name, ?User $login = null): Employee
    {
        return Tenant::run($this->here->id, fn () => Employee::create(['employee_no' => 'E'.random_int(10000, 99999), 'name' => $name, 'joined_on' => '2025-01-01', 'weekly_off' => ['sun'], 'user_id' => $login?->id]));
    }

    private function row(string $date, Employee $who): array
    {
        return collect($this->getJson("/api/workforce/attendance/day?date={$date}")->assertOk()->json('rows'))->firstWhere('id', $who->id);
    }

    public function test_someone_checked_in_at_eight_in_the_evening_is_on_duty_until_the_next_morning(): void
    {
        $login = User::factory()->create(['role' => 'nurse', 'branch_id' => $this->here->id]);
        $nurse = $this->person('Night Nurse', $login);

        // Checked in at 8:02 PM on Tuesday. The duty is Tuesday's.
        $spell = $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $nurse->id])->assertSuccessful()->json();
        $this->assertSame(['2026-03-10', 'open'], [$spell['work_date'], $spell['state']]);

        // One in the morning on Wednesday. They are in the hospital, on duty since last night: not "not in", not a
        // forgotten check-out, and not someone to be checked in again.
        $this->travelTo('2026-03-11 01:00:00');
        $row = $this->row('2026-03-11', $nurse);
        $this->assertSame([[$spell['id']], [], []], [array_column($row['on_duty'], 'id'), $row['open_earlier'], $row['records']]);
        $day = $this->getJson('/api/workforce/attendance/day?date=2026-03-11')->json('counts');
        $this->assertSame([1, 0, 1], [$day['in'], $day['not_in'], $day['still_in']]);
        $board = $this->getJson('/api/workforce/dashboard')->assertOk()->json();
        $this->assertSame(['Night Nurse'], array_column($board['in'], 'name'));
        $this->assertSame('since yesterday 8:02 PM', $board['in'][0]['note']);
        $this->assertSame([[], []], [$board['not_in'], $board['missing_check_outs']]);
        $this->assertStringContainsString('already checked in, since 8:02 PM yesterday', $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $nurse->id])->assertStatus(422)->json('errors.employee_id.0'));

        // Their own screen says the same: on duty, with nothing forgotten, and the button checks them out.
        Sanctum::actingAs($login);
        $this->getJson('/api/workforce/me/today')->assertOk()->assertJsonPath('open.id', $spell['id'])->assertJsonPath('forgotten', null);
        $this->getJson('/api/workforce/me')->assertOk()->assertJsonPath('open.id', $spell['id']);

        // Eight in the morning: checked out. Twelve hours, all of it on Tuesday. Wednesday is untouched.
        $this->travelTo('2026-03-11 08:02:00');
        $this->postJson('/api/workforce/me/punch')->assertOk()->assertJsonPath('id', $spell['id'])->assertJsonPath('state', 'completed')->assertJsonPath('work_date', '2026-03-10')->assertJsonPath('worked_minutes', 720);
        Sanctum::actingAs($this->admin);
        $this->assertSame(['present', 720], [$this->row('2026-03-10', $nurse)['day']['status'], $this->row('2026-03-10', $nurse)['day']['worked_minutes']]);
        $this->assertSame([[], [], []], [$this->row('2026-03-11', $nurse)['records'], $this->row('2026-03-11', $nurse)['on_duty'], $this->row('2026-03-11', $nurse)['open_earlier']]);

        // That evening they check in for Wednesday night without trouble.
        $this->travelTo('2026-03-11 20:00:00');
        Sanctum::actingAs($login);
        $this->postJson('/api/workforce/me/punch')->assertOk()->assertJsonPath('state', 'open')->assertJsonPath('work_date', '2026-03-11');
    }

    public function test_a_forgotten_check_out_does_not_block_the_next_check_in_and_is_left_for_the_office(): void
    {
        $login = User::factory()->create(['role' => 'nurse', 'branch_id' => $this->here->id]);
        $nurse = $this->person('Night Nurse', $login);
        $first = $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $nurse->id])->assertSuccessful()->json('id');

        // They went home on Wednesday morning without checking out. By the afternoon that duty is plainly over.
        $this->travelTo('2026-03-11 14:00:00');
        $this->assertSame([[], [$first]], [$this->row('2026-03-11', $nurse)['on_duty'], array_column($this->row('2026-03-11', $nurse)['open_earlier'], 'id')]);
        $board = $this->getJson('/api/workforce/dashboard')->json();
        $this->assertSame([[], [$first]], [$board['in'], array_column($board['missing_check_outs'], 'id')]);

        // Wednesday, eight in the evening: their screen offers check-in, saying the earlier check-out is missing.
        $this->travelTo('2026-03-11 20:00:00');
        Sanctum::actingAs($login);
        $this->getJson('/api/workforce/me/today')->assertOk()->assertJsonPath('open', null)->assertJsonPath('forgotten.id', $first);
        $second = $this->postJson('/api/workforce/me/punch')->assertOk()->assertJsonPath('state', 'open')->assertJsonPath('work_date', '2026-03-11')->json('id');
        $this->assertNotSame($first, $second);
        // Tuesday's is not closed at this hour, which would have counted a whole day as worked. It waits for the office.
        $old = Attendance::withoutGlobalScopes()->find($first);
        $this->assertSame(['review', null, null], [$old->state, $old->check_out, $old->worked_minutes]);
        $this->getJson('/api/workforce/me/today')->assertJsonPath('open.id', $second)->assertJsonPath('forgotten.id', $first);

        Sanctum::actingAs($this->admin);
        $this->assertSame([$first], array_column($this->getJson('/api/workforce/dashboard')->json('missing_check_outs'), 'id'));
        $this->assertSame('open', $this->row('2026-03-10', $nurse)['day']['status']);
        // The office puts in the time they left; it is then an ordinary finished duty and nothing is left over.
        $this->putJson("/api/workforce/attendance/{$first}", ['work_date' => '2026-03-10', 'check_in' => '2026-03-10 20:02', 'check_out' => '2026-03-11 08:00', 'reason' => 'Forgot to check out'])->assertOk();
        $this->assertSame(['completed', 718], [Attendance::withoutGlobalScopes()->find($first)->state, Attendance::withoutGlobalScopes()->find($first)->worked_minutes]);
        $this->assertSame([], $this->getJson('/api/workforce/dashboard')->json('missing_check_outs'));
    }

    public function test_arriving_after_midnight_counts_for_the_night_before_and_a_day_duty_stays_on_its_own_day(): void
    {
        $late = $this->person('Late Arrival');
        $day = $this->person('Day Worker');

        // Half past midnight, nothing marked on Tuesday, no duty set: this is Tuesday's night duty.
        $this->travelTo('2026-03-11 00:30:00');
        $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $late->id])->assertSuccessful()->assertJsonPath('work_date', '2026-03-10');
        $this->assertSame(['open', 'open'], [$this->row('2026-03-10', $late)['day']['status'], $this->row('2026-03-11', $late)['on_duty'][0]['state']]);

        // A day's work from the morning: on that day, and still "in" late that evening.
        $this->travelTo('2026-03-11 09:00:00');
        $spell = $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $day->id])->assertSuccessful()->assertJsonPath('work_date', '2026-03-11')->json('id');
        $this->travelTo('2026-03-11 23:30:00');
        $this->assertContains('Day Worker', array_column($this->getJson('/api/workforce/dashboard')->json('in'), 'name'));
        // Half past midnight they are still treated as there (they stayed late) and can be checked out, on Wednesday.
        $this->travelTo('2026-03-12 00:30:00');
        $this->assertSame([$spell], array_column($this->row('2026-03-12', $day)['on_duty'], 'id'));
        $this->postJson("/api/workforce/attendance/{$spell}/check-out")->assertOk()->assertJsonPath('work_date', '2026-03-11')->assertJsonPath('worked_minutes', 930);
        // Someone who already worked on Wednesday and comes back at one in the morning starts Thursday's entry.
        $this->travelTo('2026-03-12 01:00:00');
        $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $day->id])->assertSuccessful()->assertJsonPath('work_date', '2026-03-12');
    }
}
