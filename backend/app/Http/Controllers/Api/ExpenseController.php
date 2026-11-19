<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminEntry;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use App\Support\PaidOut;
use App\Workforce\Models\ExpenseCategory;
use App\Workforce\Models\ExpenseTemplate;
use App\Workforce\Support\Audit;
use App\Workforce\Support\ExpenseBook;
use App\Workforce\Support\Permissions;
use App\Workforce\Support\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The hospital's expenses. Whoever keeps the cash writes down each payment out as it is made, so the drawer agrees
 * with the books at the end of the day. A bigger expense, under a heading that asks for it, goes for approval first
 * and is paid once approved. An entry is never erased: a wrong one is cancelled with a reason.
 *
 * Who may do what: anyone allowed on the Expenses screen ("Record expenses") enters expenses, pays approved ones and
 * puts right their own mistakes of the day. Approving needs "Approve or refuse expenses", or "Approve large expenses"
 * above a heading's limit. Undoing a paid expense from an earlier day needs "Reverse or cancel a paid expense".
 */
class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $range = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'category' => ['nullable', 'string', 'max:100'], 'q' => ['nullable', 'string', 'max:100'],
            'show' => ['nullable', Rule::in(['all', 'waiting', 'to_pay', 'drafts'])],
        ]);
        $from = Carbon::parse($range['from'] ?? today())->startOfDay();
        $to = Carbon::parse($range['to'] ?? $from)->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose a range of one year or less.');
        $term = trim((string) ($range['q'] ?? ''));
        $show = $range['show'] ?? 'all';
        // What is waiting on someone is shown whatever its date: it is still to be dealt with.
        $rows = Expense::query()
            ->when($show === 'all', fn ($q) => $q->where(fn ($w) => $w->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
                ->orWhere(fn ($p) => $p->where('status', 'paid')->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()]))))
            ->when($show === 'waiting', fn ($q) => $q->where('status', 'submitted')->whereNull('cancelled_at'))
            ->when($show === 'to_pay', fn ($q) => $q->where('status', 'approved')->whereNull('cancelled_at'))
            ->when($show === 'drafts', fn ($q) => $q->whereIn('status', ['draft', 'rejected'])->whereNull('cancelled_at'))
            ->when($range['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('paid_to', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%")->orWhere('expense_no', 'like', "{$term}%")->orWhere('bill_no', 'like', "{$term}%")))
            ->with(['creator:id,name', 'canceller:id,name', 'submitter:id,name', 'decider:id,name', 'payer:id,name', 'department:id,name', 'heading'])
            ->orderByDesc('spent_on')->orderByDesc('id')->limit(2000)->get();
        $open = Expense::query()->whereNull('cancelled_at')->selectRaw("SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as waiting, SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as to_pay, SUM(CASE WHEN status IN ('draft', 'rejected') THEN 1 ELSE 0 END) as drafts")->first();

        return response()->json([
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'show' => $show,
            'rows' => $rows->map(fn (Expense $e) => $this->present($e, $user)),
            'paid_out' => PaidOut::between($from, $to),
            'counts' => ['waiting' => (int) $open->waiting, 'to_pay' => (int) $open->to_pay, 'drafts' => (int) $open->drafts],
            'categories' => $this->categories(),
            'headings' => ExpenseCategory::query()->where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'approval_above', 'approver_limit', 'is_payroll']),
            'departments' => AdminEntry::query()->where('kind', 'department')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'modes' => Payment::MODES,
            'today' => today()->toDateString(),
            'can' => [
                'approve' => Permissions::allows($user, 'wf:expenses:approve'), 'approve_large' => Permissions::allows($user, 'wf:expenses:finalise'),
                'reverse' => Permissions::allows($user, 'wf:expenses:reverse'), 'export' => $this->mayExport($user), 'set_up' => $this->maySetUp($user),
            ],
        ]);
    }

    /**
     * A new expense. `stage` says where it starts: paid (the usual cash entry, money already out), sent for approval,
     * or kept as a draft. One that its heading says must be approved cannot be entered as already paid.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request);
        $stage = $request->validate(['stage' => ['nullable', Rule::in(['paid', 'submitted', 'draft'])]])['stage'] ?? 'paid';
        $heading = $this->heading($data);
        if ($stage === 'paid' && ExpenseBook::needsApproval($heading, (float) $data['amount']) && $user->role->value !== 'admin') {
            throw ValidationException::withMessages(['amount' => "Under {$heading->name}, anything above ".$this->rupees($heading->approval_above).' is approved before it is paid. Send it for approval.']);
        }
        $expense = Expense::create($data + [
            'branch_id' => $user->branch_id, 'created_by' => $user->id, 'status' => $stage,
            'paid_on' => $stage === 'paid' ? $data['spent_on'] : null, 'paid_by' => $stage === 'paid' ? $user->id : null,
            'submitted_by' => $stage === 'submitted' ? $user->id : null, 'submitted_at' => $stage === 'submitted' ? now() : null,
        ]);
        AuditLog::record('expense.added', null, $expense, $this->words($expense->fresh()).($stage !== 'paid' ? ' · '.($stage === 'draft' ? 'kept as a draft' : 'sent for approval') : ''));

        return response()->json($this->present($expense->fresh($this->with()), $user), 201);
    }

    /**
     * Corrects an entry. A paid one may have its heading and details put right at any time; its money (amount, mode,
     * date) changes only the same day, or by someone who may reverse a paid expense, and never in a closed day. A draft
     * or a refused one is simply changed; a refused one goes back to being a draft. An approved one is not changed:
     * cancel it and enter it again.
     */
    public function update(Request $request, Expense $expense): JsonResponse
    {
        $user = $request->user();
        $this->refuseIfCancelled($expense);
        $this->refuseIfPosted($expense);
        if ($expense->status === 'approved') {
            throw ValidationException::withMessages(['amount' => 'This expense has been approved, so it is not changed. Cancel it and enter it again if it is wrong.']);
        }
        if (in_array($expense->status, ['draft', 'submitted', 'rejected'], true)) {
            $this->refuseUnlessOwnOrEditor($expense, $user);
        }
        $data = $this->validated($request);
        $heading = $this->heading($data);
        $before = $this->words($expense);
        $values = $data;
        if ($expense->status === 'paid') {
            $moneyMoves = (float) $data['amount'] !== (float) $expense->amount || $data['mode'] !== $expense->mode || $data['spent_on'] !== $expense->spent_on->format('Y-m-d');
            if ($moneyMoves) {
                $this->refuseOldMoneyChange($expense, $user, 'changed');
                if (ExpenseBook::needsApproval($heading, (float) $data['amount']) && $user->role->value !== 'admin') {
                    throw ValidationException::withMessages(['amount' => "Under {$heading->name}, anything above ".$this->rupees($heading->approval_above).' is approved before it is paid. Cancel this entry and send a new one for approval.']);
                }
            }
            $values['paid_on'] = $data['spent_on'];
        } elseif ($expense->status === 'rejected') {
            $values['status'] = 'draft';
        }
        $expense->update($values);
        if (($after = $this->words($expense)) !== $before) {
            AuditLog::record('expense.changed', null, $expense, "Was: {$before}. Now: {$after}");
        }

        return response()->json($this->present($expense->fresh($this->with()), $user));
    }

    /** Sends a draft (or a refused one, put right) for approval. One that needs no approval is ready to pay at once. */
    public function submit(Request $request, Expense $expense): JsonResponse
    {
        $user = $request->user();
        $this->refuseIfCancelled($expense);
        $this->refuseUnlessOwnOrEditor($expense, $user);
        if (! in_array($expense->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => 'Only a draft is sent for approval.']);
        }
        $needs = ExpenseBook::needsApproval($expense->heading, (float) $expense->amount);
        $expense->update(['status' => $needs ? 'submitted' : 'approved', 'submitted_by' => $user->id, 'submitted_at' => now(), 'decided_by' => null, 'decided_at' => null, 'decision_note' => $needs ? null : 'No approval needed under this heading.']);
        AuditLog::record('expense.submitted', null, $expense, $this->words($expense).($needs ? ' · sent for approval' : ' · needs no approval, ready to pay'));

        return response()->json($this->present($expense->fresh($this->with()), $user));
    }

    /** Approves or refuses an expense waiting for approval. Nobody decides their own. */
    public function decide(Request $request, Expense $expense): JsonResponse
    {
        $user = $request->user();
        $this->refuseIfCancelled($expense);
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['required_if:approve,false', 'nullable', 'string', 'max:500']], ['note.required_if' => 'Say why it is refused.']);
        abort_unless(Permissions::allows($user, 'wf:expenses:approve'), 403, 'You are not allowed to approve expenses.');
        $large = ExpenseBook::needsHigherApproval($expense->heading, (float) $expense->amount);
        abort_if($large && ! Permissions::allows($user, 'wf:expenses:finalise'), 403, 'This is above what can be approved without "Approve large expenses" ('.$this->rupees($expense->heading?->approver_limit).' under '.$expense->category.').');
        abort_if($user->role->value !== 'admin' && in_array($user->id, [$expense->created_by, $expense->submitted_by], true), 403, 'An expense is approved by someone other than the person who entered it.');
        if ($expense->status !== 'submitted') {
            throw ValidationException::withMessages(['approve' => 'This expense is not waiting for approval.']);
        }
        $expense->update(['status' => $data['approve'] ? 'approved' : 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null]);
        AuditLog::record($data['approve'] ? 'expense.approved' : 'expense.refused', null, $expense, $this->words($expense).(filled($data['note'] ?? null) ? '. Note: '.$data['note'] : ''));
        $this->trail($data['approve'] ? 'expense.approved' : 'expense.refused', $expense, ['status' => 'submitted'], ['status' => $expense->status, 'amount' => $expense->amount, 'category' => $expense->category], $data['note'] ?? null);

        return response()->json($this->present($expense->fresh($this->with()), $user));
    }

    /** Writes down that an approved expense was paid: the day, how, and the reference. It moves no money itself. */
    public function pay(Request $request, Expense $expense): JsonResponse
    {
        $user = $request->user();
        $this->refuseIfCancelled($expense);
        $data = $request->validate([
            'paid_on' => ['required', 'date', 'before_or_equal:today'], 'mode' => ['required', Rule::in(Payment::MODES)], 'reference' => ['nullable', 'string', 'max:255'],
        ], ['paid_on.before_or_equal' => 'A payment cannot be dated in the future.']);
        if ($expense->status !== 'approved') {
            throw ValidationException::withMessages(['paid_on' => $expense->status === 'paid' ? 'This has already been paid.' : 'Only an approved expense is paid.']);
        }
        $expense->update(['status' => 'paid', 'paid_on' => $data['paid_on'], 'mode' => $data['mode'], 'reference' => $data['reference'] ?? $expense->reference, 'paid_by' => $user->id]);
        AuditLog::record('expense.paid', null, $expense, $this->words($expense).' · paid on '.Carbon::parse($data['paid_on'])->format('j M Y'));

        return response()->json($this->present($expense->fresh($this->with()), $user));
    }

    /** Cancels an entry. It stays in the list, struck out, and no longer counts. */
    public function cancel(Request $request, Expense $expense): JsonResponse
    {
        $user = $request->user();
        $this->refuseIfCancelled($expense);
        $this->refuseIfPosted($expense);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], ['reason.required' => 'Say why this entry is being cancelled.']);
        if ($expense->status === 'paid') {
            $this->refuseOldMoneyChange($expense, $user, 'cancelled');
        } elseif ($user->id !== $expense->created_by && ! Permissions::allows($user, 'wf:expenses:approve')) {
            abort(403, 'Only the person who entered it, or someone who approves expenses, can cancel it.');
        }
        $expense->update(['cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => $data['reason']]);
        AuditLog::record('expense.cancelled', null, $expense, $this->words($expense).'. Reason: '.$data['reason']);
        if ($expense->status === 'paid') {
            $this->trail('expense.reversed', $expense, ['amount' => $expense->amount, 'paid_on' => $expense->paid_on?->format('Y-m-d')], null, $data['reason']);
        }

        return response()->json($this->present($expense->fresh($this->with()), $user));
    }

    /** Attaches the bill or receipt (a photo or a PDF). A new one replaces the old. */
    public function attachReceipt(Request $request, Expense $expense): JsonResponse
    {
        $this->refuseIfCancelled($expense);
        $file = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp']], ['file.mimes' => 'Attach a PDF or an image (JPG, PNG or WebP).', 'file.max' => 'The file must be 10 MB or smaller.'])['file'];
        $old = $expense->receipt_path;
        // Kept on the private disk and served only to signed-in staff who may see expenses.
        $expense->update(['receipt_path' => $file->store("expenses/{$expense->id}", 'local'), 'receipt_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'receipt_mime' => $file->getMimeType()]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }
        AuditLog::record('expense.receipt', null, $expense, $this->words($expense).' · bill attached');

        return response()->json($this->present($expense->fresh($this->with()), $request->user()));
    }

    public function receipt(Expense $expense): Response
    {
        abort_unless($expense->receipt_path && Storage::disk('local')->exists($expense->receipt_path), 404, 'No bill is attached.');

        return Storage::disk('local')->response($expense->receipt_path, $expense->receipt_name, ['Content-Type' => $expense->receipt_mime]);
    }

    /** Where the money went in a period: by heading, by department, by month and by mode, with what is still in hand. */
    public function summary(Request $request): JsonResponse
    {
        $range = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($range['from'])->startOfDay();
        $to = Carbon::parse($range['to'])->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose a range of one year or less.');
        $paid = Expense::paidOut()->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->with(['department:id,name', 'heading:id,name,is_payroll'])->get();
        $group = fn ($rows, callable $key) => $rows->groupBy($key)->map(fn ($list, $name) => ['name' => (string) $name, 'count' => $list->count(), 'amount' => round((float) $list->sum('amount'), 2), 'tax' => round((float) $list->sum('tax'), 2)])->sortByDesc('amount')->values();
        $open = Expense::query()->whereNull('cancelled_at')->whereIn('status', ['submitted', 'approved'])->get(['status', 'amount']);

        return response()->json([
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'total' => round((float) $paid->sum('amount'), 2), 'tax' => round((float) $paid->sum('tax'), 2), 'count' => $paid->count(),
            // Salaries and staff advances, posted by payroll and advances: shown apart so they are seen once and plainly.
            'payroll' => round((float) $paid->filter(fn (Expense $e) => $e->heading?->is_payroll)->sum('amount'), 2),
            'by_heading' => $group($paid, fn (Expense $e) => $e->category),
            'by_department' => $group($paid, fn (Expense $e) => $e->department?->name ?? 'No department'),
            'by_month' => $paid->groupBy(fn (Expense $e) => $e->paid_on->format('Y-m'))->map(fn ($list, $month) => ['month' => $month, 'amount' => round((float) $list->sum('amount'), 2)])->sortBy('month')->values(),
            'by_mode' => $group($paid, fn (Expense $e) => $e->mode),
            'waiting' => ['count' => $open->where('status', 'submitted')->count(), 'amount' => round((float) $open->where('status', 'submitted')->sum('amount'), 2)],
            'to_pay' => ['count' => $open->where('status', 'approved')->count(), 'amount' => round((float) $open->where('status', 'approved')->sum('amount'), 2)],
        ]);
    }

    /** Every expense in a period as a file, written down as taken. */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($this->mayExport($user), 403, 'You are not allowed to export to a file.');
        $range = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $rows = Expense::query()->whereBetween('spent_on', [$range['from'], $range['to']])->with(['creator:id,name', 'decider:id,name', 'department:id,name'])->orderBy('spent_on')->orderBy('id')->get();
        AuditLog::record('expense.exported', null, null, "Expenses {$range['from']} to {$range['to']}: {$rows->count()} rows");
        $this->trail('expenses.exported', null, null, ['from' => $range['from'], 'to' => $range['to'], 'rows' => $rows->count()]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['No.', 'Date', 'Heading', 'Department', 'Paid to', 'Bill no.', 'Details', 'Amount', 'Of which tax', 'Stage', 'Paid on', 'Mode', 'Reference', 'Entered by', 'Decided by', 'Cancelled', 'Reason']);
            foreach ($rows as $e) {
                $cells = [$e->expense_no, $e->spent_on->format('Y-m-d'), $e->category, $e->department?->name, $e->paid_to, $e->bill_no, $e->description, $e->amount, $e->tax, $e->status, $e->paid_on?->format('Y-m-d'), $e->mode, $e->reference, $e->creator?->name, $e->decider?->name, $e->cancelled_at ? 'Yes' : '', $e->cancel_reason];
                fputcsv($out, array_map(fn ($cell) => is_string($cell) && preg_match('/^[=+\-@]/', $cell) ? "'".$cell : $cell, $cells));
            }
            fclose($out);
        }, "expenses-{$range['from']}-to-{$range['to']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    // ---- Headings ----

    public function headings(Request $request): JsonResponse
    {
        return response()->json(ExpenseCategory::query()->orderBy('sort')->orderBy('name')->get());
    }

    public function saveHeading(Request $request, ?int $heading = null): JsonResponse
    {
        abort_unless($this->maySetUp($request->user()), 403, 'You are not allowed to set up expense headings.');
        $existing = $heading ? ExpenseCategory::query()->findOrFail($heading) : null;
        abort_if($existing?->is_payroll, 422, 'This heading belongs to payroll and advances, and is kept as it is.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'approval_above' => ['nullable', 'numeric', 'min:0', 'max:99999999'], 'approver_limit' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'is_active' => ['sometimes', 'boolean'], 'sort' => ['nullable', 'integer', 'between:0,9999'],
        ]);
        $data['name'] = trim($data['name']);
        if (in_array($data['name'], [ExpenseBook::SALARIES, ExpenseBook::ADVANCES], true) || ExpenseCategory::query()->where('name', $data['name'])->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists()) {
            throw ValidationException::withMessages(['name' => 'There is already a heading with this name.']);
        }
        if (isset($data['approval_above'], $data['approver_limit']) && $data['approver_limit'] !== null && $data['approval_above'] !== null && (float) $data['approver_limit'] < (float) $data['approval_above']) {
            throw ValidationException::withMessages(['approver_limit' => 'This cannot be below the amount from which approval is needed.']);
        }
        $data['sort'] = (int) ($data['sort'] ?? $existing->sort ?? 0);
        $was = $existing?->only(['name', 'approval_above', 'approver_limit', 'is_active']) ?? [];
        $saved = $existing ? tap($existing)->update($data) : ExpenseCategory::create($data);
        if ($existing && $was['name'] !== $saved->name) {
            // Entries already filed under it carry its name: they follow the new one.
            Expense::query()->where('category_id', $saved->id)->update(['category' => $saved->name]);
        }
        [$before, $after] = Audit::difference($was, $saved->only(['name', 'approval_above', 'approver_limit', 'is_active']));
        if ($after !== []) {
            $this->trail($existing ? 'expense.heading_changed' : 'expense.heading_added', $saved, $before, $after, $request->input('reason'));
        }

        return response()->json($saved->fresh(), $existing ? 200 : 201);
    }

    // ---- Expenses that come round every month ----

    /** The repeating expenses, each saying whether this month's has been made into a draft yet. */
    public function templates(Request $request): JsonResponse
    {
        $month = now()->format('Y-m');

        return response()->json(ExpenseTemplate::query()->with(['category:id,name', 'department:id,name'])->orderBy('day_of_month')->orderBy('name')->get()
            ->map(fn (ExpenseTemplate $t) => $t->only(['id', 'name', 'category_id', 'department_id', 'paid_to', 'amount', 'mode', 'day_of_month', 'is_active', 'last_offered_period']) + [
                'category' => $t->category?->name, 'department' => $t->department?->name,
                'due' => $t->is_active && $t->last_offered_period !== $month && (int) ($t->day_of_month ?? 1) <= (int) now()->day,
            ]));
    }

    public function saveTemplate(Request $request, ?int $template = null): JsonResponse
    {
        abort_unless($this->maySetUp($request->user()), 403, 'You are not allowed to set up repeating expenses.');
        $existing = $template ? ExpenseTemplate::query()->findOrFail($template) : null;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'category_id' => ['required', 'integer'], 'department_id' => ['nullable', 'integer', Rule::exists('admin_entries', 'id')->where('kind', 'department')],
            'paid_to' => ['nullable', 'string', 'max:150'], 'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'], 'mode' => ['nullable', Rule::in(Payment::MODES)],
            'day_of_month' => ['nullable', 'integer', 'between:1,28'], 'is_active' => ['sometimes', 'boolean'],
        ], ['category_id.required' => 'Choose the heading it is filed under.', 'amount.required' => 'Enter the usual amount. Each month’s draft can be changed before it is sent.', 'day_of_month.between' => 'Choose a day from 1 to 28, so every month has it.']);
        $heading = ExpenseCategory::query()->find($data['category_id']);
        if (! $heading || $heading->is_payroll) {
            throw ValidationException::withMessages(['category_id' => $heading ? 'Salaries and advances come from Payroll and Advances, not from a repeating expense.' : 'Choose a heading from the list.']);
        }
        $saved = $existing ? tap($existing)->update($data) : ExpenseTemplate::create($data);
        $this->trail($existing ? 'expense.repeat_changed' : 'expense.repeat_added', $saved, null, $saved->only(['name', 'amount', 'day_of_month', 'is_active']));

        return response()->json($saved->fresh(), $existing ? 200 : 201);
    }

    /**
     * Makes this month's draft from a repeating expense, for someone to check, send for approval and pay. Nothing is
     * ever paid or counted by itself.
     */
    public function useTemplate(Request $request, int $template): JsonResponse
    {
        $user = $request->user();
        $template = ExpenseTemplate::query()->with('category')->findOrFail($template);
        $month = now()->format('Y-m');
        if (! $template->is_active) {
            throw ValidationException::withMessages(['template' => 'This repeating expense is switched off.']);
        }
        if ($template->last_offered_period === $month) {
            throw ValidationException::withMessages(['template' => 'This month’s draft has already been made from it.']);
        }
        $expense = Expense::create([
            'branch_id' => $user->branch_id, 'status' => 'draft', 'spent_on' => today()->toDateString(), 'category' => $template->category?->name ?? $template->name, 'category_id' => $template->category_id,
            'department_id' => $template->department_id, 'paid_to' => $template->paid_to, 'description' => $template->name.' for '.now()->format('F Y'),
            'amount' => (float) $template->amount, 'mode' => $template->mode ?? 'cash',
            'created_by' => $user->id, 'source_type' => 'template', 'source_id' => $template->id,
        ]);
        $template->update(['last_offered_period' => $month]);
        AuditLog::record('expense.added', null, $expense, $this->words($expense->fresh()).' · draft from a repeating expense');

        return response()->json($this->present($expense->fresh($this->with()), $user), 201);
    }

    // ---- Shared ----

    /** @return list<string> */
    private function with(): array
    {
        return ['creator:id,name', 'canceller:id,name', 'submitter:id,name', 'decider:id,name', 'payer:id,name', 'department:id,name', 'heading'];
    }

    /**
     * An expense as the screen shows it, with what this person may do to it.
     *
     * @return array<string, mixed>
     */
    private function present(Expense $e, User $user): array
    {
        $live = $e->cancelled_at === null;
        $own = in_array($user->id, [$e->created_by, $e->submitted_by], true) && $user->role->value !== 'admin';
        $large = ExpenseBook::needsHigherApproval($e->heading, (float) $e->amount);
        $today = $e->status === 'paid' && $e->paid_on?->isToday();

        return $e->only(['id', 'expense_no', 'status', 'category', 'category_id', 'department_id', 'paid_to', 'bill_no', 'description', 'amount', 'tax', 'mode', 'reference', 'cancel_reason', 'decision_note', 'source_type', 'source_id', 'receipt_name']) + [
            'spent_on' => $e->spent_on?->format('Y-m-d'), 'paid_on' => $e->paid_on?->format('Y-m-d'), 'cancelled_at' => $e->cancelled_at?->format('Y-m-d H:i'),
            'submitted_at' => $e->submitted_at?->format('Y-m-d H:i'), 'decided_at' => $e->decided_at?->format('Y-m-d H:i'),
            'creator' => $e->creator?->only(['id', 'name']), 'canceller' => $e->canceller?->only(['id', 'name']), 'submitter' => $e->submitter?->only(['id', 'name']),
            'decider' => $e->decider?->only(['id', 'name']), 'payer' => $e->payer?->only(['id', 'name']), 'department' => $e->department?->only(['id', 'name']),
            'has_receipt' => (bool) $e->receipt_path, 'posted' => $e->isPosted(),
            'needs_large_approval' => $large,
            'can' => [
                'edit' => $live && ! $e->isPosted() && in_array($e->status, ['draft', 'rejected', 'submitted', 'paid'], true) && ($e->status === 'paid' || ! $own || $user->id === $e->created_by || Permissions::allows($user, 'wf:expenses:edit')),
                'submit' => $live && in_array($e->status, ['draft', 'rejected'], true),
                'decide' => $live && $e->status === 'submitted' && ! $own && Permissions::allows($user, 'wf:expenses:approve') && (! $large || Permissions::allows($user, 'wf:expenses:finalise')),
                'pay' => $live && $e->status === 'approved',
                'cancel' => $live && ! $e->isPosted() && ($e->status === 'paid' ? ($today || Permissions::allows($user, 'wf:expenses:reverse')) : ($user->id === $e->created_by || Permissions::allows($user, 'wf:expenses:approve'))),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'category' => ['required_without:category_id', 'nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer', Rule::exists('admin_entries', 'id')->where('kind', 'department')],
            'paid_to' => ['nullable', 'string', 'max:255'],
            'bill_no' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'tax' => ['nullable', 'numeric', 'min:0', 'lte:amount'],
            'mode' => ['required', Rule::in(Payment::MODES)],
            'reference' => ['nullable', 'string', 'max:255'],
        ], [
            'spent_on.before_or_equal' => 'An expense cannot be dated in the future.', 'category.required_without' => 'Say what the money was for.',
            'tax.lte' => 'The tax is part of the amount, so it cannot be more than the amount.', 'department_id.exists' => 'Choose a department from the list.',
        ]);
        $data['tax'] = round((float) ($data['tax'] ?? 0), 2);

        return $data;
    }

    /**
     * Fills in the heading's name from a configured heading, or finds the configured heading a typed name matches,
     * and refuses one that would count salaries twice.
     *
     * @param  array<string, mixed>  $data
     */
    private function heading(array &$data): ?ExpenseCategory
    {
        $heading = null;
        if (! empty($data['category_id'])) {
            $heading = ExpenseCategory::query()->find($data['category_id']);
            if (! $heading) {
                throw ValidationException::withMessages(['category_id' => 'Choose a heading from the list.']);
            }
        } elseif (filled($data['category'] ?? null)) {
            $heading = ExpenseCategory::query()->where('name', trim($data['category']))->first();
        }
        $data['category'] = $heading?->name ?? trim((string) $data['category']);
        $data['category_id'] = $heading?->id;
        ExpenseBook::refuseDoubleCounting($heading, $data['category']);

        return $heading;
    }

    /** The headings to offer: the hospital's set-up ones, the standard ones, then any it has typed of its own. */
    private function categories(): array
    {
        $own = Expense::query()->whereNull('source_type')->distinct()->orderBy('category')->pluck('category')->all();
        $closed = ExpenseBook::payrollPosts() ? [Expense::OLD_SALARY_HEADING] : [];

        return array_values(array_diff(array_unique(array_merge(Expense::CATEGORIES, $own)), $closed, [ExpenseBook::SALARIES, ExpenseBook::ADVANCES]));
    }

    private function mayExport(User $user): bool
    {
        return $user->allows('canExportData') || Permissions::allows($user, 'wf:expenses:export');
    }

    private function maySetUp(User $user): bool
    {
        return Permissions::allows($user, 'wf:settings:admin') || Permissions::allows($user, 'wf:expenses:finalise');
    }

    private function refuseIfCancelled(Expense $expense): void
    {
        if ($expense->cancelled_at) {
            throw ValidationException::withMessages(['reason' => 'This entry has already been cancelled.']);
        }
    }

    private function refuseIfPosted(Expense $expense): void
    {
        if ($expense->isPosted()) {
            throw ValidationException::withMessages(['reason' => $expense->source_type === 'advance'
                ? 'This was written here when the advance was given. Put it right under Workforce → Advances.'
                : 'This was written here when salaries were recorded as paid. Put it right under Workforce → Payroll.']);
        }
    }

    private function refuseUnlessOwnOrEditor(Expense $expense, User $user): void
    {
        abort_unless($user->id === $expense->created_by || Permissions::allows($user, 'wf:expenses:edit'), 403, 'Only the person who entered it, or someone who may change expenses, can do this.');
    }

    /** Money already paid on an earlier day is undone only by someone who may reverse a paid expense. */
    private function refuseOldMoneyChange(Expense $expense, User $user, string $what): void
    {
        $day = $expense->paid_on ?? $expense->spent_on;
        abort_if($day && ! $day->isToday() && ! Permissions::allows($user, 'wf:expenses:reverse'), 403, "A paid expense from an earlier day is {$what} only by someone allowed to reverse paid expenses. Ask an admin.");
    }

    /**
     * Writes to the workforce record of changes as well as the HMS activity log, where the expense belongs to a
     * hospital branch. The HMS log always has it either way.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function trail(string $action, ?Model $subject, ?array $before, ?array $after, ?string $reason = null): void
    {
        if ((Tenant::id() ?? $subject?->getAttribute('branch_id') ?? auth()->user()?->branch_id) !== null) {
            Audit::record($action, $subject, $before, $after, $reason);
        }
    }

    private function rupees(mixed $amount): string
    {
        return '₹'.number_format((float) $amount, 2);
    }

    private function words(Expense $expense): string
    {
        return $expense->expense_no.' · '.$expense->category.($expense->paid_to ? ' to '.$expense->paid_to : '')
            .' · ₹'.(float) $expense->amount.' by '.str_replace('_', ' ', $expense->mode).' on '.$expense->spent_on->format('j M Y');
    }
}
