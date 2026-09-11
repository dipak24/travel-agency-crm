<?php

namespace App\Filament\Tenant\Resources\BookingResource\Concerns;

use App\Filament\Tenant\Resources\BookingResource;
use App\Models\BookingDocument;
use App\Models\BookingIncludeExclude;

/**
 * Syncs the two form sections that aren't backed by a real Eloquent relationship binding — the
 * include/exclude checkbox list and the per-document-type upload fields — against the record,
 * once it (and its id) actually exists. Shared by CreateBooking's afterCreate() and EditBooking's
 * afterSave(), since both need the identical reconciliation logic.
 */
trait SyncsBookingExtras
{
    protected function syncBookingExtras(): void
    {
        $uploadedBy = optional(auth('tenant')->user())->email ?? 'staff';

        BookingIncludeExclude::syncForBooking($this->record, $this->data['include_exclude_selection'] ?? []);

        foreach (array_keys(BookingResource::documentTypes()) as $docType) {
            BookingDocument::syncForBookingDocType(
                $this->record,
                $docType,
                $this->data['document_files'][$docType] ?? [],
                $uploadedBy,
            );
        }
    }
}
