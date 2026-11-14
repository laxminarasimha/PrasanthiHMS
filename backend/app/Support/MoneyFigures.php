<?php

namespace App\Support;

use App\Models\AdmissionCharge;
use App\Models\AdmissionPayment;
use App\Models\Bill;
use App\Models\LabOrder;
use App\Models\PatientDeposit;
use App\Models\Payment;
use App\Models\PharmacyPayment;
use App\Models\PharmacySale;
use App\Models\PharmacySaleReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Money billed and collected per department and payment mode, shared by the reports and the day close. */
class MoneyFigures
{
    /**
     * "Billed" is what was charged in the period; "collected" is the money actually received in
     * the period, whenever the bill was made, less refunds.
     *
     * @return array<string, array{label: string, billed: float, collected: array<string, float>, collected_total: float}>
     */
    public static function departments(Carbon $from, Carbon $to): array
    {
        $labBillIds = LabOrder::whereNotNull('bill_id')->pluck('bill_id');
        $net = "mode, sum(case when type = 'refund' then -amount else amount end) as amount";

        // Front desk and lab share the bills and payments tables; lab bills are the ones a lab order points to.
        $billPayments = fn (bool $lab) => self::byMode(
            Payment::whereBetween('created_at', [$from, $to])
                ->when($lab, fn ($q) => $q->whereIn('bill_id', $labBillIds), fn ($q) => $q->whereNotIn('bill_id', $labBillIds))
                ->selectRaw($net)->groupBy('mode')->pluck('amount', 'mode')
        );
        $billed = fn (bool $lab) => (float) Bill::whereBetween('created_at', [$from, $to])
            ->when($lab, fn ($q) => $q->whereIn('id', $labBillIds), fn ($q) => $q->whereNotIn('id', $labBillIds))
            ->sum('total');

        // Deposits are money in hand on the day they are taken, whichever bill they later pay.
        $deposits = PatientDeposit::whereBetween('created_at', [$from, $to])->whereIn('type', ['deposit', 'refund'])
            ->selectRaw($net)->groupBy('mode')->pluck('amount', 'mode');
        $frontDesk = $billPayments(false);
        foreach ($frontDesk as $mode => $amount) {
            $frontDesk[$mode] = round($amount + (float) ($deposits[$mode] ?? 0), 2);
        }

        $departments = [
            'consultation' => ['label' => 'Front desk', 'billed' => $billed(false), 'collected' => $frontDesk],
            'lab' => ['label' => 'Lab', 'billed' => $billed(true), 'collected' => $billPayments(true)],
            'pharmacy' => [
                'label' => 'Pharmacy',
                'billed' => (float) PharmacySale::whereBetween('sold_at', [$from, $to])->whereNull('voided_at')->sum('total')
                    - (float) PharmacySaleReturn::whereBetween('created_at', [$from, $to])->sum('amount'),
                'collected' => self::byMode(PharmacyPayment::whereBetween('created_at', [$from, $to])->selectRaw($net)->groupBy('mode')->pluck('amount', 'mode')),
            ],
            'ipd' => [
                'label' => 'IPD',
                'billed' => (float) AdmissionCharge::whereBetween('charged_on', [$from->toDateString(), $to->toDateString()])->sum('amount'),
                'collected' => self::byMode(AdmissionPayment::whereBetween('created_at', [$from, $to])->selectRaw($net)->groupBy('mode')->pluck('amount', 'mode')),
            ],
        ];

        foreach ($departments as &$department) {
            $department['billed'] = round($department['billed'], 2);
            $department['collected_total'] = round(array_sum($department['collected']), 2);
        }

        return $departments;
    }

    /**
     * Every payment mode with its amount, zero when nothing was received that way.
     *
     * @return array<string, float>
     */
    private static function byMode(Collection $amounts): array
    {
        return collect(Payment::MODES)->mapWithKeys(fn ($mode) => [$mode => round((float) ($amounts[$mode] ?? 0), 2)])->all();
    }
}
