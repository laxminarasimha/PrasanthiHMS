<?php

namespace App\Workforce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One earning or deduction on a payslip, with how its amount was arrived at. Fixed once the payroll is finalised. */
class PayslipLine extends Model
{
    protected $table = 'wf_payslip_lines';

    protected $fillable = ['payslip_id', 'component_id', 'code', 'name', 'kind', 'amount', 'explanation', 'source_type', 'source_id', 'sort'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        $refuse = function (PayslipLine $line) {
            if ($line->payslip?->run?->isLocked()) {
                throw new LogicException('The lines of a finalised payslip are not changed, added to or removed.');
            }
        };
        static::creating($refuse);
        static::updating($refuse);
        static::deleting($refuse);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
