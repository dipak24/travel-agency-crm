<?php

namespace App\Filament\Portal\Resources;

use App\Filament\Portal\Resources\BookingResource\Pages;
use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingIncludeExclude;
use App\Models\BookingTraveler;
use App\Services\BookingPricing;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationLabel = 'My Bookings';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-briefcase';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('customer_id', auth('customer')->id());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('trip_name')->label('Trip')->searchable()->sortable(),
                TextColumn::make('start_date')->date()->sortable(),
                TextColumn::make('end_date')->date()->sortable(),
                TextColumn::make('pax_count')->label('Travelers'),
                TextColumn::make('total_amount')->label('Total')->money(fn (Booking $record): string => $record->tenant?->currency ?? 'USD', divideBy: 100)->sortable(),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('start_date', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Trip overview')
                ->schema([
                    TextEntry::make('trip_name')->label('Trip'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('start_date')->date(),
                    TextEntry::make('end_date')->date(),
                    TextEntry::make('pax_count')->label('Travelers'),
                    TextEntry::make('total_amount')->label('Total')->money(fn (Booking $record): string => $record->tenant?->currency ?? 'USD', divideBy: 100),
                    TextEntry::make('description')->columnSpanFull(),
                ])
                ->columns(3)
                ->columnSpanFull(),
            Section::make('Price breakdown')
                ->key('price')
                ->schema([
                    TextEntry::make('price_breakdown')
                        ->hiddenLabel()
                        ->state(fn (Booking $record): HtmlString => app(BookingPricing::class)->quoteFor($record)->toHtml($record->tenant?->currency ?? 'USD'))
                        ->html(),
                ])
                ->visible(fn (Booking $record): bool => $record->per_person_price > 0)
                ->collapsible()
                ->columnSpanFull(),
            Section::make('Itinerary')
                ->schema([
                    TextEntry::make('booked_itinerary')->label('')->html(),
                ])
                ->visible(fn (Booking $record): bool => filled($record->booked_itinerary))
                ->columnSpanFull(),
            static::includeExcludeSection('include', 'What\'s included', 'heroicon-s-check-circle', 'success'),
            static::includeExcludeSection('exclude', 'What\'s not included', 'heroicon-s-x-circle', 'danger'),
            Section::make('Travelers')
                ->schema([
                    RepeatableEntry::make('travelers')
                        ->label('')
                        ->schema([
                            TextEntry::make('name')->label('Full name')->weight('bold'),
                            TextEntry::make('email')->placeholder('—'),
                            TextEntry::make('phone')->placeholder('—'),
                            TextEntry::make('dob')->label('Date of birth')->date()->placeholder('—'),
                            TextEntry::make('nationality.nationality')->label('Nationality')->placeholder('—'),
                            TextEntry::make('address')->placeholder('—'),
                            TextEntry::make('document_status')->badge(),
                            TextEntry::make('documents_summary')->label('Documents')
                                ->state(fn (BookingTraveler $record): string => $record->documents->isEmpty()
                                    ? 'None uploaded yet'
                                    : $record->documents
                                        ->map(fn (BookingDocument $document): string => (BookingDocument::TYPES[$document->doc_type] ?? 'Document')." ({$document->status})")
                                        ->implode(', ')),
                        ])
                        ->columns(4),
                ])
                ->visible(fn (Booking $record): bool => $record->travelers->isNotEmpty())
                ->columnSpanFull(),
            // One card per document type, mirroring the tenant booking form's per-type upload
            // fields. Uploads are add-only (BookingDocument::addCustomerUploads()): customers can't
            // remove or replace a document staff are reviewing or have approved.
            Section::make('Travel documents')
                ->key('documents')
                ->description('Upload the documents your trip requires — one file (or several) per document type. '.BookingDocument::UPLOAD_RULES_HINT)
                ->schema(collect(BookingDocument::TYPES)
                    ->map(fn (string $label, string $docType): Section => static::documentTypeCard($docType, $label))
                    ->values()
                    ->all())
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Add-ons')
                ->schema([
                    RepeatableEntry::make('addons')
                        ->label('')
                        ->schema([
                            TextEntry::make('name')->label('Service'),
                            TextEntry::make('quantity'),
                            TextEntry::make('price')->label('Price')->money(fn (): string => auth('customer')->user()->tenant?->currency ?? 'USD', divideBy: 100),
                            TextEntry::make('status')->badge(),
                        ])
                        ->columns(4)
                        ->contained(false),
                ])
                ->visible(fn (Booking $record): bool => $record->addons->isNotEmpty())
                ->columnSpanFull(),
            Section::make('Your note to staff')
                ->key('note')
                ->description('Anything our team should know about this trip — dietary needs, seat preferences, questions.')
                ->afterHeader([static::editNoteAction()])
                ->schema([
                    TextEntry::make('customer_notes')->hiddenLabel()->placeholder('You haven\'t left a note yet.'),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * The booking's included (green tick) or excluded (red cross) items, each in its own card.
     *
     * @param  'include'|'exclude'  $type
     */
    private static function includeExcludeSection(string $type, string $heading, string $icon, string $color): Section
    {
        $items = fn (Booking $record): Collection => $record->includeExcludes->where('type', $type)->values();

        return Section::make($heading)
            ->key("include-exclude-{$type}")
            ->schema([
                RepeatableEntry::make("include_exclude_{$type}")
                    ->hiddenLabel()
                    ->state($items)
                    ->schema([
                        TextEntry::make('title')
                            ->hiddenLabel()
                            ->icon($icon)
                            ->iconColor($color)
                            ->color($type === 'exclude' ? 'danger' : null)
                            ->weight('medium')
                            ->helperText(fn (BookingIncludeExclude $record): ?string => $record->description),
                    ])
                    ->contained(false),
            ])
            ->visible(fn (Booking $record): bool => $items($record)->isNotEmpty())
            ->columnSpan(1);
    }

    /**
     * One document type's card: its own Upload button, and every booking-level file uploaded for
     * that type with its review status. Per-traveller documents are listed under each traveller.
     */
    public static function documentTypeCard(string $docType, string $label): Section
    {
        $documentsOfType = fn (Booking $record): Collection => $record->documents
            ->where('doc_type', $docType)
            ->whereNull('booking_traveler_id')
            ->values();

        return Section::make($docType === 'other' ? "{$label} (optional, max ".BookingDocument::MAX_OTHER_FILES.' files)' : $label)
            ->key("documents-{$docType}")
            ->visible(fn (Booking $record): bool => $docType === 'other'
                || in_array($docType, $record->requiredDocumentTypes(), true)
                || $documentsOfType($record)->isNotEmpty())
            ->compact()
            ->description(fn (Booking $record): string => static::documentTypeSummary($documentsOfType($record)))
            ->afterHeader([static::uploadDocumentsAction($docType, $label)])
            ->schema([
                RepeatableEntry::make("documents_{$docType}")
                    ->hiddenLabel()
                    ->state($documentsOfType)
                    ->schema([
                        TextEntry::make('created_at')->label('Uploaded')->since(),
                        TextEntry::make('status')->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'approved' => 'success',
                                'rejected' => 'danger',
                                default => 'warning',
                            }),
                        TextEntry::make('rejection_reason')->label('Reason')
                            ->visible(fn (BookingDocument $record): bool => $record->status === 'rejected')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->contained(false)
                    ->visible(fn (Booking $record): bool => $documentsOfType($record)->isNotEmpty()),
                TextEntry::make("no_documents_{$docType}")
                    ->hiddenLabel()
                    ->state('Nothing uploaded yet.')
                    ->color('gray')
                    ->visible(fn (Booking $record): bool => $documentsOfType($record)->isEmpty()),
            ])
            ->columnSpan(1);
    }

    /**
     * @param  Collection<int, BookingDocument>  $documents
     */
    private static function documentTypeSummary(Collection $documents): string
    {
        if ($documents->isEmpty()) {
            return 'Not uploaded';
        }

        return collect(['approved' => 'approved', 'pending' => 'pending review', 'rejected' => 'rejected'])
            ->map(fn (string $label, string $status): ?string => ($count = $documents->where('status', $status)->count()) > 0 ? "{$count} {$label}" : null)
            ->filter()
            ->implode(' · ');
    }

    public static function uploadDocumentsAction(string $docType, string $label): Action
    {
        $isOther = $docType === 'other';

        return Action::make("upload_{$docType}")
            ->label('Upload')
            ->icon('heroicon-o-arrow-up-tray')
            ->size('sm')
            ->visible(fn (Booking $record): bool => ! $isOther || BookingDocument::otherFilesRemaining($record) > 0)
            ->modalHeading("Upload {$label}")
            ->modalDescription(fn (Booking $record): string => $isOther
                ? 'Optional extra documents. You can add '.BookingDocument::otherFilesRemaining($record).' more file(s) (max '.BookingDocument::MAX_OTHER_FILES.' in total). '.BookingDocument::UPLOAD_RULES_HINT
                : BookingDocument::UPLOAD_RULES_HINT)
            ->modalSubmitActionLabel('Upload')
            ->form([
                FileUpload::make('files')
                    ->label($label)
                    ->disk('local')
                    ->directory('booking-documents')
                    ->visibility('private')
                    ->multiple()
                    ->maxFiles(fn (Booking $record): ?int => $isOther ? max(1, BookingDocument::otherFilesRemaining($record)) : null)
                    ->required()
                    ->acceptedFileTypes(BookingDocument::ACCEPTED_MIME_TYPES)
                    ->minSize(BookingDocument::MIN_SIZE_KB)
                    ->maxSize(BookingDocument::MAX_SIZE_KB)
                    ->helperText($isOther
                        ? BookingDocument::OTHER_UPLOAD_HINT
                        : 'Select one or several files. PDF, JPG, PNG — 10 KB to 5 MB each.'),
            ])
            ->action(function (Booking $record, array $data) use ($docType, $label): void {
                $added = BookingDocument::addCustomerUploads($record, [$docType => $data['files'] ?? []], auth('customer')->user()->email);

                Notification::make()->title("{$label}: {$added} file(s) uploaded — pending staff review")->success()->send();
            });
    }

    public static function editNoteAction(): Action
    {
        return Action::make('editNote')
            ->label(fn (Booking $record): string => filled($record->customer_notes) ? 'Edit note' : 'Add a note')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->form([
                Textarea::make('customer_notes')
                    ->label('Note to staff')
                    ->rows(4)
                    ->maxLength(2000),
            ])
            ->fillForm(fn (Booking $record): array => ['customer_notes' => $record->customer_notes])
            ->action(function (Booking $record, array $data): void {
                $record->update(['customer_notes' => $data['customer_notes']]);

                Notification::make()->title('Note saved')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'view' => Pages\ViewBooking::route('/{record}'),
        ];
    }
}
