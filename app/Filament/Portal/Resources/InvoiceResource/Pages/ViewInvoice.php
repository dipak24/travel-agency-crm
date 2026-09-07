<?php

namespace App\Filament\Portal\Resources\InvoiceResource\Pages;

use App\Filament\Portal\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\InvoiceGiftVoucherRedemption;
use App\Services\InvoicePromoRedemption;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('applyPromoCode')
                ->label('Enter promo code')
                ->icon('heroicon-o-tag')
                ->visible(fn (Invoice $record): bool => $record->balanceDue() > 0)
                ->form([
                    TextInput::make('code')->label('Promo code')->required()->maxLength(255),
                ])
                ->action(function (Invoice $record, array $data): void {
                    try {
                        app(InvoicePromoRedemption::class)->apply($record, $data['code']);
                    } catch (LogicException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Promo code applied')->success()->send();
                }),
            Action::make('redeemGiftVoucher')
                ->label('Redeem gift voucher')
                ->icon('heroicon-o-gift')
                ->visible(fn (Invoice $record): bool => $record->balanceDue() > 0)
                ->form([
                    TextInput::make('code')->label('Gift voucher code')->required()->maxLength(255),
                ])
                ->action(function (Invoice $record, array $data): void {
                    try {
                        app(InvoiceGiftVoucherRedemption::class)->redeem($record, $data['code']);
                    } catch (LogicException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Gift voucher redeemed')->success()->send();
                }),
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function (Invoice $record) {
                    $record->loadMissing(['tenant', 'customer', 'booking']);

                    return response()->streamDownload(
                        fn () => print (Pdf::loadView('pdf.invoice', ['invoice' => $record])->output()),
                        "{$record->invoice_no}.pdf",
                    );
                }),
        ];
    }
}
