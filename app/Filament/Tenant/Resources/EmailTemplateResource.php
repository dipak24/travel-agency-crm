<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\EmailTemplateResource\Pages;
use App\Models\EmailTemplate;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * A tenant's email templates. Transactional templates (portal invites, booking updates, reminders,
 * ...) are seeded one per type and can only be edited or reset; marketing templates are created
 * freely and used by EmailCampaignResource.
 */
class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static UnitEnum|string|null $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 1;

    /**
     * Merge tags every marketing template can use (see CampaignSender).
     */
    public const MARKETING_MERGE_TAGS = ['recipient_name', 'recipient_email', 'tenant_name', 'unsubscribe_url'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template')
                ->schema([
                    Placeholder::make('merge_tags')
                        ->label('Available merge tags')
                        ->content(fn (?EmailTemplate $record): HtmlString => static::mergeTagHelp($record))
                        ->columnSpanFull(),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->disabled(fn (?EmailTemplate $record): bool => (bool) $record?->isTransactional()),
                    // Transactional emails are always on — only their wording is editable.
                    Select::make('status')
                        ->options(['active' => 'Active', 'draft' => 'Draft', 'archived' => 'Archived'])
                        ->helperText('Only active templates can be picked for a campaign.')
                        ->default('draft')
                        ->required()
                        ->hidden(fn (?EmailTemplate $record): bool => (bool) $record?->isTransactional()),
                    TextInput::make('subject')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    RichEditor::make('body_html')
                        ->label('Body')
                        ->required()
                        ->toolbarButtons([
                            ['bold', 'italic', 'underline', 'strike', 'link'],
                            ['h2', 'h3'],
                            ['bulletList', 'orderedList', 'blockquote'],
                            ['undo', 'redo'],
                        ])
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (EmailTemplate $record): ?string => $record->isLockedByRunningCampaign()
                        ? 'Locked — used by a scheduled or sending campaign'
                        : null),
                TextColumn::make('subject')->limit(60)->searchable(),
                TextColumn::make('status')
                    ->visible(fn ($livewire): bool => ($livewire->activeTab ?? null) === 'marketing')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'archived' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                ActionGroup::make([EditAction::make(), DeleteAction::make()]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailTemplates::route('/'),
            'create' => Pages\CreateEmailTemplate::route('/create'),
            'edit' => Pages\EditEmailTemplate::route('/{record}/edit'),
        ];
    }

    private static function mergeTagHelp(?EmailTemplate $record): HtmlString
    {
        $tags = $record?->isTransactional()
            ? ($record->type?->available_merge_tags ?? [])
            : self::MARKETING_MERGE_TAGS;

        $list = collect($tags)->map(fn (string $tag): string => '<code>{{ '.e($tag).' }}</code>')->implode(' ');
        $description = $record?->type?->description ? e($record->type->description).'<br>' : '';

        return new HtmlString("{$description}Type these anywhere in the subject or body: {$list}");
    }
}
