<?php

namespace App\Filament\Tenant\Resources;

use App\Enums\DiscountType;
use App\Filament\Tenant\Resources\GroupDiscountTierResource\Pages;
use App\Models\GroupDiscountTier;
use App\Models\Package;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class GroupDiscountTierResource extends Resource
{
    protected static ?string $model = GroupDiscountTier::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('package_id')->relationship('package', 'name')->searchable()->preload()
                ->helperText('Leave empty to apply to every package. A package\'s own tier wins over an all-package tier.'),
            ...static::tierFields(),
        ]);
    }

    /**
     * The tier's pax range and discount, shared with PackageResource's discount tiers tab.
     * A fixed discount is an amount off per person.
     *
     * @return array<int, Select|TextInput>
     */
    public static function tierFields(): array
    {
        return [
            TextInput::make('min_pax')->numeric()->integer()->minValue(1)->required(),
            TextInput::make('max_pax')->numeric()->integer()->minValue(1),
            Select::make('discount_type')->options(DiscountType::class)->required()->live(),
            TextInput::make('discount_value')
                ->label(fn (Get $get): string => static::isPercentage($get('discount_type')) ? 'Discount (%)' : 'Discount per person')
                ->suffix(fn (Get $get): ?string => static::isPercentage($get('discount_type')) ? '%' : null)
                ->numeric()
                ->minValue(0)
                ->required()
                ->afterStateHydrated(function (TextInput $component, $state, Get $get): void {
                    if (static::isFixed($get('discount_type')) && filled($state)) {
                        $component->state(Money::toDecimal($state));
                    }
                })
                ->dehydrateStateUsing(fn ($state, Get $get) => static::isFixed($get('discount_type'))
                    ? Money::toCents($state)
                    : (int) $state),
        ];
    }

    private static function isPercentage(mixed $type): bool
    {
        return ($type instanceof DiscountType ? $type->value : $type) === DiscountType::Percentage->value;
    }

    private static function isFixed(mixed $type): bool
    {
        return ($type instanceof DiscountType ? $type->value : $type) === DiscountType::Fixed->value;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('package.name')->label('Package')->placeholder('All packages'),
            TextColumn::make('min_pax'),
            TextColumn::make('max_pax')->placeholder('No limit'),
            TextColumn::make('discount_type')->badge(),
            TextColumn::make('discount_value')
                ->formatStateUsing(fn (GroupDiscountTier $record, $state): string => $record->discount_type === 'fixed'
                    ? Money::format($state, auth('tenant')->user()->tenant->currency ?? 'USD')
                    : "{$state}%"),
        ])
            ->filters([
                SelectFilter::make('package')
                    ->label('Package')
                    ->options(fn (): array => ['all' => 'All packages (no specific package)'] + Package::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'all' => $query->whereNull('package_id'),
                        default => $query->where('package_id', $data['value']),
                    }),
                SelectFilter::make('discount_type')
                    ->label('Discount type')
                    ->options(DiscountType::class),
            ])
            ->recordActions([
                ActionGroup::make([EditAction::make(), DeleteAction::make()])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->size('sm')
                    ->tooltip('Actions'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroupDiscountTiers::route('/'),
            'create' => Pages\CreateGroupDiscountTier::route('/create'),
            'edit' => Pages\EditGroupDiscountTier::route('/{record}/edit'),
        ];
    }
}
