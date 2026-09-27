<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ChecksPlatformPermission;
use App\Models\PlatformSetting;
use App\Support\Currencies;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;
use UnitEnum;

/**
 * Platform-wide settings: which currencies tenants may use, the default for new tenants, and
 * maintenance mode for every agency-facing surface (see EnsurePlatformAvailable). Plan feature
 * toggles live on each plan (Billing → Plans).
 */
class SystemSettings extends Page
{
    use ChecksPlatformPermission;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'System Settings';

    protected static UnitEnum|string|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 20;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = PlatformSetting::current();

        $this->form->fill([
            'currencies' => Currencies::enabled(),
            'default_currency' => Currencies::defaultCode(),
            'maintenance_mode' => $settings->maintenance_mode,
            'maintenance_message' => $settings->maintenance_message,
        ]);
    }

    public static function canAccess(): bool
    {
        return self::platformUserCan('manage platform');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Currencies')
                ->description('Currencies tenants can choose for their agency and that platform invoices can be raised in. Records already using a disabled currency keep it.')
                ->schema([
                    CheckboxList::make('currencies')
                        ->label('Enabled currencies')
                        ->options(collect(Currencies::ALL)->mapWithKeys(fn (string $name, string $code): array => [$code => "{$code} - {$name}"])->all())
                        ->columns(3)
                        ->required()
                        ->live()
                        ->columnSpanFull(),
                    Select::make('default_currency')
                        ->label('Default currency for new tenants')
                        ->options(fn (Get $get): array => collect((array) $get('currencies'))->mapWithKeys(fn (string $code): array => [$code => $code])->all())
                        ->rule(fn (Get $get) => Rule::in((array) $get('currencies')))
                        ->validationMessages(['in' => 'The default currency must be one of the enabled currencies.'])
                        ->required(),
                ]),
            Section::make('Maintenance mode')
                ->description('While on, the staff panel, customer portal, payment pages and public API show a maintenance notice. This admin panel and payment webhooks keep working.')
                ->schema([
                    Toggle::make('maintenance_mode')->label('Maintenance mode on')->live(),
                    Textarea::make('maintenance_message')
                        ->label('Message shown to staff and customers')
                        ->placeholder('We are carrying out scheduled maintenance and will be back shortly.')
                        ->rows(3)
                        ->maxLength(1000),
                ]),
        ])->statePath('data')->columns(1);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $settings = PlatformSetting::query()->first() ?? new PlatformSetting;
        $settings->fill([
            'currencies' => array_values($data['currencies']),
            'default_currency' => $data['default_currency'],
            'maintenance_mode' => (bool) $data['maintenance_mode'],
            'maintenance_message' => $data['maintenance_message'] ?: null,
        ]);
        $settings->save();

        Notification::make()->success()->title('System settings saved')->send();
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Save')->submit('save'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make($this->getFormActions())->key('form-actions'),
                ]),
        ]);
    }
}
