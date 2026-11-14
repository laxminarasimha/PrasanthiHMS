<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Consultation;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabResult;
use App\Models\Patient;
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
        'cashier' => 'Collections by person (day close)',
        'services' => 'Front desk: services',
        'appointments' => 'Front desk: all appointments',
        'upcoming' => 'Front desk: upcoming appointments',
        'missed' => 'Front desk: missed visits',
        'advised-visits' => 'Front desk: advised visits',
        'waiting-time' => 'Front desk: waiting time',
        'consultations' => 'Doctor: consultation report',
        'prescriptions' => 'Doctor: prescription report',
        'diagnoses' => 'Doctor: diagnosis report',
        'diagnosis-codes' => 'Doctor: coded diagnoses (ICD-10)',
        'tests-advised' => 'Doctor: tests distribution',
        'test-outcomes' => 'Doctor: tests outcome',
        'lab-tests' => 'Lab: tests done',
        'lab-referrals' => 'Referrals: lab orders by referring doctor',
        'patient-referrals' => 'Referrals: new patients by referring doctor',
        'activity' => 'Activity log: who opened and changed records',
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

    /**
     * What each person took in across every counter (front desk, deposits, pharmacy, IPD), split by
     * how it was paid, with refunds taken off. Used to tally the cash drawer at the end of the day.
     */
    private function cashier(Carbon $from, Carbon $to): array
    {
        $entries = collect();
        $add = function ($rows, string $counter) use (&$entries) {
            foreach ($rows as $row) {
                $entries->push(['person' => $row->receiver?->name ?? 'Not recorded', 'counter' => $counter, 'mode' => $row->mode, 'amount' => $row->type === 'refund' ? -1 * $row->amount : $row->amount]);
            }
        };
        $add(Payment::whereBetween('created_at', [$from, $to])->whereNotIn('mode', ['ipd', 'deposit'])->with('receiver:id,name')->get(), 'Front desk and lab');
        $add(\App\Models\PatientDeposit::whereBetween('created_at', [$from, $to])->whereIn('type', ['deposit', 'refund'])->with('receiver:id,name')->get(), 'Deposits');
        $add(\App\Models\PharmacyPayment::whereBetween('created_at', [$from, $to])->with('receiver:id,name')->get(), 'Pharmacy');
        $add(\App\Models\AdmissionPayment::whereBetween('created_at', [$from, $to])->with('receiver:id,name')->get(), 'IPD');

        $rows = $entries->groupBy(fn ($e) => $e['person'].'|'.$e['counter'])->map(function (Collection $group) {
            $by = fn (array $modes) => round($group->whereIn('mode', $modes)->sum('amount'), 2);

            return [
                'person' => $group->first()['person'], 'counter' => $group->first()['counter'], 'entries' => $group->count(),
                'cash' => $by(['cash']), 'upi' => $by(['upi']), 'card' => $by(['card']), 'other' => $by(['cheque', 'bank_transfer', 'insurance']),
                'total' => round($group->sum('amount'), 2),
            ];
        })->sortBy(fn ($r) => $r['person'].$r['counter']);

        return [[
            self::col('person', 'Collected by'), self::col('counter', 'Counter'), self::col('entries', 'Entries', 'number'),
            self::col('cash', 'Cash', 'money'), self::col('upi', 'UPI', 'money'), self::col('card', 'Card', 'money'),
            self::col('other', 'Cheque / bank / insurance', 'money'), self::col('total', 'Total', 'money'),
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

    /**
     * Consultations per doctor per day, with how many were first visits. A visit counts once the
     * patient has been seen: the doctor finished the visit pad, or the desk marked the appointment
     * Reviewed for a doctor who wrote the notes elsewhere.
     */
    private function consultations(Carbon $from, Carbon $to): array
    {
        $when = fn (Appointment $a) => $a->consultation?->completed_at ?? $a->scheduled_at;
        $with = ['doctor:id,name', 'consultation:id,appointment_id,completed_at'];
        $columns = ['id', 'doctor_id', 'patient_id', 'scheduled_at'];

        $all = Appointment::where(fn ($q) => $q
            ->where(fn ($q) => $q->whereBetween('scheduled_at', [$from, $to])->where('status', 'reviewed'))
            ->orWhereHas('consultation', fn ($q) => $q->whereBetween('completed_at', [$from, $to])))
            ->with($with)->get($columns);

        // Each patient's very first visit, to tell first visits from repeat ones.
        $first = Appointment::whereIn('patient_id', $all->pluck('patient_id')->unique())
            ->where(fn ($q) => $q->where('status', 'reviewed')->orWhereHas('consultation', fn ($q) => $q->whereNotNull('completed_at')))
            ->with('consultation:id,appointment_id,completed_at')->get($columns)
            ->groupBy('patient_id')->map(fn (Collection $visits) => $visits->sortBy($when)->first()->id);

        // The day shown is the day the pad was finished when that falls in the range, otherwise the appointment's day.
        $day = function (Appointment $a) use ($from, $to) {
            $done = $a->consultation?->completed_at;

            return ($done && $done->between($from, $to) ? $done : $a->scheduled_at)->format('Y-m-d');
        };

        $rows = $all->groupBy(fn (Appointment $a) => $day($a).'|'.$a->doctor_id)
            ->map(function (Collection $visits) use ($first, $day) {
                $new = $visits->filter(fn (Appointment $a) => $first[$a->patient_id] === $a->id)->count();

                return [
                    'date' => $day($visits->first()), 'doctor' => $visits->first()->doctor?->name,
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

    /** How many visits and patients carried each ICD-10 code. */
    private function diagnosisCodes(Carbon $from, Carbon $to): array
    {
        $rows = $this->completed($from, $to)->whereNotNull('diagnosis_codes')->get(['patient_id', 'diagnosis_codes'])
            ->flatMap(fn ($c) => collect($c->diagnosis_codes)->map(fn ($d) => $d + ['patient_id' => $c->patient_id]))
            ->groupBy('code')
            ->map(fn (Collection $group, $code) => ['code' => $code, 'name' => $group->first()['name'], 'visits' => $group->count(), 'patients' => $group->pluck('patient_id')->unique()->count()])
            ->sortByDesc('visits');

        return [[self::col('code', 'Code'), self::col('name', 'Diagnosis'), self::col('visits', 'Visits', 'number'), self::col('patients', 'Patients', 'number', false)], $rows];
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

    /** Who opened or changed which record. */
    private function activity(Carbon $from, Carbon $to): array
    {
        $rows = \App\Models\AuditLog::whereBetween('created_at', [$from, $to])->latest('id')->limit(5000)->get();
        $patients = Patient::whereIn('id', $rows->pluck('patient_id')->filter()->unique())->get(['id', 'uhid', 'name'])->keyBy('id');

        return [[
            self::col('when', 'When'), self::col('who', 'Who'), self::col('action', 'What they did'), self::col('patient', 'Patient'), self::col('details', 'Details'),
        ], $rows->map(fn ($r) => [
            'when' => $r->created_at->format('Y-m-d H:i'), 'who' => $r->user_name, 'patient' => isset($patients[$r->patient_id]) ? $patients[$r->patient_id]->uhid.' '.$patients[$r->patient_id]->name : null,
        ] + \App\Models\AuditLog::words($r))];
    }

    // ---- Lab and referrals ----

    /** How many times each lab test was ordered and reported, and what it billed. */
    private function labTests(Carbon $from, Carbon $to): array
    {
        $rows = LabOrderItem::whereIn('lab_order_id', LabOrder::whereBetween('ordered_at', [$from, $to])->select('id'))
            ->get(['test_name', 'department', 'price', 'reported_at'])
            ->groupBy('test_name')
            ->map(fn (Collection $group, $name) => [
                'test' => $name, 'department' => $group->first()->department, 'ordered' => $group->count(),
                'reported' => $group->whereNotNull('reported_at')->count(), 'amount' => round((float) $group->sum('price'), 2),
            ])->sortByDesc('ordered');

        return [[
            self::col('test', 'Test'), self::col('department', 'Department'), self::col('ordered', 'Ordered', 'number'),
            self::col('reported', 'Reported', 'number'), self::col('amount', 'Amount', 'money'),
        ], $rows];
    }

    /** Lab work sent by each outside doctor: orders, tests and their value. */
    private function labReferrals(Carbon $from, Carbon $to): array
    {
        $rows = LabOrder::whereBetween('ordered_at', [$from, $to])->with('items:id,lab_order_id,price')->get(['id', 'referred_by', 'patient_id'])
            ->groupBy(fn ($o) => mb_strtolower(trim((string) $o->referred_by)))
            ->map(fn (Collection $group) => [
                'referred_by' => trim((string) $group->first()->referred_by) ?: 'Not referred (self)',
                'orders' => $group->count(), 'patients' => $group->pluck('patient_id')->unique()->count(),
                'tests' => $group->sum(fn ($o) => $o->items->count()), 'amount' => round((float) $group->sum(fn ($o) => $o->items->sum('price')), 2),
            ])->sortByDesc('amount');

        return [[
            self::col('referred_by', 'Referred by'), self::col('orders', 'Lab orders', 'number'), self::col('patients', 'Patients', 'number'),
            self::col('tests', 'Tests', 'number'), self::col('amount', 'Amount', 'money'),
        ], $rows];
    }

    /** New patients registered in the period, by who sent them, with what they have been billed since. */
    private function patientReferrals(Carbon $from, Carbon $to): array
    {
        $patients = Patient::whereBetween('created_at', [$from, $to])->get(['id', 'referred_by']);
        $billed = Bill::whereIn('patient_id', $patients->pluck('id'))->selectRaw('patient_id, sum(total) as total')->groupBy('patient_id')->pluck('total', 'patient_id');

        $rows = $patients->groupBy(fn ($p) => mb_strtolower(trim((string) $p->referred_by)))
            ->map(fn (Collection $group) => [
                'referred_by' => trim((string) $group->first()->referred_by) ?: 'Not referred (self)',
                'patients' => $group->count(),
                'billed' => round((float) $group->sum(fn ($p) => (float) ($billed[$p->id] ?? 0)), 2),
            ])->sortByDesc('patients');

        return [[self::col('referred_by', 'Referred by'), self::col('patients', 'New patients', 'number'), self::col('billed', 'Billed to them so far', 'money')], $rows];
    }

    /**
     * Finds patients by who they are and what was found or given: age, gender, visit dates,
     * a diagnosis, a medicine, or a lab value in a range. For recalls, camps and audits.
     */
    public function findPatients(Request $request): JsonResponse
    {
        abort_unless($request->user()->allows('canViewReports'), 403, 'You are not allowed to view reports.');
        $f = $request->validate([
            'age_from' => ['nullable', 'integer', 'between:0,130'],
            'age_to' => ['nullable', 'integer', 'between:0,130'],
            'gender' => ['nullable', 'in:male,female,other'],
            'visit_from' => ['nullable', 'date'],
            'visit_to' => ['nullable', 'date', 'after_or_equal:visit_from'],
            'diagnosis' => ['nullable', 'string', 'max:100'],
            'icd' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z][0-9A-Za-z.]*$/'],
            'medicine' => ['nullable', 'string', 'max:100'],
            'test' => ['nullable', 'string', 'max:100'],
            'value_from' => ['nullable', 'numeric'],
            'value_to' => ['nullable', 'numeric'],
        ]);
        // Each limit may be given alone; only a pair has to be the right way round.
        if (isset($f['age_from'], $f['age_to']) && $f['age_to'] < $f['age_from']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['age_to' => 'The upper age cannot be below the lower age.']);
        }
        if (isset($f['value_from'], $f['value_to']) && $f['value_to'] < $f['value_from']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['value_to' => 'The upper value cannot be below the lower value.']);
        }
        $like = fn (string $text) => '%'.str_replace(['%', '_'], ['\%', '\_'], trim($text)).'%';
        $from = isset($f['visit_from']) ? Carbon::parse($f['visit_from'])->startOfDay() : null;
        $to = isset($f['visit_to']) ? Carbon::parse($f['visit_to'])->endOfDay() : null;
        $inRange = fn ($q, string $column) => $q->when($from, fn ($q) => $q->where($column, '>=', $from))->when($to, fn ($q) => $q->where($column, '<=', $to));

        $query = Patient::query()
            ->when($f['gender'] ?? null, fn ($q, $g) => $q->where('gender', $g))
            // Aged N today means born after today minus N+1 years, up to today minus N years.
            ->when(isset($f['age_from']), fn ($q) => $q->whereDate('date_of_birth', '<=', today()->subYears((int) $f['age_from'])))
            ->when(isset($f['age_to']), fn ($q) => $q->whereDate('date_of_birth', '>', today()->subYears((int) $f['age_to'] + 1)));

        $visits = filled($f['diagnosis'] ?? null) || filled($f['icd'] ?? null) || filled($f['medicine'] ?? null) || $from || $to;
        if ($visits) {
            $query->whereIn('id', Consultation::query()
                ->tap(fn ($q) => $inRange($q, 'created_at'))
                ->when(filled($f['diagnosis'] ?? null), fn ($q) => $q->where('diagnosis', 'like', $like($f['diagnosis'])))
                // "E11" finds E11 and everything under it (E11.9 and so on).
                ->when(filled($f['icd'] ?? null), fn ($q) => $q->where('diagnosis_codes', 'like', '%"code":"'.strtoupper(trim($f['icd'])).'%'))
                ->when(filled($f['medicine'] ?? null), fn ($q) => $q->whereIn('id', PrescriptionItem::where('medicine', 'like', $like($f['medicine']))->select('consultation_id')))
                ->select('patient_id'));
        }

        $values = collect();
        if (filled($f['test'] ?? null)) {
            // Lab values are kept as text ("12.4", "Positive"), so the range is checked here rather than in the database.
            $results = LabResult::query()
                ->join('lab_order_items as i', 'i.id', '=', 'lab_results.lab_order_item_id')
                ->join('lab_orders as o', 'o.id', '=', 'i.lab_order_id')
                ->whereNotNull('i.reported_at')->where('lab_results.is_heading', false)->whereNotNull('lab_results.value')
                ->where(fn ($q) => $q->where('i.test_name', 'like', $like($f['test']))->orWhere('lab_results.parameter', 'like', $like($f['test'])))
                ->tap(fn ($q) => $inRange($q, 'i.reported_at'))
                ->orderBy('i.reported_at')
                ->get(['o.patient_id', 'lab_results.parameter', 'lab_results.value', 'lab_results.unit', 'i.reported_at']);
            $ranged = isset($f['value_from']) || isset($f['value_to']);
            $values = $results->filter(function ($r) use ($f, $ranged) {
                if (! $ranged) {
                    return true;
                }
                $number = str_replace(',', '', trim((string) $r->value));

                return is_numeric($number) && (! isset($f['value_from']) || (float) $number >= (float) $f['value_from']) && (! isset($f['value_to']) || (float) $number <= (float) $f['value_to']);
            })->groupBy('patient_id')->map(fn (Collection $g) => $g->last()); // the latest matching result of each patient
            $query->whereIn('id', $values->keys());
        }

        $total = (clone $query)->count();
        $patients = $query->orderBy('name')->limit(2000)->get(['id', 'uhid', 'title', 'name', 'gender', 'date_of_birth', 'mobile', 'city']);
        $seen = Consultation::whereIn('patient_id', $patients->pluck('id'))->selectRaw('patient_id, count(*) as visits, max(created_at) as last_visit')->groupBy('patient_id')->get()->keyBy('patient_id');

        $columns = [
            self::col('uhid', 'Patient ID'), self::col('name', 'Name'), self::col('age', 'Age'), self::col('gender', 'Gender'), self::col('mobile', 'Mobile'), self::col('city', 'City'),
            self::col('visits', 'Visits', 'number', false), self::col('last_visit', 'Last visit'),
        ];
        if (filled($f['test'] ?? null)) {
            $columns[] = self::col('result', 'Latest matching result');
        }

        return response()->json([
            'columns' => $columns,
            'total' => $total,
            'all_patients' => Patient::count(),
            'rows' => $patients->map(fn (Patient $p) => [
                'id' => $p->id, 'uhid' => $p->uhid, 'name' => trim(($p->title ? $p->title.' ' : '').$p->name), 'age' => $p->age_label, 'gender' => ucfirst((string) $p->gender),
                'mobile' => $p->mobile, 'city' => $p->city, 'visits' => (int) ($seen[$p->id]->visits ?? 0),
                'last_visit' => isset($seen[$p->id]) ? substr((string) $seen[$p->id]->last_visit, 0, 10) : null,
                'result' => isset($values[$p->id]) ? trim($values[$p->id]->parameter.' '.$values[$p->id]->value.' '.$values[$p->id]->unit).' ('.substr((string) $values[$p->id]->reported_at, 0, 10).')' : null,
            ]),
        ]);
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
