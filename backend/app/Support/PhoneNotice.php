<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\DischargeSummary;
use App\Models\Expense;
use App\Models\LabOrder;
use App\Models\LabResult;
use App\Models\PushSubscription;
use App\Models\RepVisit;
use App\Models\StaffTask;
use App\Models\StockCount;
use App\Models\User;
use App\Workforce\Models\Employee;
use App\Workforce\Models\LeaveRequest;
use Closure;
use Illuminate\Support\Carbon;
use Throwable;

use function Illuminate\Support\defer;

/**
 * What is sent to a person's phone, and when.
 *
 * A notification is sent after the answer has gone back to whoever is at the screen, so the desk never
 * waits for a phone. It is sent to every device the person has switched notifications on for, and a
 * failure to send never undoes or holds up the work that caused it.
 *
 * Each kind of notice has its own rule below. kindsFor() says which kinds a login can be sent at all: the screens
 * use it to say what the person will be told, and no notice of any other kind reaches them. Nobody is told about
 * something they did themselves, and nothing clinical, no pay and no reason or note typed by someone travels in the words.
 */
final class PhoneNotice
{
    // The kinds of notice. The phone hands the kind to the open app, so the screen it is about can refresh.
    public const APPOINTMENT = 'appointment';

    public const LAB = 'lab';

    public const REP = 'rep';

    public const TASK = 'task';

    public const LEAVE = 'leave';

    public const EXPENSE = 'expense';

    public const STOCK_COUNT = 'stock_count';

    public const SUMMARY = 'summary';

    public const TEST = 'test';

    /** Every kind, in the order the screens list them. */
    public const KINDS = [self::APPOINTMENT, self::LAB, self::REP, self::TASK, self::LEAVE, self::EXPENSE, self::STOCK_COUNT, self::SUMMARY, self::TEST];

    /**
     * The kinds of notice this login can be sent. None at all for a login that opens only its own record, a screen
     * on the wall, a doctor who is only on the lists, or a closed login: those are never offered notices.
     *
     * @return list<string>
     */
    public static function kindsFor(User $user): array
    {
        if (! self::reachable($user)) {
            return [];
        }
        $doctor = $user->role === Role::Doctor;
        $kinds = array_keys(array_filter([
            // A doctor's own patients and visitors.
            self::APPOINTMENT => $doctor,
            self::LAB => $doctor,
            self::REP => $doctor,
            // Anyone with a To do screen can be given a task.
            self::TASK => true,
            // Leave is asked for on the person's own record, and the notice opens that record.
            self::LEAVE => $user->allows('wf:self:view') && $user->allows('wf:self:create'),
            self::EXPENSE => $user->allows('canRecordExpenses'),
            self::STOCK_COUNT => self::approvesCounts($user),
            self::SUMMARY => $user->allows('canApproveIpd') && $user->allows('canWorkIpd'),
        ]));

        // The test a person sends themself from My account comes with any of the others.
        return $kinds === [] ? [] : [...$kinds, self::TEST];
    }

    /**
     * A doctor is told when someone else puts a patient on their list: the desk booking or checking a patient in,
     * or a patient booking from their own phone. Not told about a visit the doctor started themselves, nor about
     * an old visit being entered late.
     */
    public static function appointmentAdded(Appointment $appointment, ?int $addedBy, bool $fromPatientApp = false): void
    {
        self::safely(function () use ($appointment, $addedBy, $fromPatientApp) {
            $at = Carbon::parse($appointment->getRawOriginal('scheduled_at'));
            // A visit just made may not have read its starting status back yet: it starts as booked.
            $status = $appointment->status ?? 'booked';
            if ($addedBy === (int) $appointment->doctor_id || ! in_array($status, ['booked', 'arrived'], true) || $at->lt(today())) {
                return;
            }

            $day = $at->isToday() ? 'Today' : ($at->isTomorrow() ? 'Tomorrow' : $at->format('D j M'));
            $words = [$appointment->patient?->name ?? 'A patient', $day.' '.$at->format('g:i A')];
            if ($appointment->token_no) {
                $words[] = 'Token '.$appointment->token_no;
            }
            if ($fromPatientApp) {
                $words[] = 'booked from the patient app';
            }

            self::later((int) $appointment->doctor_id, [
                'title' => $status === 'arrived' ? 'Patient waiting' : 'New appointment',
                'body' => implode(' · ', $words),
                'url' => '/doctor',
                'tag' => 'appointment-'.$appointment->id,
                'kind' => self::APPOINTMENT,
            ]);
        });
    }

    /**
     * A doctor is told when the desk marks a patient who was booked earlier as having reached the hospital.
     * It takes the place of the "New appointment" notice for the same visit on the phone, and sounds again.
     * Not told when the doctor marked it themselves, nor for a visit of an earlier day being tidied up.
     */
    public static function patientArrived(Appointment $appointment, ?int $markedBy): void
    {
        self::safely(function () use ($appointment, $markedBy) {
            $at = Carbon::parse($appointment->getRawOriginal('scheduled_at'));
            if ($markedBy === (int) $appointment->doctor_id || $at->lt(today())) {
                return;
            }

            $words = [$appointment->patient?->name ?? 'A patient', 'has arrived', 'booked for '.$at->format('g:i A')];
            if ($appointment->token_no) {
                $words[] = 'Token '.$appointment->token_no;
            }

            self::later((int) $appointment->doctor_id, [
                'title' => 'Patient waiting',
                'body' => implode(' · ', $words),
                'url' => '/doctor',
                'tag' => 'appointment-'.$appointment->id,
                'kind' => self::APPOINTMENT,
            ]);
        });
    }

    /**
     * A doctor is told when the results they asked for are ready to read: the patient, the tests, and how many
     * values are outside their normal range. No values travel in the notice itself.
     */
    public static function resultsReady(LabOrder $order, int $doctorId): void
    {
        self::safely(function () use ($order, $doctorId) {
            $items = $order->items()->whereNotNull('reported_at')->get(['id', 'test_name']);
            $tests = $items->pluck('test_name')->unique()->values();
            $abnormal = LabResult::whereIn('lab_order_item_id', $items->pluck('id'))->whereIn('flag', ['high', 'low'])->count();
            $words = [$order->patient?->name ?? 'A patient', $tests->take(3)->implode(', ').($tests->count() > 3 ? ' and '.($tests->count() - 3).' more' : '')];
            if ($abnormal > 0) {
                $words[] = $abnormal.' outside the normal range';
            }

            self::later($doctorId, [
                'title' => 'Lab results ready',
                'body' => implode(' · ', $words),
                'url' => '/doctor/results',
                'tag' => 'lab-'.$order->id,
                'kind' => self::LAB,
            ]);
        });
    }

    /** Someone given a task by another person is told who gave it and what it is. A task written for oneself is silent. */
    public static function taskGiven(StaffTask $task, User $giver): void
    {
        self::safely(function () use ($task, $giver) {
            if ((int) $task->assigned_to === $giver->id) {
                return;
            }

            self::later((int) $task->assigned_to, [
                'title' => 'New task from '.$giver->name,
                'body' => $task->title.($task->due_on ? ' · by '.$task->due_on->format('j M') : ''),
                'url' => '/todo',
                'tag' => 'task-'.$task->id,
                'kind' => self::TASK,
            ]);
        });
    }

    /** A doctor is told when the desk logs a medical representative who has come to meet them. */
    public static function repWaiting(RepVisit $visit, User $loggedBy): void
    {
        self::safely(function () use ($visit, $loggedBy) {
            if ((int) $visit->doctor_id === $loggedBy->id) {
                return;
            }

            self::later((int) $visit->doctor_id, [
                'title' => 'Medical representative waiting',
                'body' => $visit->rep->name.' · '.$visit->rep->company->name,
                'url' => '/reps',
                'tag' => 'rep-visit-'.$visit->id,
                'kind' => self::REP,
            ]);
        });
    }

    /**
     * A member of staff is told on their own login when someone else decides their leave: approved (leave entered
     * for them that needs no approval included), not approved, or cancelled. The dates, the days and who decided
     * travel in it: never the kind of leave, the reason or the note. Leave for someone with no login tells nobody.
     */
    public static function leaveDecided(LeaveRequest $leave, User $by): void
    {
        self::safely(function () use ($leave, $by) {
            [$title, $done] = match ($leave->status) {
                'approved' => ['Leave approved', 'decided'],
                'rejected' => ['Leave not approved', 'decided'],
                'cancelled' => ['Leave cancelled', 'cancelled'],
                default => [null, null],
            };
            // The person is the same whichever branch's screen it was decided on.
            $login = Employee::withoutGlobalScopes()->whereKey($leave->employee_id)->value('user_id');
            if ($title === null || ! $login || (int) $login === $by->id) {
                return;
            }

            $from = $leave->from_date->format('j M');
            $to = $leave->to_date->format('j M');
            self::later((int) $login, [
                'title' => $title,
                'body' => implode(' · ', [$from === $to ? $from : "{$from} to {$to}", self::days((float) $leave->days), "{$done} by {$by->name}"]),
                'url' => '/workforce/me',
                'tag' => 'leave-'.$leave->id,
                'kind' => self::LEAVE,
            ]);
        });
    }

    /**
     * Whoever entered an expense, and whoever sent it for approval, is told when it is approved or turned down: its
     * number, its heading and who decided. Never the amount, whom it was paid to, its details or the note.
     */
    public static function expenseDecided(Expense $expense, User $by, bool $approved): void
    {
        self::safely(function () use ($expense, $by, $approved) {
            // Salaries and advances are written already paid by payroll and advances, and never wait for a decision.
            if ($expense->isPosted() || $expense->heading?->is_payroll) {
                return;
            }

            $message = [
                'title' => $approved ? 'Expense approved' : 'Expense not approved',
                'body' => implode(' · ', array_filter([$expense->expense_no, $expense->heading?->name ?? $expense->category, 'decided by '.$by->name])),
                'url' => $approved ? '/expenses?show=to_pay' : '/expenses?show=drafts',
                'tag' => 'expense-'.$expense->id,
                'kind' => self::EXPENSE,
            ];
            $people = collect([$expense->created_by, $expense->submitted_by])->filter()->map(fn ($id) => (int) $id)->unique()->reject(fn (int $id) => $id === $by->id);
            foreach ($people as $id) {
                self::later($id, $message);
            }
        });
    }

    /**
     * Whoever entered an expense is told when someone else sends it on and it needs no approval, so it is ready to pay
     * at once: its number, its heading and who sent it. Never the amount, whom it was paid to or its details.
     */
    public static function expenseReady(Expense $expense, User $by): void
    {
        self::safely(function () use ($expense, $by) {
            // Salaries and advances are written already paid by payroll and advances, and never wait to be paid.
            if ($expense->status !== 'approved' || $expense->isPosted() || $expense->heading?->is_payroll || ! $expense->created_by || (int) $expense->created_by === $by->id) {
                return;
            }

            self::later((int) $expense->created_by, [
                'title' => 'Expense ready to pay',
                'body' => implode(' · ', array_filter([$expense->expense_no, $expense->heading?->name ?? $expense->category, 'needs no approval, sent by '.$by->name])),
                'url' => '/expenses?show=to_pay',
                'tag' => 'expense-'.$expense->id,
                'kind' => self::EXPENSE,
            ]);
        });
    }

    /**
     * Those who approve stock counts are told when one is handed in: its number, what was counted and who counted
     * it. Nothing of the differences, the units or their value travels in it.
     */
    public static function stockCountHandedIn(StockCount $count, User $by): void
    {
        self::safely(fn () => self::toEveryone(fn (User $person) => self::hears($person, self::STOCK_COUNT), [
            'title' => 'Stock count handed in',
            'body' => implode(' · ', array_filter([$count->count_no, $count->title, 'by '.$by->name])),
            'url' => '/pharmacy/audit/counts/'.$count->id,
            'tag' => 'stock-count-'.$count->id,
            'kind' => self::STOCK_COUNT,
        ], $by->id));
    }

    /**
     * The doctor of an IPD case is told when its discharge summary is handed to them for approval. Where that doctor
     * cannot be told or cannot approve (a visiting consultant who does not sign in, say), or hands it over
     * themself for someone else to approve, those who approve summaries and are not doctors are told instead.
     * Never another doctor: a doctor hears only of their own patients. The case number and the patient's name
     * only: nothing of what the summary says.
     */
    public static function summaryReady(DischargeSummary $summary, Admission $admission, User $by): void
    {
        self::safely(function () use ($summary, $admission, $by) {
            if ($summary->approved_at) {
                return;
            }

            $message = [
                'title' => 'Discharge summary to approve',
                'body' => implode(' · ', array_filter([$admission->ipd_no, $admission->patient?->name ?? 'A patient', 'sent by '.$by->name])),
                'url' => '/ipd/'.$admission->id.'?tab=summary',
                'tag' => 'summary-'.$admission->id,
                'kind' => self::SUMMARY,
            ];
            if ((int) $admission->doctor_id !== $by->id && self::doctorHearsSummary($admission)) {
                self::later((int) $admission->doctor_id, $message);

                return;
            }
            self::toEveryone(fn (User $person) => $person->role !== Role::Doctor && self::hears($person, self::SUMMARY), $message, $by->id);
        });
    }

    /**
     * Whether the doctor of an IPD case is the one told when its summary is handed over for approval: they can be sent
     * notices, and may approve summaries and work in the IPD. The summary screen asks this too, so it names whoever is
     * really told.
     */
    public static function doctorHearsSummary(Admission $admission): bool
    {
        $doctor = $admission->doctor_id ? User::find($admission->doctor_id) : null;

        return $doctor !== null && self::hears($doctor, self::SUMMARY);
    }

    /**
     * Sends once the answer to the present request has gone out.
     *
     * @param  array<string, mixed>  $message
     */
    public static function later(int $userId, array $message): void
    {
        // Most people have not switched notifications on: nothing is set aside for them.
        if (! PushSubscription::where('user_id', $userId)->exists()) {
            return;
        }
        defer(function () use ($userId, $message) {
            try {
                self::now($userId, $message);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Sends to each of the person's devices, and says how many took it.
     *
     * @param  array<string, mixed>  $message
     */
    public static function now(int $userId, array $message): int
    {
        $user = User::find($userId);
        $kinds = $user ? self::kindsFor($user) : [];
        // Nothing for a login that is offered no notices, and no kind of notice the person is not offered.
        if ($kinds === [] || (isset($message['kind']) && ! in_array($message['kind'], $kinds, true))) {
            return 0;
        }

        return PushSubscription::where('user_id', $userId)->get()->filter(function (PushSubscription $device) use ($message) {
            // Each device on its own: one that fails is reported, and never keeps the notice from the others.
            try {
                return $device->deliver($message);
            } catch (Throwable $e) {
                report($e);

                return false;
            }
        })->count();
    }

    /** Whether this login is sent notices of this kind. */
    private static function hears(User $user, string $kind): bool
    {
        return in_array($kind, self::kindsFor($user), true);
    }

    /** Whether this login can be sent anything: open, signing in, more than an own-record login, and not a wall screen. */
    private static function reachable(User $user): bool
    {
        return (bool) $user->is_active && $user->signsIn() && ! $user->is_shared_screen && $user->role !== Role::Staff;
    }

    /**
     * Who hears of a stock count handed in: an admin, and anyone the stock audit was given to by name who can open
     * the pharmacy's screens. Every doctor holds the audit through their role, and the hospital has many visiting
     * doctors, so the role alone tells nobody.
     */
    private static function approvesCounts(User $user): bool
    {
        if ($user->role === Role::Admin) {
            return true;
        }

        return ($user->permissions['canAuditPharmacy'] ?? null) === true && ($user->allows('canRunPharmacy') || $user->allows('canViewPharmacy'));
    }

    /**
     * Tells each person the check lets through, leaving out whoever did it. Only those with a device switched on are
     * looked at, all found in one go, so the check is made on the few in hand.
     *
     * @param  Closure(User): bool  $who
     * @param  array<string, mixed>  $message
     */
    private static function toEveryone(Closure $who, array $message, int $actorId): void
    {
        $people = User::query()
            ->whereIn('id', PushSubscription::query()->select('user_id'))
            ->where('is_active', true)->where('sign_in_with', '!=', 'none')->where('is_shared_screen', false)->where('role', '!=', Role::Staff->value)
            ->get()
            ->filter(fn (User $person) => $person->id !== $actorId && $who($person));
        foreach ($people as $person) {
            self::later($person->id, $message);
        }
    }

    /** A notice never stands in the way of the work that caused it: whatever goes wrong in a rule is reported, and the work goes on. */
    private static function safely(Closure $rule): void
    {
        try {
            $rule();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** A number of days as it is said: half a day, 1 day, 2.5 days. */
    private static function days(float $days): string
    {
        if ($days == 0.5) {
            return 'half a day';
        }

        return ($days == floor($days) ? (string) (int) $days : (string) $days).($days == 1.0 ? ' day' : ' days');
    }
}
