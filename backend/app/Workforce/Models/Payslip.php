<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One person's pay for one period. While it stands, `live_period` holds the period, and the database allows only
 * one standing payslip for a person and a month; reversing the run empties it, which frees the month for a new run
 * while the reversed payslip stays on record.
 */
class Payslip extends Model
{
    use BranchScoped;

    /** What may still be written once the run is finalised: the payment, and the release of the month on reversal. */
    private const AFTER_LOCK = ['payment_status', 'paid_on', 'paid_amount', 'payment_mode', 'payment_reference', 'paid_by', 'live_period', 'updated_at'];

    protected $table = 'wf_payslips';

    protected $fillable = [
        'branch_id', 'run_id', 'employee_id', 'period', 'live_period', 'slip_no', 'days', 'pay_as_set', 'gross', 'deductions', 'net', 'employer_cost',
        'payment_status', 'paid_on', 'paid_amount', 'payment_mode', 'payment_reference', 'paid_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['days' => 'array', 'pay_as_set' => 'array', 'gross' => 'decimal:2', 'deductions' => 'decimal:2', 'net' => 'decimal:2', 'employer_cost' => 'decimal:2', 'paid_amount' => 'decimal:2', 'paid_on' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        static::creating(function (Payslip $slip) {
            // A new payslip stands for its month from the moment it is made.
            $slip->live_period ??= $slip->period;
        });
        static::updating(function (Payslip $slip) {
            if ($slip->run?->isLocked() && array_diff(array_keys($slip->getDirty()), self::AFTER_LOCK) !== []) {
                throw new LogicException('A payslip of a finalised payroll is not changed. Reverse the payroll and run it again.');
            }
        });
        static::deleting(function (Payslip $slip) {
            if ($slip->run?->isLocked()) {
                throw new LogicException('A payslip of a finalised payroll is never removed.');
            }
        });
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class, 'payslip_id')->orderBy('sort')->orderBy('id');
    }
}
