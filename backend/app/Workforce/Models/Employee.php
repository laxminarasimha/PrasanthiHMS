<?php

namespace App\Workforce\Models;

use App\Models\AdminEntry;
use App\Models\User;
use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A member of the hospital's staff, whether or not they have a login. Someone who leaves is marked inactive and kept, with all their history.
 */
class Employee extends Model
{
    use BranchScoped;

    protected $table = 'wf_employees';

    protected $fillable = ['branch_id', 'employee_no', 'user_id', 'status', 'name', 'phone', 'email', 'gender', 'emergency_contact_name', 'emergency_contact_phone', 'department_id', 'designation', 'manager_id', 'work_location', 'joined_on', 'confirmed_on', 'separated_on', 'separation_reason', 'employment_type', 'shift_id', 'weekly_off', 'holiday_calendar_id', 'payment_mode', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['joined_on' => 'date:Y-m-d', 'confirmed_on' => 'date:Y-m-d', 'separated_on' => 'date:Y-m-d', 'weekly_off' => 'array'];
    }

    public const STATUSES = ['active', 'on_notice', 'inactive'];

    public const TYPES = ['permanent', 'contract', 'part_time', 'consultant', 'trainee'];

    /** Bank, tax and statutory details: loaded only when asked for, by someone allowed to see them. */
    public function private(): HasOne
    {
        return $this->hasOne(EmployeePrivate::class, 'employee_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(AdminEntry::class, 'department_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
