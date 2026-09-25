<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SaasLeadResource\Pages;
use App\Models\SaasLead;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The platform's own sales pipeline — travel agencies interested in becoming tenants.
 */
class SaasLeadResource extends Resource
{
    public const STATUSES = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'demo_scheduled' => 'Demo scheduled',
        'converted' => 'Converted',
        'lost' => 'Lost',
    ];

    protected static ?string $model = SaasLead::class;

    protected static ?string $navigationLabel = 'SaaS Leads';

    protected static ?string $modelLabel = 'SaaS lead';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-funnel';

    protected static UnitEnum|string|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lead')
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    TextInput::make('company')->maxLength(255),
                    TextInput::make('source')->maxLength(255)->placeholder('e.g. website, referral, trade show'),
                    Select::make('status')->options(self::STATUSES)->default('new')->required(),
                    Textarea::make('notes')->rows(4)->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('company')->searchable()->placeholder('—'),
                TextColumn::make('source')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'converted' => 'success',
                        'lost' => 'danger',
                        'new' => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(self::STATUSES),
            ])
            ->recordActions([
                ActionGroup::make([EditAction::make(), DeleteAction::make()]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSaasLeads::route('/'),
            'create' => Pages\CreateSaasLead::route('/create'),
            'edit' => Pages\EditSaasLead::route('/{record}/edit'),
        ];
    }
}
