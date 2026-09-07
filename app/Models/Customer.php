<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Traits\HasRoles;

class Customer extends Authenticatable implements FilamentUser, HasAvatar
{
    use BelongsToTenant, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'tenant_id', 'name', 'email', 'password', 'avatar', 'phone', 'type',
        'address', 'passport_no', 'nationality', 'notes',
    ];

    protected $hidden = ['password', 'remember_token', 'passport_no'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Deliberately does NOT also check `$this->password !== null` here.
        // Filament's own password-reset page re-checks canAccessPanel()
        // *before* saving the new password, using the model's current
        // (still-null, pre-reset) state — so gating login on a non-null
        // password here would make it impossible for an invited customer to
        // ever complete their first password setup. A null password already
        // blocks login on its own, since no submitted password can hash-match
        // a null stored value.
        return $panel->getId() === 'portal'
            && $this->tenant?->status !== 'suspended';
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar ? Storage::disk('public')->url($this->avatar) : null;
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
