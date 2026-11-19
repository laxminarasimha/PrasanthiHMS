<?php

namespace App\Workforce\Models;

use App\Workforce\Support\AppendOnly;
use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/** One line of an advance's account. What is still owed is the sum of the lines. */
class AdvanceLedgerLine extends Model
{
    use AppendOnly, BranchScoped;

    protected $table = 'wf_advance_ledger';

    public $timestamps = false;

    protected $fillable = ['branch_id', 'advance_id', 'entry_date', 'kind', 'mode', 'amount', 'balance_after', 'payslip_id', 'reason', 'created_by', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'created_at' => 'datetime'];
    }
}
