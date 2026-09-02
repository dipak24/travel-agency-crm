<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantUserFactory;
use Filament\Panel;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

class TenantUser extends Model implements Authenticatable
{
    /** @use HasFactory<TenantUserFactory> */
    use AuthenticatableTrait, BelongsToTenant, HasFactory, Notifiable;

    protected $fillable = [
        'tenant_id', 'name', 'email', 'password', 'status', 'avatar',
        'designation', 'department', 'joining_date',
    ];

    protected $hidden = ['password', 'remember_token'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === 'active' && $panel->getId() === 'tenant';
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'joining_date' => 'date',
            'password' => 'hashed',
        ];
    }
}
