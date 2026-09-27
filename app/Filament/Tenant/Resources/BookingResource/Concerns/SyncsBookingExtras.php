<?php

namespace App\Filament\Tenant\Resources\BookingResource\Concerns;

use App\Filament\Tenant\Resources\BookingResource;
use App\Filament\Tenant\Resources\BookingResource\BookingFormState;
use App\Models\BookingDocument;
use App\Models\BookingIncludeExclude;
use App\Models\FixedDeparture;
use App\Services\BookingPricing;
use App\Services\BookingSchedule;

/**
 * Shared save logic for CreateBooking and EditBooking. Syncs the form sections that aren't bound
 * to an Eloquent relationship (inclusion/exclusion rows, per-type document uploads), moves
 * fixed-departure seats, then re-prices the booking from its saved rows. Both pages run this
 * inside their database transaction, so a failed seat reservation rolls the whole save back.
 */
trait SyncsBookingExtras
{
    /**
     * Seats the booking held before this save: [departure, pax].
     *
     * @var array{0: ?FixedDeparture, 1: int}
     */
    protected array $seatsHeldBeforeSave = [null, 0];

    protected function syncBookingExtras(): void
    {
        $uploadedBy = optional(auth('tenant')->user())->email ?? 'staff';

        BookingIncludeExclude::syncRows($this->record, BookingFormState::stateToRows(
            $this->data[BookingFormState::INCLUSIONS] ?? [],
            $this->data[BookingFormState::EXCLUSIONS] ?? [],
        ));

        foreach (array_keys(BookingResource::documentTypes()) as $docType) {
            BookingDocument::syncForBookingDocType(
                $this->record,
                $docType,
                $this->data['document_files'][$docType] ?? [],
                $uploadedBy,
            );
        }

        app(BookingSchedule::class)->moveSeats($this->seatsHeldBeforeSave, $this->record->refresh(), 'data.');

        app(BookingPricing::class)->recalculate($this->record);
    }
}
