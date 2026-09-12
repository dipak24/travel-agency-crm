<?php

namespace App\Filament\Tenant\Reports;

use App\Models\Payment;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Deliberately NOT under app/Filament/Tenant/Widgets — that directory is auto-discovered onto the
 * Dashboard (TenantPanelProvider::discoverWidgets()); this widget belongs only on the Reports page,
 * attached explicitly via App\Filament\Tenant\Pages\Reports::getHeaderWidgets().
 */
class RevenueByMonth extends ChartWidget
{
    protected static bool $isLazy = false;

    protected ?string $heading = 'Revenue by month (last 12 months)';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $tenant = auth('tenant')->user()->tenant;
        $months = collect(range(11, 0))->map(fn (int $i): Carbon => now()->subMonths($i)->startOfMonth());

        // Grouped in PHP rather than SQL (e.g. to_char/strftime) so this works identically on both
        // this app's Postgres (production) and SQLite (tests) — see .ai/rules/general.md.
        $totals = Payment::query()
            ->where('status', 'completed')
            ->where('type', '!=', 'refund')
            ->where('created_at', '>=', $months->first())
            ->get(['amount', 'created_at'])
            ->groupBy(fn (Payment $payment): string => $payment->created_at->format('Y-m'))
            ->map(fn ($group) => $group->sum('amount'));

        return [
            'datasets' => [
                [
                    'label' => 'Revenue ('.($tenant?->currency ?? 'USD').')',
                    'data' => $months->map(fn (Carbon $month): float => round(($totals[$month->format('Y-m')] ?? 0) / 100, 2))->all(),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'fill' => true,
                ],
            ],
            'labels' => $months->map(fn (Carbon $month): string => $month->format('M Y'))->all(),
        ];
    }
}
