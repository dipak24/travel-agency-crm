<?php

namespace App\Models;

use Filament\Panel;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class SuperAdmin extends Model implements Authenticatable
{
    use AuthenticatableTrait, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'status', 'avatar'];

    protected $hidden = ['password', 'remember_token'];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === 'active' && $panel->getId() === 'admin';
    }

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }
}
