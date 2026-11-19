<?php

namespace App\Workforce\Support;

use App\Workforce\Models\Advance;
use App\Workforce\Models\AdvanceInstalment;
use App\Workforce\Models\AdvanceLedgerLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An advance or loan's account. What is owed is the sum of its ledger lines and nothing else; the instalment plan
 * says how fast it is meant to come back. What payroll takes in a month is what the plan says should have come back
 * by then, less what already has: so a month where the salary could not cover an instalment is made up the next
 * month without anyone rewriting the plan.
 */
final class AdvanceBook
{
    /** Lines that bring money back from the member of staff (a reversal undoes one, so it counts with them). */
    private const RECOVERY = ['payroll_deduction', 'repayment', 'reversal'];

    /** What is still owed. */
    public static function balance(Advance $advance): BigDecimal
    {
        return Money::paise(AdvanceLedgerLine::query()->where('advance_id', $advance->id)->sum('amount'));
    }

    /** What has come back so far, through salary and by hand. */
    public static function recovered(Advance $advance): BigDecimal
    {
        return Money::paise(AdvanceLedgerLine::query()->where('advance_id', $advance->id)->whereIn('kind', self::RECOVERY)->sum('amount'))->negated();
    }

    /** What payroll should take for a salary month: the plan up to that month, less what has come back, never more than is owed. */
    public static function dueBy(Advance $advance, string $period): BigDecimal
    {
        if ($advance->status !== 'issued') {
            return BigDecimal::zero()->toScale(2);
        }
        $planned = Money::paise(AdvanceInstalment::query()->where('advance_id', $advance->id)->where('period', '<=', $period)->whereIn('status', ['due', 'deducted'])->sum('amount'));
        $due = BigDecimal::min($planned->minus(self::recovered($advance)), self::balance($advance));

        return $due->isNegative() ? BigDecimal::zero()->toScale(2) : $due;
    }

    /** The plan: the amount in equal monthly parts from the first salary month, the odd rupees on the last. */
    public static function plan(mixed $amount, int $instalments, string $firstPeriod): array
    {
        $amount = Money::paise($amount);
        $instalments = max(1, $instalments);
        // Whole rupees where the amount allows, so nobody's instalment ends in odd paise.
        $scale = $amount->isGreaterThanOrEqualTo($instalments) ? 0 : 2;
        $each = $amount->dividedBy($instalments, $scale, RoundingMode::Down);
        $plan = [];
        for ($i = 0; $i < $instalments; $i++) {
            $part = $i === $instalments - 1 ? $amount->minus($each->multipliedBy($instalments - 1)) : $each;
            $plan[] = ['period' => Period::after($firstPeriod, $i), 'amount' => Money::text($part)];
        }

        return $plan;
    }

    /** Records the money as given: the first ledger line, and the plan. */
    public static function issue(Advance $advance, string $date, ?string $mode, ?string $reference): Advance
    {
        return DB::transaction(function () use ($advance, $date, $mode, $reference) {
            $advance = Advance::query()->lockForUpdate()->findOrFail($advance->id);
            if ($advance->status !== 'approved') {
                throw ValidationException::withMessages(['status' => $advance->status === 'issued' ? 'This has already been given.' : 'Only an approved request can be given.']);
            }
            $advance->update(['status' => 'issued', 'issued_on' => $date, 'payment_mode' => $mode, 'payment_reference' => $reference, 'first_period' => $advance->first_period ?? substr($date, 0, 7)]);
            $advance->schedule()->delete();
            foreach (self::plan($advance->amount, (int) $advance->instalments, $advance->first_period) as $part) {
                $advance->schedule()->create($part + ['status' => 'due']);
            }
            self::line($advance, 'issued', $advance->amount, $date, 'Given to the member of staff', null, $mode);
            // The money left the hospital: one paid expense under the advances heading, counted in that day's cash.
            ExpenseBook::post('advance', $advance->id, ExpenseBook::ADVANCES, $advance->amount, $date, (string) $mode, $reference, $advance->employee?->name,
                ($advance->kind === 'loan' ? 'Loan ' : 'Advance ').($advance->advance_no ?? '').' given'.($advance->reason ? ': '.$advance->reason : ''));

            return $advance->fresh();
        });
    }

    /**
     * Adds a line to the account. A plus amount means more is owed, a minus amount less. Lines are never changed:
     * a mistake is another line.
     */
    public static function line(Advance $advance, string $kind, mixed $amount, string $date, ?string $reason = null, ?int $payslipId = null, ?string $mode = null): AdvanceLedgerLine
    {
        return DB::transaction(function () use ($advance, $kind, $amount, $date, $reason, $payslipId, $mode) {
            Advance::query()->lockForUpdate()->findOrFail($advance->id);
            $after = self::balance($advance)->plus(Money::paise($amount));
            if ($after->isNegative()) {
                throw ValidationException::withMessages(['amount' => 'That is more than is still owed ('.Money::show(self::balance($advance)).').']);
            }

            return AdvanceLedgerLine::create([
                'advance_id' => $advance->id, 'entry_date' => $date, 'kind' => $kind, 'mode' => $mode, 'amount' => Money::text($amount), 'balance_after' => Money::text($after),
                'payslip_id' => $payslipId, 'reason' => $reason, 'created_by' => auth()->id(), 'created_at' => now(),
            ]);
        });
    }

    /**
     * Brings the plan and the advance's own status into line with the ledger: instalments are marked as taken in
     * order as far as the money that has come back covers them, and an advance with nothing owed is closed (or
     * opened again, if a reversal put a balance back).
     */
    public static function settle(Advance $advance): Advance
    {
        $left = self::recovered($advance);
        foreach ($advance->schedule()->whereIn('status', ['due', 'deducted'])->orderBy('period')->orderBy('id')->get() as $part) {
            $covered = $left->isGreaterThanOrEqualTo($part->amount) && Money::of($part->amount)->isPositive();
            $left = $covered ? $left->minus($part->amount) : BigDecimal::zero();
            if ($part->status !== ($covered ? 'deducted' : 'due')) {
                $part->update(['status' => $covered ? 'deducted' : 'due']);
            }
        }
        $owed = self::balance($advance);
        if ($advance->status === 'issued' && $owed->isZero()) {
            $advance->update(['status' => 'closed']);
        } elseif (in_array($advance->status, ['closed', 'written_off'], true) && $owed->isPositive()) {
            // A reversed payroll put money back as owed: the account is open again, whatever closed it.
            $advance->update(['status' => 'issued']);
        }

        return $advance->fresh();
    }

    /** Leaves one month's instalment untaken and adds it after the last month of the plan. */
    public static function skip(Advance $advance, string $period): void
    {
        DB::transaction(function () use ($advance, $period) {
            $part = $advance->schedule()->where('period', $period)->where('status', 'due')->first();
            if (! $part) {
                throw ValidationException::withMessages(['period' => 'There is no instalment still to be taken in that month.']);
            }
            $part->update(['status' => 'skipped']);
            $last = (string) $advance->schedule()->max('period');
            $advance->schedule()->create(['period' => Period::after($last), 'amount' => $part->amount, 'status' => 'due']);
        });
    }

    /**
     * Lets go of what is owed, in part (a waiver) or altogether (a write-off). The ledger says so in its own line;
     * the instalments that will now never be taken are marked waived.
     */
    public static function letGo(Advance $advance, string $kind, mixed $amount, string $reason): Advance
    {
        return DB::transaction(function () use ($advance, $kind, $amount, $reason) {
            $advance = Advance::query()->lockForUpdate()->findOrFail($advance->id);
            if ($advance->status !== 'issued') {
                throw ValidationException::withMessages(['status' => 'Only an advance that is still owed can be waived or written off.']);
            }
            $owed = self::balance($advance);
            $amount = $kind === 'write_off' ? $owed : Money::paise($amount);
            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(['amount' => 'Enter how much is being let go.']);
            }
            self::line($advance, $kind, $amount->negated(), now()->toDateString(), $reason);
            if (self::balance($advance)->isZero()) {
                $advance->schedule()->where('status', 'due')->update(['status' => 'waived']);
                $advance->update(['status' => $kind === 'write_off' ? 'written_off' : 'closed']);
            }

            return $advance->fresh();
        });
    }
}
