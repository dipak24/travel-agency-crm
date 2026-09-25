<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ConfiguresEmailCampaigns;
use App\Filament\Resources\PlatformCampaignResource\Pages;
use App\Models\EmailCampaign;
use App\Models\PlatformEmailTemplate;
use App\Services\Mail\CampaignSender;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The platform's own campaigns — to tenants (billing email, falling back to the tenant's first
 * user) or to SaaS leads — from an active PlatformEmailTemplate.
 */
class PlatformCampaignResource extends Resource
{
    use ConfiguresEmailCampaigns;

    protected static ?string $model = EmailCampaign::class;

    protected static ?string $navigationLabel = 'Campaigns';

    protected static ?string $modelLabel = 'campaign';

    protected static ?string $slug = 'campaigns';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-megaphone';

    protected static UnitEnum|string|null $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->forPlatform();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Campaign')
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    Select::make('template_id')
                        ->label('Campaign template')
                        ->options(fn (): array => PlatformEmailTemplate::query()
                            ->marketing()
                            ->where('status', 'active')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->helperText('Only active campaign templates are listed (Communication → Email Templates → Campaign templates).')
                        ->required(),
                    TextInput::make('subject_override')
                        ->label('Subject (optional)')
                        ->helperText('Leave empty to use the template\'s own subject.')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Select::make('audience_type')
                        ->label('Audience')
                        ->options(CampaignSender::AUDIENCES[EmailCampaign::OWNER_PLATFORM])
                        ->default('tenants')
                        ->live()
                        ->required(),
                    CheckboxList::make('audience_filter.lead_statuses')
                        ->label('Lead statuses')
                        ->helperText('Leave all unchecked to include every lead.')
                        ->options(SaasLeadResource::STATUSES)
                        ->visible(fn (Get $get): bool => $get('audience_type') === 'saas_leads'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(static::campaignColumns(EmailCampaign::OWNER_PLATFORM))
            ->defaultSort('created_at', 'desc')
            ->recordActions(static::campaignActions());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlatformCampaigns::route('/'),
            'create' => Pages\CreatePlatformCampaign::route('/create'),
            'edit' => Pages\EditPlatformCampaign::route('/{record}/edit'),
        ];
    }
}
