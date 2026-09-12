<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ChecksPlatformPermission;
use App\Models\Tenant;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TopPerformingTenants extends BaseWidget
{
    use ChecksPlatformPermission;

    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return static::platformUserCan('manage billing');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Top-performing tenants')
            ->description('Ranked by booking volume — revenue is shown in each tenant\'s own currency and is not comparable across rows.')
            ->query(
                Tenant::query()
                    ->withCount('bookings')
                    ->withSum(['payments as revenue' => fn ($query) => $query->where('status', 'completed')], 'amount')
                    ->orderByDesc('bookings_count')
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('bookings_count')->label('Bookings')->sortable(),
                TextColumn::make('revenue')
                    ->label('Revenue')
                    ->formatStateUsing(fn ($state, Tenant $record): string => $record->currency.' '.number_format(($state ?? 0) / 100, 2)),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'active' => 'success',
                    'suspended' => 'danger',
                    default => 'warning',
                }),
            ])
            ->paginated(false);
    }
}
