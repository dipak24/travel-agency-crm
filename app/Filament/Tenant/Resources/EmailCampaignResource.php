<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Concerns\ConfiguresEmailCampaigns;
use App\Filament\Tenant\Resources\EmailCampaignResource\Pages;
use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
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
 * Mass email to the tenant's own customers or staff, from one of its active marketing templates.
 */
class EmailCampaignResource extends Resource
{
    use ConfiguresEmailCampaigns;

    protected static ?string $model = EmailCampaign::class;

    protected static ?string $navigationLabel = 'Campaigns';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-megaphone';

    protected static UnitEnum|string|null $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 2;

    /**
     * EmailCampaign isn't BelongsToTenant (platform campaigns have no tenant), so scope explicitly.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->forTenant((int) auth('tenant')->user()?->tenant_id);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Campaign')
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    Select::make('template_id')
                        ->label('Campaign template')
                        ->options(fn (): array => EmailTemplate::query()
                            ->where('category', EmailTemplate::CATEGORY_MARKETING)
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
                        ->options(CampaignSender::AUDIENCES[EmailCampaign::OWNER_TENANT])
                        ->default('customers')
                        ->live()
                        ->required(),
                    CheckboxList::make('audience_filter.customer_types')
                        ->label('Customer types')
                        ->helperText('Leave all unchecked to include every customer.')
                        ->options(['individual' => 'Individual', 'agency' => 'Agency', 'group_leader' => 'Group leader'])
                        ->visible(fn (Get $get): bool => $get('audience_type') === 'customers'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(static::campaignColumns(EmailCampaign::OWNER_TENANT))
            ->defaultSort('created_at', 'desc')
            ->recordActions(static::campaignActions());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailCampaigns::route('/'),
            'create' => Pages\CreateEmailCampaign::route('/create'),
            'edit' => Pages\EditEmailCampaign::route('/{record}/edit'),
        ];
    }
}
