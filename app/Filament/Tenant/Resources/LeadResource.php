<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\LeadResource\Pages;
use App\Models\Lead;
use App\Models\TenantUser;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-funnel';

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')->relationship('customer', 'name')->searchable()->preload(),
            Select::make('origin')->options([
                'manual' => 'Manual',
                'public_website' => 'Public website',
                'whatsapp' => 'WhatsApp',
                'referral' => 'Referral',
                'agent' => 'Agent',
            ])->required()->default('manual'),
            TextInput::make('source')->maxLength(255),
            TextInput::make('destination')->maxLength(255),
            TextInput::make('trip_type')->maxLength(255),
            TextInput::make('pax_count')->numeric()->integer()->minValue(1)->required()->default(1),
            TextInput::make('budget_range')->maxLength(255),
            Select::make('status')->options([
                'new' => 'New',
                'contacted' => 'Contacted',
                'negotiating' => 'Negotiating',
                'won' => 'Won',
                'lost' => 'Lost',
            ])->required()->default('new'),
            Select::make('assigned_staff_id')
                ->label('Assigned staff')
                ->options(fn (): array => TenantUser::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            DatePicker::make('follow_up_date'),
            Textarea::make('notes')->rows(4)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('customer.name')->label('Customer')->searchable(),
            TextColumn::make('destination')->searchable(),
            TextColumn::make('origin')->badge(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('assignedStaff.name')->label('Assigned to'),
            TextColumn::make('follow_up_date')->date()->sortable(),
        ])->filters([
            \Filament\Tables\Filters\SelectFilter::make('status')->options([
                'new' => 'New',
                'contacted' => 'Contacted',
                'negotiating' => 'Negotiating',
                'won' => 'Won',
                'lost' => 'Lost',
            ]),
        ])->defaultSort('follow_up_date');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeads::route('/'),
            'create' => Pages\CreateLead::route('/create'),
            'edit' => Pages\EditLead::route('/{record}/edit'),
        ];
    }
}
