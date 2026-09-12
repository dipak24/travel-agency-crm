<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\PromoCodeResource\Pages;
use App\Models\PromoCode;
use App\Support\Money;
use App\Support\TenantContext;
use BackedEnum;
use Carbon\Carbon;
use Closure;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PromoCodeResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    protected static ?string $navigationLabel = 'Promo Codes';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-ticket';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->required()
                ->minLength(6)
                ->maxLength(12)
                ->regex('/^[A-Za-z0-9]+$/')
                ->helperText('6-12 characters, letters and numbers only. Always stored in capitals.')
                ->validationMessages([
                    'regex' => 'Only letters (A-Z) and numbers (0-9) are allowed.',
                ])
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state))
                ->rules(fn (?PromoCode $record): array => [
                    function (string $attribute, $value, Closure $fail) use ($record): void {
                        $exists = PromoCode::query()
                            ->where('tenant_id', app(TenantContext::class)->id())
                            ->where('code', strtoupper((string) $value))
                            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                            ->exists();

                        if ($exists) {
                            $fail('This promo code is already in use.');
                        }
                    },
                ]),
            Select::make('discount_type')
                ->options([
                    'percent' => 'Percent',
                    'flat' => 'Flat amount',
                ])
                ->required()
                ->live(),
            TextInput::make('discount_value')
                ->label(fn (Get $get): string => $get('discount_type') === 'percent' ? 'Discount (%)' : 'Discount amount')
                ->suffix(fn (Get $get): ?string => $get('discount_type') === 'percent' ? '%' : null)
                ->helperText(fn (Get $get): ?string => $get('discount_type') === 'flat'
                    ? 'Enter the amount in normal currency units, e.g. 25.00 — not minor units.'
                    : null)
                ->numeric()
                ->required()
                ->default(0)
                ->rules(fn (Get $get): array => $get('discount_type') === 'percent'
                    ? ['numeric', 'min:1', 'max:99']
                    : ['numeric', 'min:0.01'])
                ->afterStateHydrated(function (TextInput $component, $state, Get $get): void {
                    if ($get('discount_type') === 'flat' && filled($state)) {
                        $component->state(Money::toDecimal($state));
                    }
                })
                ->dehydrateStateUsing(fn ($state, Get $get) => $get('discount_type') === 'flat'
                    ? Money::toCents($state)
                    : (int) $state),
            TextInput::make('usage_limit')->numeric()->integer()->minValue(1)->helperText('Leave blank for unlimited uses.'),
            TextInput::make('used_count')->numeric()->integer()->minValue(0)->disabled()->dehydrated(false)->hiddenOn('create'),
            DateTimePicker::make('valid_from')
                ->native(false)
                ->rules([
                    fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                        if (filled($value) && Carbon::parse($value)->lt(now()->startOfDay())) {
                            $fail('Valid from cannot be a past date.');
                        }
                    },
                ]),
            DateTimePicker::make('valid_until')
                ->native(false)
                ->rules(fn (Get $get): array => [
                    function (string $attribute, $value, Closure $fail) use ($get): void {
                        if (blank($value)) {
                            return;
                        }

                        if (Carbon::parse($value)->lt(now()->startOfDay())) {
                            $fail('Valid until cannot be a past date.');

                            return;
                        }

                        $validFrom = $get('valid_from');

                        if (filled($validFrom) && Carbon::parse($value)->lt(Carbon::parse($validFrom))) {
                            $fail('Valid until must be on or after the valid from date.');
                        }
                    },
                ]),
            Select::make('packages')
                ->relationship('packages', 'name')
                ->multiple()
                ->searchable()
                ->preload()
                ->helperText('Leave empty to apply this code globally, to every package. Select one or more packages to restrict it to only those.'),
            Toggle::make('is_active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('discount_type')->badge(),
            TextColumn::make('discount_value')
                ->formatStateUsing(fn (PromoCode $record, $state): string => $record->discount_type === 'flat'
                    ? Money::format($state, auth('tenant')->user()->tenant->currency ?? 'USD')
                    : "{$state}%"),
            TextColumn::make('packages_count')->counts('packages')->label('Scope')
                ->formatStateUsing(fn (int $state): string => $state > 0 ? "{$state} package(s)" : 'Global'),
            TextColumn::make('usage_limit')->placeholder('Unlimited'),
            TextColumn::make('used_count')->label('Used'),
            TextColumn::make('valid_until')->label('Expires')->dateTime()->placeholder('Never')->sortable(),
            IconColumn::make('is_active')->boolean(),
        ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromoCodes::route('/'),
            'create' => Pages\CreatePromoCode::route('/create'),
            'edit' => Pages\EditPromoCode::route('/{record}/edit'),
        ];
    }
}
