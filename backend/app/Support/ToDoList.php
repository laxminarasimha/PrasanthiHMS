<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Bill;
use App\Models\ConsultantPayment;
use App\Models\ConsultantVisit;
use App\Models\Consultation;
use App\Models\DayClose;
use App\Models\DoctorBlock;
use App\Models\Expense;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\PastRecord;
use App\Models\PharmacySale;
use App\Models\RepSample;
use App\Models\RepVisit;
use App\Models\StockCount;
use App\Models\User;
use App\Workforce\Models\Attendance;
use App\Workforce\Models\AttendanceCorrection;
use App\Workforce\Models\Employee;
use App\Workforce\Models\LeaveRequest;
use App\Workforce\Models\PayrollRun;
use App\Workforce\Models\Payslip;
use App\Workforce\Support\Access;
use App\Workforce\Support\Period;
use App\Workforce\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The work the system itself knows is waiting for one person, by what that person is allowed to do: patients
 * waiting for a doctor, results a lab has to enter, bills with money due, medicines to order, leave to decide.
 * Nothing here is written by anyone; each line is read from the records as they stand, and goes away by itself
 * when the work is done. Each line points at the screen where it is done.
 *
 * A person only ever gets the lists of work they may do, so this shows nothing the menu would not.
 */
class ToDoList
{
    /** The most lines listed under one heading; the count is always the full number. */
    private const LIST = 8;

    /** How far back unfinished work is looked for. Older than this it is a report, not something to do today. */
    private const DAYS = 14;

    /**
     * Days a test may be out at another lab before its result counts as late. Nothing in the hospital's setup says
     * how long an outside lab takes, so this is a plain rule of thumb.
     */
    private const OUTSIDE_LATE_DAYS = 2;

    /** How many finished months back consultants' fees still due are looked for. */
    private const CONSULTANT_MONTHS = 3;

    /**
     * @return list<array{key: string, label: string, items: list<array<string, mixed>>}>
     */
    public static function for(User $user): array
    {
        $sections = [
            ['key' => 'doctor', 'label' => 'Your patients', 'items' => self::doctor($user)],
            ['key' => 'front_desk', 'label' => 'Front desk', 'items' => self::frontDesk($user)],
            ['key' => 'lab', 'label' => 'Lab', 'items' => self::lab($user)],
            ['key' => 'pharmacy', 'label' => 'Pharmacy', 'items' => self::pharmacy($user)],
            ['key' => 'ipd', 'label' => 'IPD', 'items' => self::ipd($user)],
            ['key' => 'office', 'label' => 'Office', 'items' => self::office($user)],
        ];

        // A heading with nothing under it is left out: the screen shows what is waiting, not what is not.
        return collect($sections)->map(fn (array $s) => ['items' => array_values(array_filter($s['items'], fn (array $i) => $i['count'] > 0))] + $s)
            ->filter(fn (array $s) => $s['items'] !== [])->values()->all();
    }

    private static function who(?object $patient, ?string $fallback = null): string
    {
        return $patient ? trim(($patient->title ? $patient->title.' ' : '').$patient->name) : ($fallback ?: 'Walk-in');
    }

    private static function rupees(float $amount): string
    {
        return '₹'.rtrim(rtrim(number_format($amount, 2), '0'), '.');
    }

    /**
     * One kind of waiting work. `urgent` is for what should not wait until later in the day.
     *
     * @param  iterable<array{text: string, detail: ?string, to: ?string}>  $rows
     * @return array<string, mixed>
     */
    private static function item(string $key, string $label, string $help, string $to, iterable $rows, bool $urgent = false, ?int $count = null): array
    {
        $rows = collect($rows)->values();

        return ['key' => $key, 'label' => $label, 'help' => $help, 'to' => $to, 'urgent' => $urgent, 'count' => $count ?? $rows->count(), 'rows' => $rows->take(self::LIST)->all()];
    }

    /** A doctor's own day: who is waiting, which visits are not written up, who is admitted under them. */
    private static function doctor(User $user): array
    {
        if ($user->role !== Role::Doctor || ! $user->allows('canConsult')) {
            return [];
        }
        $with = ['patient:id,uhid,title,name', 'consultation:id,appointment_id,completed_at'];
        $today = Appointment::where('doctor_id', $user->id)->whereDate('scheduled_at', today())->with($with)->orderBy('token_no')->get();
        $earlier = Appointment::where('doctor_id', $user->id)->where('scheduled_at', '>=', today()->subDays(self::DAYS))->where('scheduled_at', '<', today())
            ->whereIn('status', ['ongoing', 'reviewed'])->with($with)->orderBy('scheduled_at')->get();
        $row = fn (Appointment $a, string $detail, ?string $to = null) => [
            'text' => ($a->token_no ? "Token {$a->token_no} · " : '').self::who($a->patient), 'detail' => $detail, 'to' => $to ?? "/visits/{$a->id}",
        ];

        $items = [
            self::item('waiting', 'Patients waiting for you', 'They have arrived and are waiting to be seen.', '/doctor',
                $today->where('status', 'arrived')->map(fn (Appointment $a) => $row($a, 'Arrived '.($a->arrived_at ? Carbon::parse($a->arrived_at)->format('g:i A') : $a->scheduled_at->format('g:i A')))), true),
            self::item('unfinished', 'Visits started and not finished', 'Finish the visit pad so the prescription and advice are on record.', '/doctor',
                $today->where('status', 'ongoing')->concat($earlier->where('status', 'ongoing'))->map(fn (Appointment $a) => $row($a, 'Started '.$a->scheduled_at->format('j M, g:i A')))),
            self::item('no_pad', 'Seen, but nothing written', 'Marked as seen at the desk, with no visit pad from you.', '/doctor',
                $today->where('status', 'reviewed')->concat($earlier->where('status', 'reviewed'))->filter(fn (Appointment $a) => ! $a->consultation?->completed_at)
                    ->map(fn (Appointment $a) => $row($a, $a->scheduled_at->format('j M, g:i A')))),
            self::item('to_come', 'Booked for today, not here yet', 'Still to come today.', '/doctor',
                $today->where('status', 'booked')->map(fn (Appointment $a) => $row($a, 'Booked for '.$a->scheduled_at->format('g:i A').($a->booked_via === 'app' ? ' · from the patient app' : ''), '/doctor'))),
        ];

        // Medical representatives who have come to meet this doctor today and are still waiting.
        $reps = RepVisit::where('doctor_id', $user->id)->where('status', 'waiting')->whereDate('arrived_at', today())->with(['rep:id,rep_company_id,name', 'rep.company:id,name'])->orderBy('arrived_at')->get();
        $ready = LabOrder::unseenBy($user->id)->where('ready_at', '>=', today()->subDays(self::DAYS))->with(['patient:id,uhid,title,name', 'items:id,lab_order_id,test_name,reported_at', 'items.results:id,lab_order_item_id,flag'])->latest('ready_at')->get();
        $outside = fn (LabOrder $o) => $o->items->flatMap->results->whereIn('flag', ['high', 'low'])->count();
        $items[] = self::item('results_ready', 'Lab results ready to see', 'The tests you asked for are back. Open the report; it leaves this list once you have looked.', '/doctor/results',
            $ready->map(fn (LabOrder $o) => ['text' => self::who($o->patient), 'to' => '/doctor/results',
                'detail' => $o->items->whereNotNull('reported_at')->pluck('test_name')->unique()->take(4)->implode(', ').($outside($o) ? ' · '.$outside($o).' outside the normal range' : '').' · '.$o->ready_at->format('j M, g:i A')]),
            $ready->contains(fn (LabOrder $o) => $outside($o) > 0));
        $items[] = self::item('reps', 'Medical representatives waiting', 'They are logged at the desk; see them when you are free, or ask them to come another day.', '/reps',
            $reps->map(fn (RepVisit $v) => ['text' => $v->rep->name.' · '.$v->rep->company->name, 'detail' => 'Came at '.$v->arrived_at->format('g:i A'), 'to' => '/reps']));

        if ($user->allows('canWorkIpd')) {
            $stays = Admission::where('doctor_id', $user->id)->where('status', 'admitted')->with('patient:id,uhid,title,name')->orderBy('admitted_at')->get();
            $items[] = self::item('inpatients', 'Your inpatients', 'Admitted under you now.', '/ipd', $stays->map(fn (Admission $a) => [
                'text' => "{$a->ipd_no} · ".self::who($a->patient), 'detail' => 'Admitted '.$a->admitted_at->format('j M Y'), 'to' => "/ipd/{$a->id}",
            ]));
        }

        return $items;
    }

    private static function frontDesk(User $user): array
    {
        if (! $user->allows('canWorkFrontDesk')) {
            return [];
        }
        $visits = Appointment::whereDate('scheduled_at', today())->whereIn('status', ['booked', 'arrived'])
            ->with(['patient:id,uhid,title,name', 'doctor:id,name'])->orderBy('scheduled_at')->get();
        $visit = fn (Appointment $a) => [
            'text' => ($a->token_no ? "Token {$a->token_no} · " : '').self::who($a->patient),
            'detail' => ($a->doctor?->name ?? 'No doctor').' · '.$a->scheduled_at->format('g:i A'), 'to' => '/front-desk',
        ];
        $labBills = LabOrder::whereNotNull('bill_id')->where('ordered_at', '>=', today()->subDays(self::DAYS + 1))->pluck('bill_id');
        $dues = Bill::where('created_at', '>=', today()->subDays(self::DAYS))->whereNull('cancelled_at')->whereColumn('paid', '<', 'total')->whereNotIn('id', $labBills)
            ->with('patient:id,uhid,title,name')->withIpdTransfers()->latest()->get()->filter(fn (Bill $b) => $b->due > 0);
        $bill = fn (Bill $b) => [
            'text' => "{$b->bill_no} · ".self::who($b->patient), 'detail' => self::rupees($b->due).' due · '.$b->created_at->format('j M'), 'to' => "/patients/{$b->patient_id}",
        ];
        [$todays, $older] = $dues->partition(fn (Bill $b) => $b->created_at->isToday());
        $late = FollowUps::notReturned(today()->subDays(self::DAYS)->startOfDay(), today()->endOfDay());

        $items = [
            self::item('late', 'Booked and not arrived, time gone', 'Ring them, or cancel the visit if they are not coming.', '/front-desk',
                $visits->where('status', 'booked')->filter(fn (Appointment $a) => $a->scheduled_at->lt(now()))->map($visit)),
            self::item('desk_dues', 'Today’s bills with money due', 'Collect before the patient leaves.', '/front-desk', $todays->map($bill), true),
            self::item('desk_old_dues', 'Earlier bills still unpaid', 'From the last '.self::DAYS.' days. Collect, or give a discount for what will not come.', '/front-desk/bills', $older->map($bill)),
            self::item('follow_ups', 'Asked to come back, not come', 'Patients a doctor asked to return in the last '.self::DAYS.' days who have not been seen since.', '/front-desk',
                $late->map(fn (array $r) => ['text' => self::who($r['patient'] ?? null), 'detail' => 'Was due '.Carbon::parse($r['follow_up_on'])->format('j M').(($r['doctor'] ?? null) ? ' · '.$r['doctor']->name : ''), 'to' => isset($r['patient']) ? '/patients/'.$r['patient']->id : null])),
        ];
        // The two lines below name a doctor's patients, so a doctor is shown only their own.
        $doctorOnly = $user->role === Role::Doctor ? $user->id : null;

        // Tests a doctor advised at a visit finished today that the lab has not been asked for, of those our own lab
        // does, under the lab's names. The rest were advised to be done outside, and are in the tests-not-done report.
        $tests = TestsNotDone::ourLab(TestsNotDone::between(today()->startOfDay(), today()->endOfDay(), $doctorOnly));
        $items[] = self::item('tests_not_ordered', 'Tests advised today, no lab order', 'A doctor advised these tests at today’s visit and the lab has not been asked for them. Make the lab order from the Tests button on the day list. Tests our lab does not do are left out.', '/front-desk',
            $tests->map(fn (array $r) => [
                'text' => ($r['visit']->appointment?->token_no ? "Token {$r['visit']->appointment->token_no} · " : '').self::who($r['visit']->patient),
                'detail' => ($r['visit']->doctor?->name ?? 'Doctor').' · '.$r['missing']->take(4)->implode(', ').($r['missing']->count() > 4 ? ' and '.($r['missing']->count() - 4).' more' : ''),
                'to' => '/front-desk',
            ]));
        // Patients booked for a doctor on a day that doctor is now away: someone has to ring them and move the visit.
        $blocks = DoctorBlock::whereDate('to_date', '>=', today())->with('doctor:id,name')->get();
        $stranded = $blocks->flatMap(fn (DoctorBlock $b) => $b->booked()->where('status', 'booked')->filter(fn (Appointment $a) => $a->scheduled_at->gte(today()))
            ->map(fn (Appointment $a) => ['id' => $a->id, 'text' => self::who($a->patient), 'detail' => ($b->doctor?->name ?? 'The doctor').' is away · booked for '.$a->scheduled_at->format('j M, g:i A'), 'to' => '/front-desk/calendar']))
            ->unique('id')->map(fn (array $row) => array_diff_key($row, ['id' => 1]))->values();
        $items[] = self::item('doctor_away', 'Booked on a day the doctor is away', 'Ring the patient and move the visit to another day or doctor.', '/front-desk/calendar', $stranded, true);

        // Visits patients booked on their own phone, still to come today or tomorrow. One whose time has gone is under
        // "Booked and not arrived". Never the note the patient typed, nor their number: the patient's page has that.
        $fromApp = Appointment::where('booked_via', 'app')->where('status', 'booked')->where('scheduled_at', '>=', now())->where('scheduled_at', '<', today()->addDays(2))
            ->when($doctorOnly, fn ($q) => $q->where('doctor_id', $doctorOnly))->with(['patient:id,uhid,title,name', 'doctor:id,name'])->orderBy('scheduled_at')->get();
        $items[] = self::item('app_bookings', 'Booked from the patient app', 'Patients booked these on their own phone. Ring them if anything needs checking, and see who they are when they come.', '/front-desk/calendar',
            $fromApp->map(fn (Appointment $a) => [
                'text' => self::who($a->patient),
                'detail' => ($a->doctor?->name ?? 'No doctor').' · '.($a->scheduled_at->isToday() ? 'Today ' : 'Tomorrow ').$a->scheduled_at->format('g:i A').($a->token_no ? " · Token {$a->token_no}" : ''),
                'to' => '/patients/'.$a->patient_id,
            ]));

        // Representatives asked to come back today who have not been logged yet: the desk knows whom to expect.
        $due = RepVisit::where('status', 'come_back')->whereDate('next_on', today())->with(['rep:id,rep_company_id,name', 'rep.company:id,name', 'doctor:id,name'])->get()
            ->reject(fn (RepVisit $v) => RepVisit::where('medical_rep_id', $v->medical_rep_id)->whereDate('arrived_at', today())->exists());
        $items[] = self::item('reps_due', 'Medical representatives asked to come today', 'Log them under Medical reps when they arrive.', '/reps',
            $due->map(fn (RepVisit $v) => ['text' => $v->rep->name.' · '.$v->rep->company->name, 'detail' => 'To meet '.($v->doctor?->name ?? 'the doctor'), 'to' => '/reps']));

        // Samples left by representatives that are about to go out of date, or already have, and are still on the shelf.
        $samples = RepSample::whereNotNull('expiry')->whereDate('expiry', '<=', today()->addDays(30))->with(['company:id,name', 'issues'])->orderBy('expiry')->get()->filter(fn (RepSample $s) => $s->in_hand > 0);
        $items[] = self::item('samples_expiring', 'Samples expired or expiring within 30 days', 'Give them out while they are in date, or throw away what has expired.', '/reps?tab=samples',
            $samples->map(fn (RepSample $s) => ['text' => $s->name.' · '.($s->company?->name ?? ''), 'detail' => $s->in_hand.' left · '.($s->expiry->lt(today()) ? 'expired ' : 'expires ').$s->expiry->format('j M Y'), 'to' => '/reps?tab=samples']));
        if ($user->allows('canFilePastRecords')) {
            $items[] = self::item('old_papers', 'Old papers waiting to be checked', 'Scanned and read; someone has to check them and file them.', '/past-records', [], false, PastRecord::where('status', 'to_check')->count());
        }

        return $items;
    }

    private static function lab(User $user): array
    {
        if (! $user->allows('canRunLab')) {
            return [];
        }
        $orders = LabOrder::where('ordered_at', '>=', today()->subDays(self::DAYS))->where('status', '!=', 'cancelled')
            ->with('patient:id,uhid,title,name')->get(['id', 'order_no', 'patient_id', 'ordered_at', 'admission_id', 'appointment_id'])->keyBy('id');
        $items = LabOrderItem::whereIn('lab_order_id', $orders->keys())->whereNull('approved_at')->orderBy('id')->get();
        // One line per order, naming its tests at that stage.
        $byOrder = fn (Collection $rows) => $rows->groupBy('lab_order_id')->map(fn (Collection $same, $orderId) => [
            'text' => $orders[$orderId]->order_no.' · '.self::who($orders[$orderId]->patient),
            'detail' => $same->pluck('test_name')->take(4)->implode(', ').($same->count() > 4 ? ' and '.($same->count() - 4).' more' : '').' · '.$orders[$orderId]->ordered_at->format('j M, g:i A'),
            'to' => '/lab',
        ])->values();
        // A sample sent to an outside lab is waited for there, not entered here: it is on its own line, and counted once.
        $sentOut = fn (LabOrderItem $i) => filled($i->outside_lab) && $i->sent_at && ! $i->reported_at;
        $toCollect = $items->filter(fn (LabOrderItem $i) => ! $i->collected_at && ! $i->reported_at);
        $toReport = $items->filter(fn (LabOrderItem $i) => $i->collected_at && ! $i->reported_at && ! $sentOut($i));
        $toApprove = $items->filter(fn (LabOrderItem $i) => $i->reported_at);
        $away = self::ownPatientsOnly($user, $items->filter($sentOut), $orders);

        $out = [
            self::item('samples', 'Samples to collect', 'Ordered, and the sample is not marked as collected.', '/lab', $byOrder($toCollect), true, $toCollect->count()),
            self::item('results', 'Results to enter', 'The sample is collected; the result is not entered yet.', '/lab', $byOrder($toReport), false, $toReport->count()),
            // Never what the outside lab charges: only which lab, which test and since when.
            self::item('outside', 'Sent to an outside lab, result not back', 'The sample has gone to another lab. Ring them for the result if it is late, then enter it here.', '/lab',
                $away->groupBy('lab_order_id')->map(fn (Collection $same, $orderId) => [
                    'text' => $orders[$orderId]->order_no.' · '.self::who($orders[$orderId]->patient),
                    'detail' => $same->map(fn (LabOrderItem $i) => $i->label().' at '.$i->outside_lab)->take(3)->implode(', ').($same->count() > 3 ? ' and '.($same->count() - 3).' more' : '')
                        .' · sent '.$same->sortBy('sent_at')->first()->sent_at->format('j M, g:i A'),
                    'to' => '/lab?open='.$orderId,
                ])->values(),
                $away->contains(fn (LabOrderItem $i) => $i->sent_at->lt(now()->subDays(self::OUTSIDE_LATE_DAYS))), $away->count()),
        ];
        if ($user->allows('canApproveLab')) {
            $out[] = self::item('approve', 'Results to approve', 'Entered, and waiting for approval before the report goes out.', '/lab', $byOrder($toApprove), false, $toApprove->count());
        }

        return $out;
    }

    /**
     * For a doctor, only the tests of their own patients: whose the order is goes by the IPD case it was made from,
     * else the visit it was made at. An order made at the lab counter is nobody's here. Anyone else gets them all.
     *
     * @param  Collection<int, LabOrderItem>  $tests
     * @param  Collection<int, LabOrder>  $orders  by id
     * @return Collection<int, LabOrderItem>
     */
    private static function ownPatientsOnly(User $user, Collection $tests, Collection $orders): Collection
    {
        if ($user->role !== Role::Doctor || $tests->isEmpty()) {
            return $tests;
        }
        $theirs = $orders->only($tests->pluck('lab_order_id')->unique()->all());
        $admittedUnder = Admission::whereIn('id', $theirs->pluck('admission_id')->filter())->pluck('doctor_id', 'id');
        $seenBy = Appointment::whereIn('id', $theirs->pluck('appointment_id')->filter())->pluck('doctor_id', 'id');

        return $tests->filter(function (LabOrderItem $i) use ($orders, $admittedUnder, $seenBy, $user) {
            $order = $orders[$i->lab_order_id];
            $doctor = ($order->admission_id ? ($admittedUnder[$order->admission_id] ?? null) : null) ?? ($order->appointment_id ? ($seenBy[$order->appointment_id] ?? null) : null);

            return (int) $doctor === (int) $user->id;
        });
    }

    private static function pharmacy(User $user): array
    {
        // Stock counts are approved by those trusted with the audit, who may be allowed only to look at the rest of the pharmacy.
        $audit = $user->allows('canAuditPharmacy') && ($user->allows('canRunPharmacy') || $user->allows('canViewPharmacy')) ? self::pharmacyAudit() : [];
        if (! $user->allows('canRunPharmacy')) {
            return $audit;
        }
        // Today's prescriptions with medicines, for which no pharmacy bill has been made since.
        $written = Consultation::whereDate('completed_at', today())->whereHas('medicines')->with(['patient:id,uhid,title,name', 'doctor:id,name'])->latest('completed_at')->get();
        $billed = PharmacySale::whereIn('patient_id', $written->pluck('patient_id'))->whereDate('sold_at', '>=', today())->whereNull('voided_at')->get(['patient_id', 'sold_at'])->groupBy('patient_id');
        $waiting = $written->filter(fn (Consultation $c) => ! $billed->get($c->patient_id)?->first(fn ($sale) => $sale->sold_at >= $c->completed_at));

        $dues = PharmacySale::where('sold_at', '>=', today()->subDays(self::DAYS))->whereNull('voided_at')->with('patient:id,uhid,title,name')->latest('sold_at')->get()
            ->filter(fn (PharmacySale $s) => $s->due > 0);
        $order = PharmacyDesk::reorder();
        $expiry = PharmacyDesk::expiry();
        $soon = $expiry['expiring']->where('days_left', '<=', 30);
        $batch = fn (array $b) => ['text' => $b['medicine'].' · batch '.$b['batch_no'], 'detail' => $b['units'].' on the shelf · '.Carbon::parse($b['expiry'])->format('M Y'), 'to' => '/pharmacy/manager'];

        // Medicines ordered on the ward that nothing has been sent for yet, one line for each patient.
        $ward = WardMedicines::waiting();
        $cases = Admission::whereIn('id', $ward->keys())->with(['patient:id,uhid,title,name', 'bed:id,ward,bed_no'])->get()->keyBy('id');
        // Orders placed with a stockist that have not come by the day expected. Suggested orders not yet approved are not late.
        $notCome = PharmacyDesk::unmatched()['orders']->where('status', 'open')->sortByDesc('days_late');

        $items = [
            self::item('ward_orders', 'Ward medicine orders to send', 'Ordered by a doctor for a patient in the ward; no pharmacy bill has been made for the patient since.', '/pharmacy',
                $ward->filter(fn ($orders, $id) => $cases->has($id))->map(fn ($orders, $id) => [
                    'text' => $cases[$id]->ipd_no.' · '.self::who($cases[$id]->patient).($cases[$id]->bed ? ' · '.$cases[$id]->bed->ward.' '.$cases[$id]->bed->bed_no : ''),
                    'detail' => $orders->pluck('title')->take(4)->implode(', ').($orders->count() > 4 ? ' and '.($orders->count() - 4).' more' : ''),
                    'to' => '/pharmacy?patient='.$cases[$id]->patient_id,
                ])->values(), true, $ward->flatten(1)->count()),
            self::item('prescriptions', 'Prescriptions not billed yet', 'Written by a doctor today; no pharmacy bill has been made for the patient since.', '/pharmacy/orders',
                $waiting->map(fn (Consultation $c) => ['text' => self::who($c->patient), 'detail' => ($c->doctor?->name ?? 'Doctor').' · '.$c->completed_at->format('g:i A'), 'to' => '/pharmacy/orders']), true),
            self::item('pharmacy_dues', 'Pharmacy bills with money due', 'From the last '.self::DAYS.' days. Collect on the bill, or take the medicines back.', '/pharmacy/bills',
                $dues->map(fn (PharmacySale $s) => ['text' => "{$s->bill_no} · ".self::who($s->patient, $s->customer_name), 'detail' => self::rupees($s->due).' due · '.$s->sold_at->format('j M'), 'to' => '/pharmacy/bills'])),
            self::item('out_of_stock', 'Medicines out of stock or nearly', 'Sold regularly, with nothing or very little left. Order these first.', '/pharmacy/manager',
                $order->whereIn('class', ['out', 'urgent'])->map(fn (array $m) => ['text' => $m['name'], 'detail' => $m['class'] === 'out' ? 'None left' : $m['usable'].' left'.($m['days_cover'] !== null ? ', about '.$m['days_cover'].' days' : ''), 'to' => '/pharmacy/manager']), true),
            self::item('reorder', 'Medicines to order', 'Below the level kept for them.', '/pharmacy/manager',
                $order->where('class', 'low')->map(fn (array $m) => ['text' => $m['name'], 'detail' => $m['usable'].' left · order '.$m['suggest_packs'].' pack(s)', 'to' => '/pharmacy/manager'])),
            self::item('orders_late', 'Supplier orders not arrived', 'Ordered from a stockist and not received by the day expected. Ring the supplier, or cancel the order if it is not coming.', '/pharmacy/purchase-orders',
                $notCome->map(fn (array $o) => [
                    'text' => $o['order_no'].' · '.($o['stockist'] ?? 'Stockist'),
                    'detail' => 'Expected '.Carbon::parse($o['expected_on'])->format('j M').' · '.$o['days_late'].($o['days_late'] === 1 ? ' day' : ' days').' late · '.$o['items'].($o['items'] === 1 ? ' medicine' : ' medicines'),
                    'to' => '/pharmacy/purchase-orders?open='.$o['id'],
                ])),
            self::item('expired', 'Expired stock still on the shelf', 'Take it off the shelf and return it or write it off.', '/pharmacy/manager', $expiry['expired']->map($batch), true),
            self::item('expiring', 'Expiring within 30 days', 'Sell first, or send back to the supplier while it can still be returned.', '/pharmacy/manager', $soon->map($batch)),
        ];

        return [...$items, ...$audit];
    }

    /** For those who approve the pharmacy's stock counts: counts handed in, and medicine suppliers' bills past their date. */
    private static function pharmacyAudit(): array
    {
        $counts = StockCount::where('status', 'submitted')->orderBy('submitted_at')->get(['id', 'title', 'submitted_at']);
        $overdue = PharmacyDesk::payables()['invoices']->where('days_overdue', '>', 0);

        return [
            self::item('stock_counts', 'Stock counts waiting for approval', 'Counted and handed in; the differences have to be looked at and approved.', '/pharmacy/audit',
                $counts->map(fn (StockCount $c) => ['text' => $c->title, 'detail' => 'Handed in '.Carbon::parse($c->submitted_at)->format('j M'), 'to' => "/pharmacy/audit/counts/{$c->id}"])),
            self::item('supplier_bills', 'Supplier bills past their date', 'Invoices from medicine suppliers that should have been paid by now.', '/pharmacy/purchases',
                $overdue->sortByDesc('days_overdue')->map(fn (array $i) => ['text' => $i['stockist'].' · '.$i['invoice_no'], 'detail' => self::rupees((float) $i['owed']).' · '.$i['days_overdue'].' days late', 'to' => '/pharmacy/purchases'])),
        ];
    }

    private static function ipd(User $user): array
    {
        if (! $user->allows('canWorkIpd')) {
            return [];
        }
        $with = ['patient:id,uhid,title,name', 'doctor:id,name'];
        $stay = fn (Admission $a, string $detail) => ['text' => "{$a->ipd_no} · ".self::who($a->patient), 'detail' => $detail, 'to' => "/ipd/{$a->id}"];
        $admitted = Admission::where('status', 'admitted')->with($with)->orderBy('admitted_at')->get();
        $gone = Admission::where('discharged_at', '>=', today()->subDays(self::DAYS))->with($with)->latest('discharged_at')->get();

        $items = [
            self::item('advised', 'Advised admission, not admitted yet', 'A doctor advised admission at an OPD visit in the last '.AdmitAdvice::DAYS.' days. Admit the patient, or mark that they are not coming.', '/ipd',
                AdmitAdvice::waiting()->map(fn ($c) => ['text' => self::who($c->patient), 'detail' => trim(($c->doctor?->name ?? 'Doctor').' · '.$c->admit_advised_at->format('j M').($c->admit_reason ? ' · '.$c->admit_reason : '')), 'to' => '/ipd']), true),
            self::item('ready_home', 'Bill approved, patient still admitted', 'Ready to go home once the money is settled and the case is closed.', '/ipd',
                $admitted->filter(fn (Admission $a) => $a->bill_approved_at !== null)->map(fn (Admission $a) => $stay($a, self::rupees(max(0, $a->due)).' due'))),
            self::item('gone_unpaid', 'Discharged with a balance unpaid', 'From the last '.self::DAYS.' days. Collect the balance, or give a discount on the IPD bill.', '/ipd',
                $gone->filter(fn (Admission $a) => $a->due > 0)->map(fn (Admission $a) => $stay($a, self::rupees($a->due).' due · went home '.$a->discharged_at->format('j M'))), true),
            self::item('long_stay', 'Admitted for more than 7 days', 'Long stays to review, and their bills to keep up with.', '/ipd',
                $admitted->filter(fn (Admission $a) => $a->admitted_at->lt(today()->subDays(7)))->map(fn (Admission $a) => $stay($a, 'Since '.$a->admitted_at->format('j M').' · '.($a->doctor?->name ?? 'No doctor')))),
        ];
        if ($user->allows('canApproveIpd')) {
            // A doctor is shown their own cases; anyone else who approves is shown them all.
            $summaries = Admission::where('admitted_at', '>=', today()->subDays(60))->when($user->role === Role::Doctor, fn ($q) => $q->where('doctor_id', $user->id))
                ->whereHas('summary', fn ($q) => $q->whereNull('approved_at'))->with($with)->orderBy('admitted_at')->get();
            $items[] = self::item('summaries', 'Discharge summaries to approve', 'Written, and waiting for the doctor’s approval.', '/ipd',
                $summaries->map(fn (Admission $a) => $stay($a, 'Admitted '.$a->admitted_at->format('j M'))));
        }

        return $items;
    }

    /** The day's accounts, the staff office, and the person's own attendance. */
    private static function office(User $user): array
    {
        $items = [];

        if ($user->allows('canCloseDay')) {
            // A day on which money moved and whose accounts were never closed.
            $busy = collect(Bill::where('created_at', '>=', today()->subDays(7))->where('created_at', '<', today())->pluck('created_at')->map->toDateString())
                ->merge(PharmacySale::where('sold_at', '>=', today()->subDays(7))->where('sold_at', '<', today())->pluck('sold_at')->map->toDateString())->unique();
            $closed = DayClose::whereNull('reopened_at')->whereDate('day', '>=', today()->subDays(7))->get(['day'])->map(fn (DayClose $c) => $c->day->toDateString());
            $items[] = self::item('day_close', 'Days not closed', 'Count the cash and close each day, so its bills and money cannot be changed afterwards.', '/reports',
                $busy->diff($closed)->sort()->values()->map(fn (string $day) => ['text' => Carbon::parse($day)->format('l, j M'), 'detail' => 'Accounts still open', 'to' => '/reports']));
        }

        if ($user->allows('wf:leave:view')) {
            $leave = Access::narrow(LeaveRequest::query(), $user, 'wf:leave:view')->where('status', 'pending')->with('employee:id,name')->orderBy('from_date')->get();
            $items[] = self::item('leave', 'Leave requests to decide', 'Staff are waiting to hear.', '/workforce/leave',
                $leave->map(fn (LeaveRequest $l) => ['text' => $l->employee?->name ?? 'Staff', 'detail' => Carbon::parse($l->from_date)->format('j M').' to '.Carbon::parse($l->to_date)->format('j M'), 'to' => '/workforce/leave']), true);
        }
        if ($user->allows('wf:attendance:view')) {
            $items[] = self::item('corrections', 'Attendance corrections to decide', 'Staff have asked for a day’s attendance to be put right.', '/workforce/attendance', [], false,
                Access::narrow(AttendanceCorrection::query(), $user, 'wf:attendance:view')->where('status', 'pending')->count());
        }
        if ($user->allows('wf:expenses:approve') && $user->allows('canRecordExpenses')) {
            $expenses = Expense::whereNull('cancelled_at')->whereIn('status', ['submitted', 'approved'])->orderBy('id')->get(['id', 'status', 'description', 'paid_to', 'amount']);
            $line = fn (Expense $e) => ['text' => $e->description ?: ($e->paid_to ?: 'Expense'), 'detail' => self::rupees((float) $e->amount), 'to' => '/expenses'];
            $items[] = self::item('expenses_approve', 'Expenses to approve', 'Handed in, and waiting for a yes or a no.', '/expenses', $expenses->where('status', 'submitted')->map($line));
            $items[] = self::item('expenses_pay', 'Approved expenses to pay', 'Approved, and the money has not been paid out yet.', '/expenses', $expenses->where('status', 'approved')->map($line));
        }
        if ($user->allows('canPayConsultants')) {
            $items[] = self::consultants();
        }
        if ($user->allows('wf:payroll:view')) {
            $items = [...$items, ...self::payroll($user)];
        }

        // The person's own attendance, where the hospital has people mark themselves in.
        if ($user->allows('wf:self:view') && (Settings::get('attendance')['self_check_in'] ?? false)) {
            $employee = Employee::query()->where('user_id', $user->id)->first();
            if ($employee && $employee->isRegular() && ! Attendance::query()->where('employee_id', $employee->id)->whereDate('work_date', today())->exists()) {
                $items[] = self::item('check_in', 'You have not checked in today', 'Mark your attendance for today.', '/workforce/me', [['text' => 'Check in', 'detail' => today()->format('l, j M'), 'to' => '/workforce/me']], true);
            }
        }

        if ($user->role === Role::Admin && ($alert = DatabaseBackup::alert())) {
            $items[] = self::item('backup', 'Backups need attention', $alert, '/admin?tab=backups', [], true, 1);
        }

        return $items;
    }

    /**
     * Consultants whose fees for one of the last few finished months are not all paid: one line for each doctor and
     * month. The month still running is left out, as it is still being earned. Names and months only; what is owed is
     * on the Consultants page. Working a month out takes many sums, so the answer is kept for up to an hour, and is
     * worked out afresh as soon as a payment or a ward visit is written down, changed or taken back.
     */
    private static function consultants(): array
    {
        $latest = fn (string $model) => $model::query()->toBase()->selectRaw('max(id) as id, max(updated_at) as at')->first();
        [$paid, $visit] = [$latest(ConsultantPayment::class), $latest(ConsultantVisit::class)];
        $key = 'todo.consultants.'.today()->toDateString().".{$paid?->id}.{$visit?->id}.".md5($paid?->at.'|'.$visit?->at);

        $rows = Cache::remember($key, now()->addHour(), fn () => collect(range(self::CONSULTANT_MONTHS, 1))->flatMap(function (int $back) {
            // From the first of the month, so the 31st never slips a month.
            $month = today()->startOfMonth()->subMonths($back);

            return ConsultantFees::month($month->format('Y-m'))->filter(fn (array $r) => $r['due'] >= 0.01)->map(fn (array $r) => [
                'text' => $r['doctor'],
                'detail' => $month->format('F Y').' · '.($r['paid'] > 0 ? 'part paid' : 'nothing paid yet').($r['visits'] > 0 ? ' · '.$r['visits'].($r['visits'] === 1 ? ' ward visit' : ' ward visits') : ''),
                'to' => '/workforce/consultants?month='.$month->format('Y-m'),
            ]);
        })->values()->all());

        return self::item('consultants', 'Consultants to pay', 'Their share of their own cases and their ward visit fees for a finished month, not all paid yet. The amounts are on the Consultants page.',
            $rows[0]['to'] ?? '/workforce/consultants', $rows);
    }

    /**
     * Payroll runs waiting at the step this person takes them through, and finalised payrolls whose salaries are not
     * yet written down as paid. Counts and names only: what anyone is paid stays on the payroll page.
     */
    private static function payroll(User $user): array
    {
        // Each step, the permission it needs, and how it is put.
        $steps = array_filter(['draft' => ['wf:payroll:edit', 'to check'], 'reviewed' => ['wf:payroll:approve', 'to approve'], 'approved' => ['wf:payroll:finalise', 'to finalise']], fn (array $step) => $user->allows($step[0]));
        $runs = $steps === [] ? collect() : PayrollRun::query()->select(['id', 'period', 'label', 'status', 'created_by', 'reviewed_by'])->withCount('payslips')
            ->whereIn('status', array_keys($steps))->orderBy('period')->orderBy('id')->get()
            // As the payroll page has it: approved by someone other than whoever prepared or reviewed it, unless an admin.
            ->reject(fn (PayrollRun $r) => $r->status === 'reviewed' && $user->role !== Role::Admin && in_array((int) $user->id, [(int) $r->created_by, (int) $r->reviewed_by], true));
        $name = fn (PayrollRun $r) => Period::name($r->period).' payroll'.($r->label ? ' · '.$r->label : '');
        $items = [self::item('payroll', 'Payroll waiting for you', 'At the step you take it through: a draft to check, a reviewed one to approve, an approved one to finalise.', '/workforce/payroll',
            $runs->map(fn (PayrollRun $r) => ['text' => $name($r), 'detail' => $r->payslips_count.' staff · '.$steps[$r->status][1], 'to' => '/workforce/payroll?run='.$r->id]))];

        if ($user->allows('wf:payroll:approve')) {
            // A payslip held back on purpose is not waiting to be paid; only those still marked unpaid are.
            $unpaid = Payslip::query()->where('payment_status', 'unpaid')->whereNotNull('live_period')->whereHas('run', fn ($q) => $q->whereIn('status', ['finalised', 'paid']))
                ->selectRaw('run_id, count(*) as slips')->groupBy('run_id')->pluck('slips', 'run_id');
            $items[] = self::item('salaries_unpaid', 'Salaries not marked paid', 'The payroll is finalised. Write each payslip down as paid once the money has gone.', '/workforce/payroll',
                PayrollRun::query()->whereIn('id', $unpaid->keys())->orderBy('period')->orderBy('id')->get(['id', 'period', 'label'])->map(fn (PayrollRun $r) => [
                    'text' => $name($r), 'detail' => $unpaid[$r->id].((int) $unpaid[$r->id] === 1 ? ' payslip' : ' payslips').' not marked paid', 'to' => '/workforce/payroll?run='.$r->id,
                ]), false, (int) $unpaid->sum());
        }

        return $items;
    }
}
