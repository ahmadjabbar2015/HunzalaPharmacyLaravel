<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/*
 * RULE 4 - soft-deleted rows are hidden by default. Deletion is a synced state
 * carried in is_deleted, not Laravel's deleted_at convention, because
 * reconciliation has to tell "deleted" apart from "lost".
 */
class NotDeletedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('is_deleted'), false);
    }
}
