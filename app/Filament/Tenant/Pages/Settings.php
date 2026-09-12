<?php

namespace App\Filament\Tenant\Pages;

use App\Models\Tenant;
use App\Support\TenantContext;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;
use Spatie\Permission\Models\Permission;

class Settings extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?int $navigationSort = 100;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    #[Locked]
    public ?Tenant $tenant = null;

    public function mount(): void
    {
        $this->tenant = auth('tenant')->user()->tenant;

        $this->form->fill($this->tenant->only([
            'name', 'address', 'phone_number', 'mobile_number', 'timezone', 'logo',
            'billing_email', 'currency',
        ]));
    }

    public static function canAccess(): bool
    {
        $user = auth('tenant')->user();

        if (! $user) {
            return false;
        }

        app(TenantContext::class)->set($user->tenant);

        if (! Permission::query()->where('name', 'manage settings')->where('guard_name', 'tenant')->exists()) {
            return $user->hasRole('Tenant Owner');
        }

        return $user->hasPermissionTo('manage settings');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Business details')
                ->description('Managed by the platform team — contact support to update your business name, contact details, or branding.')
                ->schema([
                    TextInput::make('name')->label('Business name')->disabled()->maxLength(255),
                    TextInput::make('address')->label('Business address')->disabled()->maxLength(255)->columnSpanFull(),
                    TextInput::make('phone_number')->tel()->disabled()->maxLength(255),
                    TextInput::make('mobile_number')->tel()->disabled()->maxLength(255),
                    Select::make('timezone')->disabled()->searchable()
                        ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers())),
                    FileUpload::make('logo')
                        ->disabled()
                        ->image()
                        ->disk('public')
                        ->directory('tenant-logos')
                        ->visibility('public')
                        ->imagePreviewHeight('120')
                        ->maxSize(2048)
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Billing details')
                ->description('Where invoices and billing correspondence are sent.')
                ->schema([
                    TextInput::make('billing_email')->label('Billing email')->email()->maxLength(255),
                    Select::make('currency')->searchable()->options([
                        'USD' => 'USD - US Dollar',
                        'EUR' => 'EUR - Euro',
                        'GBP' => 'GBP - British Pound',
                        'AUD' => 'AUD - Australian Dollar',
                        'CAD' => 'CAD - Canadian Dollar',
                        'AED' => 'AED - UAE Dirham',
                        'INR' => 'INR - Indian Rupee',
                        'NPR' => 'NPR - Nepalese Rupee',
                        'JPY' => 'JPY - Japanese Yen',
                    ])->required(),
                ])
                ->columns(2),
        ])->statePath('data')->columns(1);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $this->tenant->update($data);

        Notification::make()->success()->title('Settings saved')->send();
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
