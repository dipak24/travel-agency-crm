<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionPlanResource\Pages;
use App\Models\SubscriptionPlan;
use BackedEnum;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static ?string $navigationLabel = 'Plans';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static UnitEnum|string|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = -2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Plan details')
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('price')
                        ->label('Price (minor units)')
                        ->helperText('E.g. 4999 = 49.99 in the plan\'s billing currency.')
                        ->numeric()->integer()->minValue(0)->required(),
                    Select::make('billing_cycle')->options([
                        'monthly' => 'Monthly',
                        'yearly' => 'Yearly',
                    ])->required()->default('monthly'),
                    Toggle::make('is_active')->label('Active')->default(true)
                        ->helperText('Inactive plans are hidden from the tenant creation/assignment selects.'),
                ])
                ->columns(1),
            Section::make('Feature limits')
                ->description('Arbitrary key/value limits enforced elsewhere (e.g. max_staff, max_bookings_per_month).')
                ->schema([
                    KeyValue::make('feature_limits')->keyPlaceholder('Feature')->valuePlaceholder('Limit')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight('bold')->searchable()->sortable(),
                TextColumn::make('price')->numeric()->sortable(),
                TextColumn::make('billing_cycle')->badge()->sortable(),
                ToggleColumn::make('is_active')->label('Active'),
                TextColumn::make('subscriptions_count')->label('Subscribers')->counts('subscriptions'),
                TextColumn::make('created_at')->date('M j, Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'edit' => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }
}
