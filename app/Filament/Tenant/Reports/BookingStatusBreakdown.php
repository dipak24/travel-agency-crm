<?php

namespace App\Filament\Tenant\Reports;

use App\Models\Booking;
use Filament\Widgets\ChartWidget;

/**
 * See the note on RevenueByMonth — deliberately outside app/Filament/Tenant/Widgets so it only
 * appears on the Reports page, not the Dashboard.
 */
class BookingStatusBreakdown extends ChartWidget
{
    protected static bool $isLazy = false;

    protected ?string $heading = 'Bookings by status';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $statuses = ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

        $counts = Booking::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'datasets' => [
                [
                    'data' => collect($statuses)->keys()->map(fn (string $status): int => (int) ($counts[$status] ?? 0))->all(),
                    'backgroundColor' => ['#94a3b8', '#60a5fa', '#fbbf24', '#34d399', '#f87171'],
                ],
            ],
            'labels' => array_values($statuses),
        ];
    }
}
