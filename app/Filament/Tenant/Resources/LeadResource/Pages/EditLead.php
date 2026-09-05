<?php

namespace App\Filament\Tenant\Resources\LeadResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\LeadResource;
use App\Models\Customer;
use App\Models\FixedDeparture;
use App\Models\Lead;
use App\Models\Package;
use App\Services\LeadConversion;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditLead extends EditRecord
{
    use HasFullWidthForm;

    protected static string $resource = LeadResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('convert')
                ->label('Convert to booking')
                ->icon('heroicon-o-arrow-right-circle')
                ->visible(fn (Lead $record): bool => $record->status !== 'won' && LeadResource::can('update', $record))
                ->form([
                    Select::make('customer_id')
                        ->label('Customer')
                        ->options(fn (): array => Customer::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),
                    Select::make('package_id')
                        ->label('Package')
                        ->options(fn (): array => Package::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                    Select::make('fixed_departure_id')
                        ->label('Fixed departure')
                        ->options(fn (): array => FixedDeparture::query()
                            ->with('package')
                            ->whereIn('status', ['open', 'full'])
                            ->get()
                            ->mapWithKeys(fn (FixedDeparture $departure): array => [
                                $departure->id => sprintf('%s - %s', $departure->package?->name, $departure->start_date?->toDateString()),
                            ])
                            ->all())
                        ->searchable(),
                ])
                ->action(function (Lead $record, array $data): void {
                    $customer = Customer::query()->findOrFail($data['customer_id']);
                    $fixedDeparture = isset($data['fixed_departure_id'])
                        ? FixedDeparture::query()->findOrFail($data['fixed_departure_id'])
                        : null;
                    $packageId = isset($data['package_id']) ? (int) $data['package_id'] : null;

                    app(LeadConversion::class)->convert(
                        $record,
                        $customer,
                        $packageId,
                        $fixedDeparture,
                        auth('tenant')->user(),
                    );

                    Notification::make()
                        ->success()
                        ->title('Lead converted to booking')
                        ->send();
                }),
        ];
    }
}
