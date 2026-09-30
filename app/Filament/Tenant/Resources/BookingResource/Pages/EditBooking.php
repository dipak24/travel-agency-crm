<?php

namespace App\Filament\Tenant\Resources\BookingResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Tenant\Resources\BookingResource;
use App\Filament\Tenant\Resources\BookingResource\Concerns\SyncsBookingExtras;
use App\Filament\Tenant\Resources\InvoiceResource;
use App\Models\Booking;
use App\Models\Invoice;
use App\Services\BookingInvoicing;
use App\Services\BookingPricing;
use App\Services\BookingSchedule;
use App\Services\InvoicePaymentLinks;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class EditBooking extends EditRecord
{
    use HasFullWidthForm, SyncsBookingExtras;

    protected static string $resource = BookingResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('recalculatePrice')
                    ->label('Recalculate price')
                    ->icon('heroicon-o-calculator')
                    ->action(function (): void {
                        app(BookingPricing::class)->recalculate($this->record);
                        $this->refreshFormData(['per_person_price', 'manual_adjustment']);
                        Notification::make()->title('Price recalculated')->success()->send();
                    }),
                Action::make('createInvoice')
                    ->label('Create invoice from booking')
                    ->icon('heroicon-o-document-text')
                    ->requiresConfirmation()
                    ->modalDescription('Creates a draft invoice with one line per price line of this booking. Save any unsaved changes first.')
                    ->visible(fn (): bool => (bool) auth('tenant')->user()?->can('create', Invoice::class))
                    ->action(function (): void {
                        $invoice = app(BookingInvoicing::class)->createDraft($this->record, auth('tenant')->user());

                        $this->redirect(InvoiceResource::getUrl('edit', ['record' => $invoice]));
                    }),
                Action::make('paymentLink')
                    ->label('Copy payment link')
                    ->icon('heroicon-o-clipboard-document')
                    ->visible(fn (): bool => $this->openInvoices()->isNotEmpty())
                    ->modalHeading('Payment link')
                    ->modalDescription('Send this link to the customer by WhatsApp, SMS or email. They can pay without logging in, using the payment methods enabled for your agency.')
                    ->modalIcon('heroicon-o-link')
                    ->schema(fn (): array => [
                        Select::make('invoice_id')
                            ->label('Invoice')
                            ->options(fn (): array => $this->openInvoices()->mapWithKeys(fn (Invoice $invoice): array => [
                                $invoice->id => "{$invoice->invoice_no} — ".Money::format($invoice->balanceDue(), $invoice->currency).' due'
                                    .match (true) {
                                        $invoice->status === 'draft' => ' (draft — issue it first)',
                                        ! app(InvoicePaymentLinks::class)->canBePaidOnline($invoice) => ' (no payment method enabled)',
                                        default => '',
                                    },
                            ])->all())
                            ->default(fn (): ?int => $this->openInvoices()->first()?->id)
                            ->selectablePlaceholder(false)
                            ->live(),
                        ...InvoiceResource::paymentLinkFields(fn (Get $get): ?Invoice => $this->openInvoices()
                            ->first(fn (Invoice $invoice): bool => $invoice->id === (int) $get('invoice_id')
                                && app(InvoicePaymentLinks::class)->canBePaidOnline($invoice))),
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('downloadAllDocuments')
                    ->label('Download all documents')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->visible(fn (): bool => $this->record->documents()->exists())
                    ->action(fn (): ?BinaryFileResponse => $this->downloadAllDocuments()),
            ])
                ->label('Booking actions')
                ->button()
                ->color('gray'),
        ];
    }

    protected function beforeSave(): void
    {
        $this->seatsHeldBeforeSave = app(BookingSchedule::class)->seatsHeld($this->record);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return BookingResource::prepareFormData($data);
    }

    protected function afterSave(): void
    {
        $this->syncBookingExtras();
    }

    /**
     * The booking's invoices that still have money owing, newest first.
     *
     * @return Collection<int, Invoice>
     */
    private function openInvoices(): Collection
    {
        return $this->record->invoices()
            ->whereNotIn('status', ['cancelled', 'paid'])
            ->latest()
            ->get()
            ->filter(fn (Invoice $invoice): bool => $invoice->balanceDue() > 0)
            ->values();
    }

    /**
     * Zips every document on the booking (booking-level and per-traveller) into one download.
     */
    private function downloadAllDocuments(): ?BinaryFileResponse
    {
        /** @var Booking $booking */
        $booking = $this->record;
        $disk = Storage::disk('local');
        $zipPath = tempnam(sys_get_temp_dir(), 'booking-docs');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($booking->documents()->with('traveler')->get() as $index => $document) {
            if (! $disk->exists($document->file_path)) {
                continue;
            }

            $folder = $document->traveler?->name ?? 'Booking';
            $zip->addFile($disk->path($document->file_path), sprintf('%s/%s-%d-%s', $folder, $document->doc_type, $index + 1, basename($document->file_path)));
        }

        $zip->close();

        return response()->download($zipPath, "booking-{$booking->getKey()}-documents.zip")->deleteFileAfterSend();
    }
}
