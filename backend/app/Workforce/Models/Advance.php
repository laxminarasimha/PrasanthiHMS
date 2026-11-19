<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money given ahead of salary, or lent, to be taken back from later salaries. */
class Advance extends Model
{
    use BranchScoped;

    protected $table = 'wf_advances';

    protected $fillable = ['branch_id', 'employee_id', 'advance_no', 'kind', 'amount', 'reason', 'status', 'issued_on', 'payment_mode', 'payment_reference', 'instalments', 'first_period', 'requested_by', 'approved_by', 'approved_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'issued_on' => 'date:Y-m-d', 'approved_at' => 'datetime'];
    }

    public function schedule(): HasMany
    {
        return $this->hasMany(AdvanceInstalment::class, 'advance_id')->orderBy('period');
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(AdvanceLedgerLine::class, 'advance_id')->orderBy('id');
    }
}
