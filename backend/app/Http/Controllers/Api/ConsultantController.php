<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\ConsultantPayment;
use App\Models\ConsultantVisit;
use App\Models\Expense;
use App\Models\Setting;
use App\Models\User;
use App\Support\ConsultantFees;
use App\Workforce\Support\ExpenseBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Consultants: the visits they make to other doctors' inpatients, what the hospital owes them for a month, and what
 * it has paid them. Writing down a visit is for whoever works on the wards; the money is only for those allowed to
 * see consultants' fees.
 */
class ConsultantController extends Controller
{
    /** The heading these payments are written under in the cash book. Kept by the system; nobody types into it by hand. */
    public const HEADING = 'Consultants’ fees';

    private function seesMoney(Request $request): bool
    {
        return $request->user()->allows('canPayConsultants');
    }

    /** @return array<string, mixed> */
    private function visit(ConsultantVisit $v, Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $v->id, 'doctor' => ['id' => $v->doctor_id, 'name' => $v->doctor?->name], 'visited_on' => $v->visited_on->format('Y-m-d'), 'kind' => $v->kind, 'note' => $v->note,
            'patient' => $v->relationLoaded('patient') && $v->patient ? ['id' => $v->patient->id, 'name' => $v->patient->name, 'uhid' => $v->patient->uhid] : null, 'admission_id' => $v->admission_id,
            // An entry made by mistake is taken back by whoever made it, on the day; after that by someone who sees the fees.
            'can_cancel' => $this->seesMoney($request) || ((int) $v->created_by === (int) $user->id && $v->created_at?->isToday()),
        ] + ($this->seesMoney($request) ? ['fee' => $v->fee] : []);
    }

    // ---- On an inpatient's page

    public function visits(Request $request, Admission $admission): JsonResponse
    {
        $visits = ConsultantVisit::standing()->where('admission_id', $admission->id)->with('doctor:id,name')->orderBy('visited_on')->orderBy('id')->get();

        return response()->json(['admitted_under' => $admission->doctor_id, 'visits' => $visits->map(fn (ConsultantVisit $v) => $this->visit($v, $request))]);
    }

    public function addVisit(Request $request, Admission $admission): JsonResponse
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'doctor')], 'visited_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'note' => ['nullable', 'string', 'max:255'],
        ], ['doctor_id.required' => 'Choose the consultant who came.', 'visited_on.before_or_equal' => 'A visit cannot be written down for a day still to come.']);
        $doctor = User::findOrFail($data['doctor_id']);
        $visit = DB::transaction(fn () => ConsultantFees::visit($admission, $doctor, $data['visited_on'], $data['note'] ?? null, $request->user()));
        AuditLog::record('consultant.visit', $admission->patient_id, $visit, "{$doctor->name} · {$visit->visited_on->format('d M Y')} · ".($visit->kind === 'first' ? 'first visit' : 'later visit'));

        return response()->json($this->visit($visit->load('doctor:id,name'), $request), 201);
    }

    public function cancelVisit(Request $request, ConsultantVisit $visit): JsonResponse
    {
        abort_if($visit->cancelled_at !== null, 422, 'This visit was already taken off.');
        abort_unless($this->visit($visit, $request)['can_cancel'], 403, 'Only whoever wrote it down today, or someone who looks after consultants’ fees, can take it off.');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], ['reason.required' => 'Say why this visit is taken off.'])['reason'];
        DB::transaction(function () use ($visit, $request, $reason) {
            $visit->update(['cancelled_at' => now(), 'cancelled_by' => $request->user()->id, 'cancel_reason' => $reason]);
            // If the first visit goes, the next one becomes the first.
            ConsultantFees::settle($visit->admission_id, $visit->doctor_id);
        });
        AuditLog::record('consultant.visit_cancelled', $visit->patient_id, $visit, "{$visit->doctor?->name} · {$visit->visited_on->format('d M Y')} · Reason: {$reason}");

        return response()->json(['ok' => true]);
    }

    // ---- What is owed and what was paid

    private function monthOf(Request $request): string
    {
        return $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
    }

    public function month(Request $request): JsonResponse
    {
        $month = $this->monthOf($request);
        $rows = ConsultantFees::month($month);

        return response()->json([
            'month' => $month, 'month_name' => Carbon::parse($month.'-01')->format('F Y'), 'rates' => ConsultantFees::rates(), 'rows' => $rows,
            'totals' => ['earned' => round($rows->sum('earned'), 2), 'paid' => round($rows->sum('paid'), 2), 'due' => round($rows->sum('due'), 2)],
        ]);
    }

    /** One consultant's month in full: where each rupee earned comes from, and each payment made. */
    public function statement(Request $request, User $doctor): JsonResponse
    {
        $month = $this->monthOf($request);
        $from = Carbon::parse($month.'-01')->startOfMonth();
        $row = ConsultantFees::month($month)->firstWhere('doctor_id', $doctor->id)
            ?? ['doctor_id' => $doctor->id, 'doctor' => $doctor->name, 'department' => $doctor->department, 'shares' => [], 'share' => 0.0, 'visits' => 0, 'first_visits' => 0, 'visit_fees' => 0.0, 'earned' => 0.0, 'paid' => 0.0, 'due' => 0.0];
        $names = User::query()->pluck('name', 'id');

        return response()->json($row + [
            'month' => $month, 'month_name' => $from->format('F Y'), 'phone' => $doctor->phone,
            'visit_list' => ConsultantVisit::standing()->where('doctor_id', $doctor->id)->whereBetween('visited_on', [$from->toDateString(), $from->copy()->endOfMonth()->toDateString()])
                ->with(['doctor:id,name', 'patient:id,name,uhid'])->orderBy('visited_on')->orderBy('id')->get()->map(fn (ConsultantVisit $v) => $this->visit($v, $request)),
            'payments' => ConsultantPayment::query()->where(['doctor_id' => $doctor->id, 'for_month' => $month])->orderBy('paid_on')->orderBy('id')->get()->map(fn (ConsultantPayment $p) => [
                'id' => $p->id, 'paid_on' => $p->paid_on->format('Y-m-d'), 'amount' => $p->amount, 'mode' => $p->mode, 'reference' => $p->reference, 'note' => $p->note,
                'by' => $names[$p->created_by] ?? null, 'cancelled' => $p->cancelled_at !== null, 'cancel_reason' => $p->cancel_reason,
            ]),
        ]);
    }

    /**
     * Writes down a payment to a consultant for a month. It also goes into the cash book as a paid expense, so the
     * day's cash count sees it; a payment cannot be dated into a day whose accounts are closed.
     */
    public function pay(Request $request, User $doctor): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'], 'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'mode' => ['required', Rule::in(ConsultantPayment::MODES)], 'reference' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:255'],
        ], ['paid_on.before_or_equal' => 'A payment cannot be dated in the future.', 'amount.gt' => 'Enter the amount paid.']);
        $forMonth = Carbon::parse($data['month'].'-01')->format('F Y');

        $payment = DB::transaction(function () use ($data, $doctor, $request, $forMonth) {
            $payment = ConsultantPayment::create([
                'branch_id' => $request->user()->branch_id, 'doctor_id' => $doctor->id, 'for_month' => $data['month'], 'paid_on' => $data['paid_on'], 'amount' => round((float) $data['amount'], 2),
                'mode' => $data['mode'], 'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null, 'created_by' => $request->user()->id,
            ]);
            $expense = ExpenseBook::post('consultant_payment', $payment->id, self::HEADING, $payment->amount, $data['paid_on'], $data['mode'], $data['reference'] ?? null, $doctor->name, "Consultant's fees to {$doctor->name} for {$forMonth}".(filled($data['note'] ?? null) ? ': '.$data['note'] : ''));
            $payment->update(['expense_id' => $expense->id]);

            return $payment;
        });
        AuditLog::record('consultant.paid', null, $payment, "{$doctor->name} · ₹".number_format($payment->amount, 2)." for {$forMonth} · {$payment->mode}");

        return response()->json(['id' => $payment->id], 201);
    }

    /** Takes back a payment written down by mistake, with the reason. Its line in the cash book is cancelled with it. */
    public function cancelPayment(Request $request, ConsultantPayment $payment): JsonResponse
    {
        abort_if($payment->cancelled_at !== null, 422, 'This payment was already cancelled.');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], ['reason.required' => 'Say why this payment is cancelled.'])['reason'];
        DB::transaction(function () use ($payment, $request, $reason) {
            $stamp = ['cancelled_at' => now(), 'cancelled_by' => $request->user()->id, 'cancel_reason' => $reason];
            $payment->update($stamp);
            // The day lock applies here as to any expense: money of a closed day is not changed.
            Expense::query()->find($payment->expense_id)?->update($stamp);
        });
        AuditLog::record('consultant.payment_cancelled', null, $payment, "{$payment->doctor?->name} · ₹".number_format($payment->amount, 2)." · Reason: {$reason}");

        return response()->json(['ok' => true]);
    }

    public function saveRates(Request $request): JsonResponse
    {
        $data = $request->validate(['first_visit' => ['required', 'numeric', 'min:0', 'max:1000000'], 'later_visit' => ['required', 'numeric', 'min:0', 'max:1000000']]);
        $was = ConsultantFees::rates();
        Setting::updateOrCreate(['key' => 'consultants'], ['value' => ['first_visit' => (float) $data['first_visit'], 'later_visit' => (float) $data['later_visit']]]);
        AuditLog::record('consultant.rates', null, null, "First visit ₹{$was['first_visit']} → ₹{$data['first_visit']} · later visit ₹{$was['later_visit']} → ₹{$data['later_visit']}");

        return response()->json(ConsultantFees::rates());
    }
}
