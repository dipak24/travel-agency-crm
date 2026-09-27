<?php

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Tenant\Reports\BookingStatusBreakdown;
use App\Filament\Tenant\Reports\LeadConversionOverview;
use App\Filament\Tenant\Reports\RevenueByMonth;
use App\Filament\Tenant\Widgets\StaffPerformance;
use App\Support\PlanFeatures;
use BackedEnum;
use Filament\Pages\Page;

class Reports extends Page
{
    use RequiresPlanFeature;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 90;

    protected function getHeaderWidgets(): array
    {
        return [
            LeadConversionOverview::class,
            RevenueByMonth::class,
            BookingStatusBreakdown::class,
            StaffPerformance::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected static function planFeature(): string
    {
        return PlanFeatures::REPORTS;
    }
}
