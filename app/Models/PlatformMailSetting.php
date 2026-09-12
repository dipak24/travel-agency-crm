<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single platform-wide row (Super Admin only) — the default outgoing mailer used for
 * platform-level email and as the fallback for any tenant that hasn't configured its own
 * SMTP settings via TenantMailSetting. Entirely separate storage from the per-tenant table,
 * even though App\Services\Mail\TenantMailer falls back to this one when a tenant has none.
 */
class PlatformMailSetting extends Model
{
    protected $fillable = [
        'enabled',
        'from_name',
        'from_address',
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
        ];
    }
}
