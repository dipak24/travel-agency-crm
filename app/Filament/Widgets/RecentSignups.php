<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ChecksPlatformPermission;
use App\Models\Tenant;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentSignups extends BaseWidget
{
    use ChecksPlatformPermission;

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return static::platformUserCan('manage tenants');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent signups')
            ->query(Tenant::query()->latest()->limit(10))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'active' => 'success',
                    'suspended' => 'danger',
                    default => 'warning',
                }),
                TextColumn::make('currency'),
                TextColumn::make('created_at')->label('Signed up')->dateTime()->sortable(),
                TextColumn::make('trial_ends_at')->label('Trial ends')->date()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated(false);
    }
}
