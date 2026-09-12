<?php

namespace App\Filament\Tenant\Reports;

use App\Models\Lead;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * See the note on RevenueByMonth — deliberately outside app/Filament/Tenant/Widgets so it only
 * appears on the Reports page, not the Dashboard.
 */
class LeadConversionOverview extends BaseWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $total = Lead::query()->count();
        $won = Lead::query()->where('status', 'won')->count();
        $lost = Lead::query()->where('status', 'lost')->count();
        $rate = $total > 0 ? round(($won / $total) * 100, 1) : 0;

        return [
            Stat::make('Total leads', $total),
            Stat::make('Won', $won)->color('success'),
            Stat::make('Lost', $lost)->color('danger'),
            Stat::make('Conversion rate', "{$rate}%")->color($rate >= 20 ? 'success' : 'warning'),
        ];
    }
}
