<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMailSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
