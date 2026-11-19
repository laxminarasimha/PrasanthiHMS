<?php

namespace App\Workforce\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * For every workforce record that belongs to a branch: it is stamped with the branch when made, and never read
 * from another branch. This is the server-side wall between hospitals; no screen or request can step round it.
 */
trait BranchScoped
{
    public static function bootBranchScoped(): void
    {
        static::addGlobalScope('branch', function (Builder $query) {
            if (! Tenant::mustNarrow()) {
                return;
            }
            $branch = Tenant::id();
            $column = $query->getModel()->getTable().'.branch_id';
            // Signed in but with no branch: nothing, never everything.
            $branch === null ? $query->whereRaw('1 = 0') : $query->where($column, $branch);
        });

        static::creating(function (Model $record) {
            $record->branch_id ??= Tenant::id();
            if ($record->branch_id === null) {
                throw new LogicException(class_basename($record).' cannot be saved without the branch it belongs to.');
            }
        });

        // A record does not move from one hospital to another.
        static::updating(function (Model $record) {
            if ($record->isDirty('branch_id')) {
                throw new LogicException(class_basename($record).' cannot be moved to another branch.');
            }
        });
    }
}
