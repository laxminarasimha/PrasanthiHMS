<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A day whose accounts have been closed. While a day is closed, money cannot be recorded
 * for it and its bills cannot be changed; an admin has to reopen it first.
 */
class DayClose extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date:Y-m-d', 'closed_at' => 'datetime:Y-m-d H:i', 'reopened_at' => 'datetime:Y-m-d H:i',
            'expected_cash' => 'float', 'counted_cash' => 'float', 'variance' => 'float', 'snapshot' => 'array',
        ];
    }

    /** The closing that is in force for a day, if there is one. */
    public static function inForce(mixed $day): ?self
    {
        return self::whereDate('day', Carbon::parse($day)->toDateString())->whereNull('reopened_at')->latest('id')->first();
    }

    /** Stops the request when the day something belongs to has been closed. */
    public static function refuseIfClosed(mixed $when): void
    {
        $day = Carbon::parse($when ?? now());
        if (self::whereDate('day', $day->toDateString())->whereNull('reopened_at')->exists()) {
            abort(423, 'The accounts for '.$day->format('j M Y').' are closed, so money cannot be recorded or changed for that day. An admin can reopen it under Reports → Day close.');
        }
    }

    /**
     * Puts the lock on every record that carries money. It sits on the records themselves rather
     * than on the screens, so no screen, old or new, can change a closed day by another route.
     */
    public static function lockMoney(): void
    {
        $made = fn (string $column) => fn (Model $m) => self::refuseIfClosed($m->{$column} ?? now());

        // Nothing new may be dated into a closed day, and nothing of a closed day may be removed.
        foreach ([
            Bill::class => 'created_at', PatientDeposit::class => 'created_at', PharmacySale::class => 'sold_at',
            PharmacySaleReturn::class => 'created_at', PharmacyPayment::class => 'created_at',
            AdmissionPayment::class => 'created_at', AdmissionCharge::class => 'charged_on', AdmissionBill::class => 'created_at',
        ] as $model => $column) {
            $model::creating($made($column));
            $model::deleting($made($column));
        }

        // A bill moved onto an IPD bill is noted as a payment of mode "ipd": no money changes hands, so it is left alone.
        $real = fn (Payment $p) => $p->mode === 'ipd' ? null : self::refuseIfClosed($p->created_at ?? now());
        Payment::creating($real);
        Payment::deleting($real);

        // Changing what a closed day's bill is worth.
        Bill::updating(fn (Bill $b) => $b->isDirty(['subtotal', 'discount', 'tax', 'total']) ? self::refuseIfClosed($b->created_at) : null);
        $line = fn (BillItem $i) => ($bill = Bill::find($i->bill_id)) ? self::refuseIfClosed($bill->created_at) : null;
        BillItem::creating($line);
        BillItem::updating($line);
        BillItem::deleting($line);
        PharmacySale::updating(fn (PharmacySale $s) => $s->isDirty(['total', 'voided_at']) ? self::refuseIfClosed($s->getOriginal('sold_at')) : null);
        AdmissionCharge::updating(function (AdmissionCharge $c) {
            if ($c->isDirty(['amount', 'charged_on'])) {
                self::refuseIfClosed($c->getOriginal('charged_on'));
                self::refuseIfClosed($c->charged_on);
            }
        });
    }
}
