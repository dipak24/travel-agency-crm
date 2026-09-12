<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ChecksPlatformPermission;
use App\Models\Tenant;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TenantOverview extends BaseWidget
{
    use ChecksPlatformPermission;

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return static::platformUserCan('manage tenants');
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Total tenants', Tenant::query()->count()),
            Stat::make('Active', Tenant::query()->where('status', 'active')->count())->color('success'),
            Stat::make('Trial', Tenant::query()->where('status', 'trial')->count())->color('warning'),
            Stat::make('Suspended', Tenant::query()->where('status', 'suspended')->count())->color('danger'),
        ];
    }
}
