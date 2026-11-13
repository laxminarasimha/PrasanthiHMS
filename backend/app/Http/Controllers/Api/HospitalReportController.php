<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Consultation;
use App\Models\LabOrderItem;
use App\Models\LabResult;
use App\Models\Payment;
use App\Models\PrescriptionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Front desk and doctor reports. Each answers in the same shape as the pharmacy and IPD reports
 * (title, columns, rows, totals), so one screen shows them all.
 */
class HospitalReportController extends Controller
{
    public const REPORTS = [
        'bills' => 'Front desk: bill report',
        'bill-lines' => 'Front desk: bill report, detailed',
        'collections' => 'Front desk: collections',
        'services' => 'Front desk: services',
        'appointments' => 'Front desk: all appointments',
        'upcoming' => 'Front desk: upcoming appointments',
        'missed' => 'Front desk: missed visits',
        'advised-visits' => 'Front desk: advised visits',
        'waiting-time' => 'Front desk: waiting time',
        'consultations' => 'Doctor: consultation report',
        'prescriptions' => 'Doctor: prescription report',
        'diagnoses' => 'Doctor: diagnosis report',
        'tests-advised' => 'Doctor: tests distribution',
        'test-outcomes' => 'Doctor: tests outcome',
    ];

    public function show(Request $request, string $type): JsonResponse
    {
        abort_unless($request->user()->allows('canViewReports'), 403, 'You are not allowed to view reports.');
        abort_unless(isset(self::REPORTS[$type]), 404, 'There is no such report.');

        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($range['from'] ?? today())->startOfDay();
        $to = Carbon::parse($range['to'] ?? $from)->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose a range of one year or less.');

        [$columns, $rows] = $this->{lcfirst(str_replace('-', '', ucwords($type, '-')))}($from, $to);
        $rows = collect($rows)->values();
        $totals = [];
        foreach ($columns as $column) {
            if (in_array($column['type'], ['money', 'number'], true) && $column['total']) {
                $totals[$column['key']] = round((float) $rows->sum($column['key']), 2);
            }
        }

        return response()->json([
            'type' => $type, 'title' => self::REPORTS[$type], 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'reports' => self::REPORTS,
        ]);
    }

    private static function col(string $key, string $label, string $type = 'text', bool $total = true): array
    {
        return compact('key', 'label', 'type', 'total');
    }

    private const KIND = ['appointment' => 'Appointment', 'lab' => 'Lab', 'other' => 'Other services'];

    // ---- Front desk ----

    private function bills(Carbon $from, Carbon $to): array
    {
        $rows = Bill::whereBetween('created_at', [$from, $to])->with(['patient:id,title,name,uhid', 'appointment.doctor:id,name'])->orderBy('created_at')->get()
            ->map(fn (Bill $b) => [
                'bill_no' => $b->bill_no, 'date' => $b->created_at->format('Y-m-d H:i'), 'patient' => $b->patient?->name, 'uhid' => $b->patient?->uhid,
                'doctor' => $b->appointment?->doctor?->name, 'kind' => self::KIND[$b->kind] ?? $b->kind,
                'amount' => $b->subtotal, 'discount' => $b->discount, 'total' => $b->total, 'paid' => $b->paid, 'due' => $b->due,
            ]);

        return [[
            self::col('bill_no', 'Bill'), self::col('date', 'Date'), self::col('patient', 'Patient'), self::col('uhid', 'Patient ID'), self::col('doctor', 'Doctor'),
            self::col('kind', 'Counter'), self::col('amount', 'Amount', 'money'), self::col('discount', 'Discount', 'money'),
            self::col('total', 'Bill total', 'money'), self::col('paid', 'Paid', 'money'), self::col('due', 'Due', 'money'),
        ], $rows];
    }

    private function billLines(Carbon $from, Carbon $to): array
    {
        $bills = Bill::whereBetween('created_at', [$from, $to])->with(['patient:id,title,name', 'items'])->orderBy('created_at')->get();
        $rows = $bills->flatMap(fn (Bill $b) => $b->items->map(fn (BillItem $i) => [
            'date' => $b->created_at->format('Y-m-d H:i'), 'bill_no' => $b->bill_no, 'patient' => $b->patient?->name, 'service' => $i->description,
            'quantity' => (float) $i->quantity, 'price' => $i->unit_price, 'discount' => $i->discount, 'tax' => $i->tax, 'amount' => round($i->amount + $i->tax, 2),
        ]));

        return [[
            self::col('date', 'Date'), self::col('bill_no', 'Bill'), self::col('patient', 'Patient'), self::col('service', 'Service'),
            self::col('quantity', 'Qty', 'number'), self::col('price', 'Price', 'money', false), self::col('discount', 'Discount', 'money'),
            self::col('tax', 'GST', 'money'), self::col('amount', 'Amount', 'money'),
        ], $rows];
    }

    /** Money taken or refunded at the front desk in the period, whenever the bill was made. Refunds are negative. */
    private function collections(Carbon $from, Carbon $to): array
    {
        $rows = Payment::whereBetween('created_at', [$from, $to])->whereNotIn('mode', ['ipd', 'deposit'])->with('receiver:id,name')->orderBy('created_at')->get()
            ->map(function (Payment $p) {
                $bill = Bill::with('patient:id,title,name')->find($p->bill_id);

                return [
                    'date' => $p->created_at->format('Y-m-d H:i'), 'bill_no' => $bill?->bill_no, 'patient' => $bill?->patient?->name,
                    'counter' => self::KIND[$bill?->kind] ?? null, 'type' => ucfirst($p->type),
                    'amount' => $p->type === 'refund' ? -1 * $p->amount : $p->amount, 'mode' => $p->mode, 'reference' => $p->reference, 'person' => $p->receiver?->name,
                ];
            });

        // Deposits taken or handed back are money at the desk too.
        $deposits = \App\Models\PatientDeposit::whereBetween('created_at', [$from, $to])->whereIn('type', ['deposit', 'refund'])->with(['receiver:id,name', 'patient:id,name'])->get()
            ->map(fn ($d) => [
                'date' => $d->created_at->format('Y-m-d H:i'), 'bill_no' => null, 'patient' => $d->patient?->name, 'counter' => 'Deposit',
                'type' => $d->type === 'refund' ? 'Deposit refund' : 'Deposit', 'amount' => $d->type === 'refund' ? -1 * $d->amount : $d->amount,
                'mode' => $d->mode, 'reference' => $d->reference, 'person' => $d->receiver?->name,
            ]);
        $rows = collect($rows)->concat($deposits)->sortBy('date')->values();

        return [[
            self::col('date', 'Date'), self::col('bill_no', 'Bill'), self::col('patient', 'Patient'), self::col('counter', 'Counter'), self::col('type', 'Type'),
            self::col('amount', 'Amount', 'money'), self::col('mode', 'Mode'), self::col('reference', 'Reference'), self::col('person', 'Collected by'),
        ], $rows];
    }

    /** How often each service was billed and what it brought in. */
    private function services(Carbon $from, Carbon $to): array
    {
        $rows = BillItem::whereIn('bill_id', Bill::whereBetween('created_at', [$from, $to])->select('id'))->get()
            ->groupBy('description')
            ->map(fn (Collection $lines, string $name) => [
                'service' => $name, 'times' => $lines->count(), 'quantity' => (float) $lines->sum('quantity'),
                'discount' => round($lines->sum('discount'), 2), 'amount' => round($lines->sum(fn ($l) => $l->amount + $l->tax), 2),
            ])->sortByDesc('amount');

        return [[
            self::col('service', 'Service'), self::col('times', 'Times billed', 'number'), self::col('quantity', 'Quantity', 'number'),
            self::col('discount', 'Discount', 'money'), self::col('amount', 'Amount', 'money'),
        ], $rows];
    }

    private function appointmentRows(Collection $appointments): Collection
    {
        return $appointments->map(fn (Appointment $a) => [
            'date' => Carbon::parse($a->getRawOriginal('scheduled_at'))->format('Y-m-d H:i'), 'token' => $a->token_no, 'patient' => $a->patient?->name,
            'uhid' => $a->patient?->uhid, 'mobile' => $a->patient?->mobile, 'doctor' => $a->doctor?->name, 'service' => $a->service?->name, 'status' => ucfirst($a->status),
        ]);
    }

    private const APPOINTMENT_COLUMNS = [['date', 'Date and time'], ['token', 'Token'], ['patient', 'Patient'], ['uhid', 'Patient ID'], ['mobile', 'Mobile'], ['doctor', 'Doctor'], ['service', 'Service'], ['status', 'Status']];

    private function appointmentColumns(): array
    {
        return array_map(fn ($c) => self::col($c[0], $c[1]), self::APPOINTMENT_COLUMNS);
    }

    private function appointments(Carbon $from, Carbon $to): array
    {
        return [$this->appointmentColumns(), $this->appointmentRows($this->visits($from, $to)->get())];
    }

    /** Booked visits from now to the end of the period that the patient has not yet come for. */
    private function upcoming(Carbon $from, Carbon $to): array
    {
        return [$this->appointmentColumns(), $this->appointmentRows($this->visits($from->max(now()), $to)->where('status', 'booked')->get())];
    }

    /** Booked visits whose time has passed without the patient arriving. */
    private function missed(Carbon $from, Carbon $to): array
    {
        return [$this->appointmentColumns(), $this->appointmentRows($this->visits($from, $to->min(now()))->where('status', 'booked')->get())];
    }

    private function visits(Carbon $from, Carbon $to)
    {
        return Appointment::where('scheduled_at', '>=', $from->format('Y-m-d H:i'))->where('scheduled_at', '<=', $to->format('Y-m-d H:i:s'))
            ->with(['patient:id,title,name,uhid,mobile', 'doctor:id,name', 'service:id,name'])->orderBy('scheduled_at');
    }

    /** Patients a doctor asked to come back in the period, and whether that visit has been booked. */
    private function advisedVisits(Carbon $from, Carbon $to): array
    {
        $rows = Consultation::whereNotNull('completed_at')->whereDate('follow_up_on', '>=', $from)->whereDate('follow_up_on', '<=', $to)
            ->with(['patient:id,title,name,uhid,mobile', 'doctor:id,name'])->orderBy('follow_up_on')->get()
            ->map(fn (Consultation $c) => [
                'advised' => $c->follow_up_on->format('Y-m-d'), 'patient' => $c->patient?->name, 'uhid' => $c->patient?->uhid, 'mobile' => $c->patient?->mobile,
                'doctor' => $c->doctor?->name, 'seen_on' => $c->completed_at->format('Y-m-d'), 'diagnosis' => str($c->diagnosis)->before("\n")->limit(60)->value() ?: null,
                'booked' => Appointment::where('patient_id', $c->patient_id)->whereDate('scheduled_at', $c->follow_up_on)->where('status', '!=', 'cancelled')->exists() ? 'Booked' : 'Not booked',
            ]);

        return [[
            self::col('advised', 'Advised date'), self::col('patient', 'Patient'), self::col('uhid', 'Patient ID'), self::col('mobile', 'Mobile'),
            self::col('doctor', 'Doctor'), self::col('seen_on', 'Last seen'), self::col('diagnosis', 'Diagnosis'), self::col('booked', 'Appointment'),
        ], $rows];
    }

    /** Minutes from the patient arriving to the visit being completed. */
    private function waitingTime(Carbon $from, Carbon $to): array
    {
        $rows = $this->visits($from, $to)->whereNotNull('arrived_at')->whereHas('consultation', fn ($q) => $q->whereNotNull('completed_at'))->with('consultation:id,appointment_id,completed_at')->get()
            ->map(function (Appointment $a) {
                $arrived = Carbon::parse($a->getRawOriginal('arrived_at'));

                return [
                    'date' => $arrived->format('Y-m-d'), 'patient' => $a->patient?->name, 'doctor' => $a->doctor?->name,
                    'arrived' => $arrived->format('H:i'), 'completed' => $a->consultation->completed_at->format('H:i'),
                    'minutes' => max(0, (int) round($arrived->diffInMinutes($a->consultation->completed_at))),
                ];
            });

        return [[
            self::col('date', 'Date'), self::col('patient', 'Patient'), self::col('doctor', 'Doctor'), self::col('arrived', 'Arrived'),
            self::col('completed', 'Visit completed'), self::col('minutes', 'Minutes', 'number', false),
        ], $rows];
    }

    // ---- Doctor ----

    private function completed(Carbon $from, Carbon $to)
    {
        return Consultation::whereBetween('completed_at', [$from, $to]);
    }

    /** Completed consultations per doctor per day, with how many were first visits. */
    private function consultations(Carbon $from, Carbon $to): array
    {
        $all = $this->completed($from, $to)->with('doctor:id,name')->get(['id', 'doctor_id', 'patient_id', 'completed_at']);
        $earlier = Consultation::whereIn('patient_id', $all->pluck('patient_id')->unique())->whereNotNull('completed_at')->get(['patient_id', 'completed_at'])->groupBy('patient_id')->map->min('completed_at');

        $rows = $all->groupBy(fn (Consultation $c) => $c->completed_at->format('Y-m-d').'|'.$c->doctor_id)
            ->map(function (Collection $visits) use ($earlier) {
                $new = $visits->filter(fn (Consultation $c) => $c->completed_at->equalTo($earlier[$c->patient_id]))->count();

                return [
                    'date' => $visits->first()->completed_at->format('Y-m-d'), 'doctor' => $visits->first()->doctor?->name,
                    'total' => $visits->count(), 'new' => $new, 'repeat' => $visits->count() - $new,
                ];
            })->sortKeys();

        return [[
            self::col('date', 'Date'), self::col('doctor', 'Doctor'), self::col('total', 'Consultations', 'number'),
            self::col('new', 'First visits', 'number'), self::col('repeat', 'Repeat visits', 'number'),
        ], $rows];
    }

    private function prescriptions(Carbon $from, Carbon $to): array
    {
        $rows = PrescriptionItem::whereIn('consultation_id', $this->completed($from, $to)->select('id'))->get(['medicine', 'consultation_id'])
            ->groupBy(fn ($m) => mb_strtolower(trim($m->medicine)))
            ->map(fn (Collection $lines) => ['medicine' => trim($lines->first()->medicine), 'times' => $lines->count(), 'visits' => $lines->pluck('consultation_id')->unique()->count()])
            ->sortByDesc('times');

        return [[self::col('medicine', 'Medicine'), self::col('times', 'Times prescribed', 'number'), self::col('visits', 'Visits', 'number')], $rows];
    }

    /** Each line of the diagnosis box counts as one diagnosis. */
    private function diagnoses(Carbon $from, Carbon $to): array
    {
        return [[self::col('name', 'Diagnosis'), self::col('times', 'Visits', 'number')], $this->countLines($this->completed($from, $to)->whereNotNull('diagnosis')->pluck('diagnosis'))];
    }

    /** Tests ordered from the lab for visits in the period, plus tests written as advised. */
    private function testsAdvised(Carbon $from, Carbon $to): array
    {
        $ordered = LabOrderItem::whereIn('lab_order_id', \App\Models\LabOrder::whereBetween('ordered_at', [$from, $to])->select('id'))->pluck('test_name');
        $advised = $this->completed($from, $to)->whereNotNull('tests_advised')->pluck('tests_advised')->flatMap(fn ($text) => preg_split('/[\r\n,]+/', $text));
        $count = fn (Collection $names) => $names->map(fn ($n) => trim($n))->filter()->groupBy(fn ($n) => mb_strtolower($n))->map(fn ($g) => [$g->first(), $g->count()]);
        $o = $count($ordered);
        $a = $count($advised);

        $rows = $o->keys()->merge($a->keys())->unique()->map(fn ($key) => [
            'test' => ($o[$key] ?? $a[$key])[0], 'ordered' => $o[$key][1] ?? 0, 'advised' => $a[$key][1] ?? 0,
        ])->sortByDesc(fn ($r) => $r['ordered'] + $r['advised']);

        return [[self::col('test', 'Test'), self::col('ordered', 'Ordered from our lab', 'number'), self::col('advised', 'Advised in writing', 'number')], $rows];
    }

    /** For each reported lab test: how many results came back normal, high or low. */
    private function testOutcomes(Carbon $from, Carbon $to): array
    {
        $items = LabOrderItem::whereBetween('reported_at', [$from, $to])->get(['id', 'test_name']);
        $results = LabResult::whereIn('lab_order_item_id', $items->pluck('id'))->where('is_heading', false)->whereNotNull('value')->get(['lab_order_item_id', 'parameter', 'flag']);
        $names = $items->pluck('test_name', 'id');

        $rows = $results->groupBy(fn ($r) => $names[$r->lab_order_item_id].'|'.$r->parameter)
            ->map(fn (Collection $group) => [
                'test' => $names[$group->first()->lab_order_item_id], 'parameter' => $group->first()->parameter, 'results' => $group->count(),
                'normal' => $group->whereNull('flag')->count(), 'high' => $group->where('flag', 'high')->count(), 'low' => $group->where('flag', 'low')->count(),
            ])->sortBy(fn ($r) => $r['test'].$r['parameter']);

        return [[
            self::col('test', 'Test'), self::col('parameter', 'Parameter'), self::col('results', 'Results', 'number'),
            self::col('normal', 'Normal', 'number'), self::col('high', 'High', 'number'), self::col('low', 'Low', 'number'),
        ], $rows];
    }

    /**
     * Counts how often each line appears across many text boxes, ignoring case.
     */
    private function countLines(Collection $texts): Collection
    {
        return $texts->flatMap(fn ($text) => preg_split('/[\r\n]+/', (string) $text))->map(fn ($line) => trim($line))->filter()
            ->groupBy(fn ($line) => mb_strtolower($line))
            ->map(fn (Collection $group) => ['name' => $group->first(), 'times' => $group->count()])
            ->sortByDesc('times');
    }
}
