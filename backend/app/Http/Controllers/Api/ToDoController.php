<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffTask;
use App\Models\User;
use App\Support\PhoneNotice;
use App\Support\ToDoList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A person's To do screen: the work the system knows is waiting for them, and the tasks written for them.
 *
 * Everyone writes tasks for themself and ticks off their own. Giving a task to someone else, and seeing whether it
 * was done, is for those allowed to give tasks. Nobody sees another person's own tasks.
 */
class ToDoController extends Controller
{
    private const WITH = ['assignee:id,name,role', 'giver:id,name', 'finisher:id,name'];

    /** The whole screen for the person signed in. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'today' => today()->toDateString(),
            'can_give' => $user->allows('canGiveTasks'),
            'waiting' => ToDoList::for($user),
            'tasks' => $this->mine($user),
            'done' => StaffTask::where('assigned_to', $user->id)->whereNotNull('done_at')->where('done_at', '>=', now()->subDays(7))->with(self::WITH)->latest('done_at')->limit(30)->get(),
        ]);
    }

    /** How many of the person's own tasks are due today or late: the number shown on the menu. */
    public function count(Request $request): JsonResponse
    {
        return response()->json(['pressing' => StaffTask::where('assigned_to', $request->user()->id)->pressing()->count()]);
    }

    /** The tasks this person gave to others, done or not, newest first. An admin sees everyone's. */
    public function given(Request $request): JsonResponse
    {
        $user = $this->giver($request);

        return response()->json(
            StaffTask::whereNull('cancelled_at')->whereColumn('assigned_to', '!=', 'created_by')
                ->when($user->role->value !== 'admin', fn (Builder $q) => $q->where('created_by', $user->id))
                ->where(fn (Builder $q) => $q->whereNull('done_at')->orWhere('done_at', '>=', now()->subDays(30)))
                ->with(self::WITH)->orderByRaw('done_at is not null')->orderByRaw('due_on is null')->orderBy('due_on')->latest('id')->limit(300)->get()
        );
    }

    /** The people a task can be given to: everyone with a login that opens more than their own record. */
    public function people(Request $request): JsonResponse
    {
        $this->giver($request);

        return response()->json(
            User::where('is_active', true)->where('sign_in_with', '!=', 'none')->where('role', '!=', 'staff')->orderBy('name')->get(['id', 'name', 'role'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->label()])
        );
    }

    /** Writes a task: for oneself, or for each of the people chosen. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate($this->rules() + [
            'for' => ['sometimes', 'array', 'max:40'],
            'for.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('is_active', true)],
        ]);
        $for = array_map('intval', $data['for'] ?? []) ?: [$user->id];
        if ($for !== [$user->id] && ! $user->allows('canGiveTasks')) {
            abort(403, 'You are not allowed to give tasks to other people. Write it for yourself, or ask someone who can.');
        }
        if (User::whereIn('id', $for)->where(fn ($q) => $q->where('role', 'staff')->orWhere('sign_in_with', 'none'))->exists()) {
            throw ValidationException::withMessages(['for' => 'One of these people has no To do screen: their login opens only their own attendance.']);
        }

        $made = DB::transaction(fn () => collect($for)->map(fn (int $id) => StaffTask::create([
            'branch_id' => $user->branch_id, 'title' => trim($data['title']), 'notes' => $data['notes'] ?? null, 'assigned_to' => $id, 'created_by' => $user->id,
            'due_on' => $this->day($data), 'important' => (bool) ($data['important'] ?? false), 'repeats' => $data['repeats'] ?? 'none',
        ])));
        // Someone given a task is told on their phone, where they have notifications on.
        foreach ($made as $task) {
            PhoneNotice::taskGiven($task, $user);
        }

        return response()->json($made->map->load(self::WITH)->values(), 201);
    }

    /** Changes a task's wording or day. Its writer may; so may the person it is for, when they wrote it themself. */
    public function update(Request $request, StaffTask $task): JsonResponse
    {
        $this->own($request, $task, writer: true);
        if ($task->done_at || $task->cancelled_at) {
            throw ValidationException::withMessages(['title' => 'This task is already finished.']);
        }
        $data = $request->validate($this->rules());
        $task->update(['title' => trim($data['title']), 'notes' => $data['notes'] ?? null, 'due_on' => $this->day($data), 'important' => (bool) ($data['important'] ?? false), 'repeats' => $data['repeats'] ?? 'none']);

        return response()->json($task->load(self::WITH));
    }

    /** Ticks a task off. A repeating one writes its next turn at the same moment. */
    public function done(Request $request, StaffTask $task): JsonResponse
    {
        $user = $this->own($request, $task);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $next = DB::transaction(function () use ($task, $user, $data) {
            $task = StaffTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if ($task->done_at || $task->cancelled_at) {
                throw ValidationException::withMessages(['task' => 'This task is already finished.']);
            }
            $task->update(['done_at' => now(), 'done_by' => $user->id, 'done_note' => $data['note'] ?? null]);
            $due = $task->nextDue();

            return $due ? StaffTask::create($task->only(['branch_id', 'title', 'notes', 'assigned_to', 'created_by', 'important', 'repeats']) + ['due_on' => $due->toDateString()]) : null;
        });

        return response()->json(['task' => $task->fresh()->load(self::WITH), 'next' => $next?->load(self::WITH)]);
    }

    /** Takes the tick off again, when it was ticked by mistake. The next turn of a repeating task, if still untouched, goes with it. */
    public function reopen(Request $request, StaffTask $task): JsonResponse
    {
        $this->own($request, $task);
        if (! $task->done_at || $task->cancelled_at) {
            throw ValidationException::withMessages(['task' => 'This task is not ticked off.']);
        }
        DB::transaction(function () use ($task) {
            if ($task->repeats !== 'none') {
                StaffTask::open()->where('assigned_to', $task->assigned_to)->where('title', $task->title)->where('repeats', $task->repeats)
                    ->where('created_at', '>=', $task->done_at)->where('id', '>', $task->id)->limit(1)->delete();
            }
            $task->update(['done_at' => null, 'done_by' => null, 'done_note' => null]);
        });

        return response()->json($task->fresh()->load(self::WITH));
    }

    /** Withdraws a task that is no longer wanted. It stays on record as withdrawn; a repeating one stops repeating. */
    public function destroy(Request $request, StaffTask $task): JsonResponse
    {
        $user = $this->own($request, $task, writer: true);
        if (! $task->done_at && ! $task->cancelled_at) {
            $task->update(['cancelled_at' => now(), 'cancelled_by' => $user->id]);
        }

        return response()->json(null, 204);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'due_on' => ['nullable', 'date', 'after_or_equal:'.today()->subYear()->toDateString(), 'before_or_equal:'.today()->addYears(2)->toDateString()],
            'important' => ['sometimes', 'boolean'],
            'repeats' => ['sometimes', Rule::in(StaffTask::REPEATS)],
        ];
    }

    /**
     * The day a task is for. A repeating one with no day given starts today: "every day" has to start on some day.
     *
     * @param  array<string, mixed>  $data
     */
    private function day(array $data): ?string
    {
        return $data['due_on'] ?? (($data['repeats'] ?? 'none') !== 'none' ? today()->toDateString() : null);
    }

    /** The person's open tasks: late first, then by day, the important ones ahead within a day. */
    private function mine(User $user)
    {
        return StaffTask::open()->where('assigned_to', $user->id)->with(self::WITH)
            ->orderByRaw('due_on is null')->orderBy('due_on')->orderByDesc('important')->orderBy('id')->limit(300)->get();
    }

    private function giver(Request $request): User
    {
        abort_unless($request->user()->allows('canGiveTasks'), 403, 'You are not allowed to give tasks to other people.');

        return $request->user();
    }

    /**
     * Whether the person signed in has a say over this task. The one it is for may tick it; its writer (or an admin)
     * may also change or withdraw it. A task someone else wrote for you is theirs to change.
     */
    private function own(Request $request, StaffTask $task, bool $writer = false): User
    {
        $user = $request->user();
        $wrote = $task->created_by === $user->id || $user->role->value === 'admin';
        // Somebody else's task does not exist as far as this person is concerned.
        abort_unless($wrote || $task->assigned_to === $user->id, 404);
        abort_if($writer && ! $wrote, 403, 'This task was given to you by '.($task->giver?->name ?? 'someone else').'. Only they can change or withdraw it; you can tick it off.');

        return $user;
    }
}
