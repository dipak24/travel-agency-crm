<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ChecksPlatformPermission;
use App\Models\Booking;
use App\Models\Payment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformActivityOverview extends BaseWidget
{
    use ChecksPlatformPermission;

    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return static::platformUserCan('manage billing');
    }

    protected function getStats(): array
    {
        $revenueByCurrency = Payment::query()
            ->withoutGlobalScopes()
            ->where('status', 'completed')
            ->selectRaw('currency, sum(amount) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $stats = [
            Stat::make('Total bookings', Booking::query()->withoutGlobalScopes()->count()),
            Stat::make('Bookings this month', Booking::query()->withoutGlobalScopes()
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count()),
        ];

        if ($revenueByCurrency->isEmpty()) {
            $stats[] = Stat::make('Total revenue', 'No completed payments yet');
        } else {
            foreach ($revenueByCurrency as $currency => $total) {
                $stats[] = Stat::make("Revenue ({$currency})", $currency.' '.number_format($total / 100, 2))
                    ->color('success');
            }
        }

        return $stats;
    }
}
