<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Filament\Panel;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

class Customer extends Model implements Authenticatable
{
    use AuthenticatableTrait, BelongsToTenant, Notifiable;

    protected $fillable = [
        'tenant_id', 'name', 'email', 'password', 'phone', 'type', 'address',
        'passport_no', 'nationality', 'notes',
    ];

    protected $hidden = ['password', 'remember_token', 'passport_no'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->password !== null && $panel->getId() === 'portal';
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'passport_no' => 'encrypted',
        ];
    }
}
