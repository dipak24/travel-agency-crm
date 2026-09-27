<?php

namespace App\Models;

use App\Support\Currencies;
use Illuminate\Database\Eloquent\Model;

/**
 * A single platform-wide row (Super Admin only, admin Platform → System Settings): the currencies
 * tenants may pick from, the default currency for new tenants, and platform maintenance mode.
 * Until the row is first saved, current() returns an unsaved instance holding the defaults.
 */
class PlatformSetting extends Model
{
    protected $fillable = [
        'currencies',
        'default_currency',
        'maintenance_mode',
        'maintenance_message',
    ];

    protected function casts(): array
    {
        return [
            'currencies' => 'array',
            'maintenance_mode' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new self([
            'currencies' => Currencies::DEFAULT_ENABLED,
            'default_currency' => 'USD',
            'maintenance_mode' => false,
            'maintenance_message' => null,
        ]);
    }

    public static function inMaintenance(): bool
    {
        return (bool) static::query()->where('maintenance_mode', true)->exists();
    }
}
