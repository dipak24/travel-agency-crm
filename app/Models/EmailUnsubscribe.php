<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An opt-out from marketing email. `tenant_id` scopes it to one tenant's campaigns; a null
 * `tenant_id` is an opt-out from the platform's own campaigns.
 */
class EmailUnsubscribe extends Model
{
    protected $fillable = ['tenant_id', 'email', 'unsubscribed_at', 'source'];

    protected function casts(): array
    {
        return ['unsubscribed_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
