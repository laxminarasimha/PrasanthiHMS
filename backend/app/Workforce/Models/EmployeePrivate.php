<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * A member of staff's bank, tax and statutory details. Every number is encrypted where it is stored, and none is ever put in a list or a log.
 */
class EmployeePrivate extends Model
{
    use BranchScoped;

    protected $table = 'wf_employee_private';

    protected $fillable = ['employee_id', 'branch_id', 'bank_name', 'bank_account_no', 'bank_ifsc', 'upi_id', 'pan', 'aadhaar', 'uan', 'esi_no', 'tax_regime', 'updated_by'];

    protected $hidden = ['bank_account_no', 'bank_ifsc', 'upi_id', 'pan', 'aadhaar', 'uan', 'esi_no'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['bank_account_no' => 'encrypted', 'bank_ifsc' => 'encrypted', 'upi_id' => 'encrypted', 'pan' => 'encrypted', 'aadhaar' => 'encrypted', 'uan' => 'encrypted', 'esi_no' => 'encrypted'];
    }

    /** The fields that identify a person or their bank account. */
    public const SECRET = ['bank_account_no', 'bank_ifsc', 'upi_id', 'pan', 'aadhaar', 'uan', 'esi_no'];

    /** A number with all but its last four characters hidden, for a screen or the record of a change. */
    public static function masked(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $shown = mb_substr($value, -min(4, max(0, mb_strlen($value) - 2)));

        return str_repeat('•', max(4, mb_strlen($value) - mb_strlen($shown))).$shown;
    }
}
