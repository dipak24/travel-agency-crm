<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksPlatformPermission;
use App\Filament\Resources\PlatformEmailTemplateResource\Pages;
use App\Models\PlatformEmailTemplate;
use App\Support\SystemEmailTypes;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * The admin panel's email templates, in two tabs on one table (`platform_email_templates`):
 *
 * - Campaign templates (`manage marketing`): the platform's own marketing emails, sent through
 *   Campaigns to tenants or SaaS leads. Active/Draft/Archived; freely created.
 * - System emails (`manage platform`): the platform's account emails — admin/staff password
 *   resets, the SMTP test email (App\Support\SystemEmailTypes). Always on; seeded one per key;
 *   only the wording is editable.
 *
 * Tenants never see either — their own transactional and campaign templates live in the tenant
 * panel's Email Templates (a separate table, `email_templates`).
 */
class PlatformEmailTemplateResource extends Resource
{
    use ChecksPlatformPermission;

    /**
     * Categories a campaign template may use (`system` is reserved for system emails).
     */
    public const CAMPAIGN_CATEGORIES = [
        'announcement' => 'Announcement',
        'newsletter' => 'Newsletter',
        'promotion' => 'Promotion',
        'onboarding' => 'Onboarding',
    ];

    public const CAMPAIGN_MERGE_TAGS = ['recipient_name', 'recipient_email', 'unsubscribe_url'];

    protected static ?string $model = PlatformEmailTemplate::class;

    protected static ?string $navigationLabel = 'Email Templates';

    protected static ?string $modelLabel = 'email template';

    protected static ?string $slug = 'email-templates';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static UnitEnum|string|null $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 1;

    public static function canManageCampaignTemplates(): bool
    {
        return static::platformUserCan('manage marketing');
    }

    public static function canManageSystemEmails(): bool
    {
        return static::platformUserCan('manage platform');
    }

    /**
     * Rows are limited to the kinds this admin may manage, not just filtered by tab.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->when(! static::canManageSystemEmails(), fn (Builder $query) => $query->marketing())
            ->when(! static::canManageCampaignTemplates(), fn (Builder $query) => $query->system());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template')
                ->schema([
                    Placeholder::make('merge_tags')
                        ->label('Available merge tags')
                        ->content(fn (?PlatformEmailTemplate $record): HtmlString => static::mergeTagHelp($record))
                        ->columnSpanFull(),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->disabled(fn (?PlatformEmailTemplate $record): bool => (bool) $record?->isSystem()),
                    // System emails are always on and have a fixed category — only their wording is editable.
                    Select::make('category')
                        ->options(self::CAMPAIGN_CATEGORIES)
                        ->default('announcement')
                        ->required()
                        ->hidden(fn (?PlatformEmailTemplate $record): bool => (bool) $record?->isSystem()),
                    Select::make('status')
                        ->options(['draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'])
                        ->helperText('Only active templates can be picked for a campaign.')
                        ->default('draft')
                        ->required()
                        ->hidden(fn (?PlatformEmailTemplate $record): bool => (bool) $record?->isSystem()),
                    TextInput::make('subject')->required()->maxLength(255)->columnSpanFull(),
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
                ->columns(3)
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
                    ->description(fn (PlatformEmailTemplate $record): ?string => $record->isLockedByRunningCampaign()
                        ? 'Locked — used by a scheduled or sending campaign'
                        : null),
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::CAMPAIGN_CATEGORIES[$state] ?? $state)
                    ->visible(fn ($livewire): bool => ($livewire->activeTab ?? null) === 'campaign'),
                TextColumn::make('subject')->limit(60),
                TextColumn::make('status')
                    ->visible(fn ($livewire): bool => ($livewire->activeTab ?? null) === 'campaign')
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
            'index' => Pages\ListPlatformEmailTemplates::route('/'),
            'create' => Pages\CreatePlatformEmailTemplate::route('/create'),
            'edit' => Pages\EditPlatformEmailTemplate::route('/{record}/edit'),
        ];
    }

    private static function mergeTagHelp(?PlatformEmailTemplate $record): HtmlString
    {
        $definition = $record?->isSystem() ? (SystemEmailTypes::all()[$record->key] ?? null) : null;
        $tags = $definition['merge_tags'] ?? self::CAMPAIGN_MERGE_TAGS;
        $description = $definition ? e($definition['description']).'<br>' : '';

        $list = collect($tags)->map(fn (string $tag): string => '<code>{{ '.e($tag).' }}</code>')->implode(' ');

        return new HtmlString("{$description}Type these anywhere in the subject or body: {$list}");
    }
}
