<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A sheet of attendance brought in: looked over first, then applied. */
class AttendanceImport extends Model
{
    use BranchScoped;

    protected $table = 'wf_attendance_imports';

    protected $fillable = ['branch_id', 'file_name', 'sha256', 'status', 'summary', 'created_by', 'applied_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['summary' => 'array', 'applied_at' => 'datetime'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(AttendanceImportRow::class, 'import_id');
    }
}
