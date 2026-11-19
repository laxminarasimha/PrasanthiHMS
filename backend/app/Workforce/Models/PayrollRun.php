<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One period's payroll. It moves one way (draft, reviewed, approved, finalised, paid) and, once finalised, nothing
 * in it changes: a mistake is put right by reversing the run and making another, never by editing this one.
 */
class PayrollRun extends Model
{
    use BranchScoped;

    public const STEPS = ['draft', 'reviewed', 'approved', 'finalised', 'paid', 'reversed'];

    /** From finalising onward the figures are fixed. */
    public const LOCKED = ['finalised', 'paid', 'reversed'];

    /** What may still be written on a locked run: that it was paid, that it was reversed, and where it was posted. */
    private const AFTER_LOCK = ['status', 'reversed_by', 'reversed_at', 'reverse_reason', 'posted_expense_id', 'updated_at'];

    protected $table = 'wf_payroll_runs';

    protected $fillable = [
        'branch_id', 'period', 'period_start', 'period_end', 'label', 'status', 'gross', 'deductions', 'net', 'employer_cost', 'rules', 'reversal_of_id', 'reverse_reason',
        'created_by', 'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at', 'finalised_by', 'finalised_at', 'reversed_by', 'reversed_at', 'posted_expense_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'gross' => 'decimal:2', 'deductions' => 'decimal:2', 'net' => 'decimal:2', 'employer_cost' => 'decimal:2',
            'rules' => 'array', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'finalised_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PayrollRun $run) {
            if (! in_array($run->getOriginal('status'), self::LOCKED, true)) {
                return;
            }
            $changed = array_diff(array_keys($run->getDirty()), self::AFTER_LOCK);
            if ($changed !== []) {
                throw new LogicException('A finalised payroll is not changed. Reverse it and run it again.');
            }
            // A locked run only goes forward: finalised to paid, or either of them to reversed.
            $allowed = ['finalised' => ['paid', 'reversed'], 'paid' => ['reversed'], 'reversed' => []];
            if ($run->isDirty('status') && ! in_array($run->status, $allowed[$run->getOriginal('status')], true)) {
                throw new LogicException("A payroll that is {$run->getOriginal('status')} cannot become {$run->status}.");
            }
        });
        static::deleting(function (PayrollRun $run) {
            if (in_array($run->status, self::LOCKED, true)) {
                throw new LogicException('A finalised payroll is never removed.');
            }
        });
    }

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED, true);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'run_id');
    }
}
