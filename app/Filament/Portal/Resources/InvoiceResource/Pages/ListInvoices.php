<?php

namespace App\Filament\Portal\Resources\InvoiceResource\Pages;

use App\Filament\Portal\Resources\InvoiceResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

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
