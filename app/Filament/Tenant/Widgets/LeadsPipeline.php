<?php

namespace App\Filament\Tenant\Widgets;

use App\Models\Lead;
use Filament\Widgets\ChartWidget;

class LeadsPipeline extends ChartWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Leads pipeline';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $statuses = ['new' => 'New', 'contacted' => 'Contacted', 'negotiating' => 'Negotiating', 'won' => 'Won', 'lost' => 'Lost'];

        $counts = Lead::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'datasets' => [
                [
                    'label' => 'Leads',
                    'data' => collect($statuses)->keys()->map(fn (string $status): int => (int) ($counts[$status] ?? 0))->all(),
                    'backgroundColor' => ['#94a3b8', '#60a5fa', '#fbbf24', '#34d399', '#f87171'],
                ],
            ],
            'labels' => array_values($statuses),
        ];
    }
}
