<?php

namespace App\Workforce\Models;

use App\Enums\Role;
use App\Models\AdminEntry;
use App\Models\User;
use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Builder;
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

    protected $fillable = ['branch_id', 'employee_no', 'user_id', 'status', 'name', 'phone', 'email', 'gender', 'emergency_contact_name', 'emergency_contact_phone', 'department_id', 'designation', 'manager_id', 'work_location', 'joined_on', 'confirmed_on', 'separated_on', 'separation_reason', 'employment_type', 'shift_id', 'weekly_off', 'holiday_calendar_id', 'payment_mode', 'created_by', 'regular'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['joined_on' => 'date:Y-m-d', 'confirmed_on' => 'date:Y-m-d', 'separated_on' => 'date:Y-m-d', 'weekly_off' => 'array', 'regular' => 'boolean'];
    }

    /**
     * Regular staff are in the daily attendance and on the payroll. Doctors and consultants are not: they are engaged
     * for their work, not employed by the day, and are paid apart from the salaries. A tick or untick on the person's
     * own record says otherwise for that one person (a doctor on a monthly salary, say).
     */
    public function scopeRegular(Builder $query, bool $regular = true): Builder
    {
        $doctors = User::query()->where('role', Role::Doctor->value)->select('id');
        $byRule = fn (Builder $q) => $q->where('employment_type', '!=', 'consultant')->where(fn (Builder $w) => $w->whereNull('user_id')->orWhereNotIn('user_id', $doctors));

        return $regular
            ? $query->where(fn (Builder $q) => $q->where('regular', true)->orWhere(fn (Builder $w) => $byRule($w->whereNull('regular'))))
            : $query->where(fn (Builder $q) => $q->where('regular', false)->orWhere(fn (Builder $w) => $w->whereNull('regular')->where(fn (Builder $x) => $x->where('employment_type', 'consultant')->orWhereIn('user_id', $doctors))));
    }

    /** The same answer for one person already in hand. */
    public function isRegular(): bool
    {
        return $this->regular ?? ! ($this->employment_type === 'consultant' || ($this->user_id && User::query()->whereKey($this->user_id)->where('role', Role::Doctor->value)->exists()));
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
