<?php

namespace App\Filament\Tenant\Resources\InvoiceResource\Pages;

use App\Filament\Concerns\HasContainedTabs;
use App\Filament\Tenant\Resources\InvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends ListRecords
{
    use HasContainedTabs;

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function getTabs(): array
    {
        return [
            'outstanding' => Tab::make('Outstanding')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotIn('status', ['paid', 'cancelled'])),
            'paid' => Tab::make('Paid')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'paid')),
        ];
    }
}
