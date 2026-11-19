<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A list of holidays that a set of staff follows. */
class HolidayCalendar extends Model
{
    use BranchScoped;

    protected $table = 'wf_holiday_calendars';

    protected $fillable = ['branch_id', 'name', 'is_default'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class, 'calendar_id')->orderBy('on');
    }
}
