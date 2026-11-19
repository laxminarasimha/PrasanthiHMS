<?php

namespace App\Workforce\Models;

use Illuminate\Database\Eloquent\Model;

/** One month's share of an advance, to be taken from that month's salary. */
class AdvanceInstalment extends Model
{
    protected $table = 'wf_advance_schedule';

    protected $fillable = ['advance_id', 'period', 'amount', 'status', 'payslip_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
