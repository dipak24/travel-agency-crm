<?php

namespace App\Filament\Tenant\Resources\PackageResource\RelationManagers;

use App\Filament\Tenant\Resources\GroupDiscountTierResource;
use App\Models\GroupDiscountTier;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Group discounts for this package only. They apply to fixed-departure and private-group bookings
 * and win over the agency's all-package tiers.
 */
class DiscountTiersRelationManager extends RelationManager
{
    protected static string $relationship = 'discountTiers';

    protected static ?string $title = 'Group discounts';

    public function form(Schema $schema): Schema
    {
        return $schema->components(GroupDiscountTierResource::tierFields())->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('min_pax')->label('From pax'),
                TextColumn::make('max_pax')->label('To pax')->placeholder('No limit'),
                TextColumn::make('discount_value')->label('Discount')
                    ->formatStateUsing(fn (GroupDiscountTier $record, $state): string => $record->discount_type === 'fixed'
                        ? Money::format($state, auth('tenant')->user()->tenant->currency ?? 'USD').' per person'
                        : "{$state}%"),
            ])
            ->defaultSort('min_pax')
            ->headerActions([CreateAction::make()->label('Add tier')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
