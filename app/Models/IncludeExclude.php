<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncludeExclude extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'type', 'title', 'description', 'sort_order'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
