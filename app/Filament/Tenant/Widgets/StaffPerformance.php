<?php

namespace App\Filament\Tenant\Widgets;

use App\Models\TenantUser;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class StaffPerformance extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Staff performance')
            ->query(
                TenantUser::query()
                    ->withCount([
                        'assignedLeads',
                        'assignedLeads as leads_won_count' => fn ($query) => $query->where('status', 'won'),
                        'createdBookings',
                    ])
            )
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('assigned_leads_count')->label('Leads assigned')->sortable(),
                TextColumn::make('leads_won_count')->label('Leads won')->sortable(),
                TextColumn::make('created_bookings_count')->label('Bookings created')->sortable(),
            ])
            ->defaultSort('created_bookings_count', 'desc')
            ->paginated(false);
    }
}
