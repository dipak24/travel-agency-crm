<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;

class TenantScopedUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null): Builder
    {
        return ($model ?? $this->createModel())->newQueryWithoutScopes();
    }
}
