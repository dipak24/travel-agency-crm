<?php

namespace App\Filament\Tenant\Pages;

use App\Models\TenantMailSetting;
use App\Notifications\TestMailSettingNotification;
use App\Services\Mail\TenantMailer;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;

class MailSettings extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Email Settings';

    protected static ?int $navigationSort = 102;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $tenant = auth('tenant')->user()->tenant;
        $existing = TenantMailSetting::query()->where('tenant_id', $tenant->id)->first();

        $this->form->fill(array_merge(
            [
                'enabled' => false, 'from_name' => $tenant->name, 'from_address' => $tenant->billing_email,
                'host' => null, 'port' => 587, 'username' => null, 'password' => null, 'encryption' => 'tls',
            ],
            $existing?->only(['enabled', 'from_name', 'from_address']) ?? [],
            $existing?->credentials ?? [],
        ));
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
            Section::make('Outgoing email (SMTP)')
                ->description('When enabled, emails to your customers and staff (invoices, portal invites, booking updates) send through your own SMTP account instead of the platform default.')
                ->schema([
                    Toggle::make('enabled')->label('Use my own SMTP settings')->live()->columnSpanFull(),
                    TextInput::make('from_name')->label('From name')->maxLength(255)
                        ->required(fn (Get $get): bool => (bool) $get('enabled')),
                    TextInput::make('from_address')->label('From address')->email()->maxLength(255)
                        ->required(fn (Get $get): bool => (bool) $get('enabled')),
                    TextInput::make('host')->label('SMTP host')->maxLength(255)
                        ->required(fn (Get $get): bool => (bool) $get('enabled')),
                    TextInput::make('port')->label('SMTP port')->numeric()->maxLength(5)
                        ->required(fn (Get $get): bool => (bool) $get('enabled')),
                    TextInput::make('username')->label('SMTP username')->maxLength(255)
                        ->required(fn (Get $get): bool => (bool) $get('enabled')),
                    TextInput::make('password')->label('SMTP password')->password()->revealable()->maxLength(255)
                        ->required(fn (Get $get): bool => (bool) $get('enabled')),
                    Select::make('encryption')->label('Encryption')->options([
                        'tls' => 'TLS',
                        'ssl' => 'SSL',
                        '' => 'None',
                    ])->default('tls'),
                ])
                ->columns(2),
        ])->statePath('data')->columns(1);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $tenant = auth('tenant')->user()->tenant;

        TenantMailSetting::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'enabled' => $data['enabled'],
                'from_name' => $data['from_name'],
                'from_address' => $data['from_address'],
                'credentials' => [
                    'host' => $data['host'],
                    'port' => $data['port'],
                    'username' => $data['username'],
                    'password' => $data['password'],
                    'encryption' => $data['encryption'],
                ],
            ],
        );

        Notification::make()->success()->title('Email settings saved')->send();
    }

    public function sendTestEmail(): void
    {
        $user = auth('tenant')->user();

        if (! TenantMailSetting::query()->where('tenant_id', $user->tenant_id)->where('enabled', true)->exists()) {
            Notification::make()->danger()->title('Save and enable your SMTP settings first')->send();

            return;
        }

        $sent = app(TenantMailer::class)->send($user->tenant_id, $user, new TestMailSettingNotification);

        if (! $sent) {
            Notification::make()->danger()->title('Test email failed to send')->body('Check your SMTP host, port, and credentials.')->send();

            return;
        }

        Notification::make()->success()->title("Test email sent to {$user->email}")->send();
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Save')->submit('save'),
            Action::make('sendTestEmail')->label('Send test email')->color('gray')->action(fn () => $this->sendTestEmail()),
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
