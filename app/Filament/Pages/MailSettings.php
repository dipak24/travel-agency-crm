<?php

namespace App\Filament\Pages;

use App\Models\PlatformMailSetting;
use App\Notifications\TestMailSettingNotification;
use App\Services\Mail\TenantMailer;
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
use Spatie\Permission\PermissionRegistrar;

class MailSettings extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Email Settings';

    protected static ?int $navigationSort = 100;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $existing = PlatformMailSetting::query()->first();

        $this->form->fill(array_merge(
            [
                'enabled' => false, 'from_name' => config('app.name'), 'from_address' => null,
                'host' => null, 'port' => 587, 'username' => null, 'password' => null, 'encryption' => 'tls',
            ],
            $existing?->only(['enabled', 'from_name', 'from_address']) ?? [],
            $existing?->credentials ?? [],
        ));
    }

    public static function canAccess(): bool
    {
        $user = auth('super_admin')->user();

        if (! $user) {
            return false;
        }

        // Super admins aren't tenant-scoped, so they use the fixed sentinel
        // team id (0) instead of a real tenant id — see ResolvePlatformTeam.
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        if (! Permission::query()->where('name', 'manage platform')->where('guard_name', 'super_admin')->exists()) {
            return $user->hasRole('Super Admin');
        }

        return $user->hasPermissionTo('manage platform');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Platform outgoing email (SMTP)')
                ->description('Used for platform-level email, and as the default for any tenant that has not enabled its own SMTP settings. Entirely separate from each tenant\'s own settings.')
                ->schema([
                    Toggle::make('enabled')->label('Enabled')->live()->columnSpanFull(),
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

        $settings = PlatformMailSetting::query()->first() ?? new PlatformMailSetting;
        $settings->fill([
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
        ]);
        $settings->save();

        Notification::make()->success()->title('Email settings saved')->send();
    }

    public function sendTestEmail(): void
    {
        $user = auth('super_admin')->user();

        if (! PlatformMailSetting::query()->where('enabled', true)->exists()) {
            Notification::make()->danger()->title('Save and enable your SMTP settings first')->send();

            return;
        }

        $sent = app(TenantMailer::class)->send(null, $user, new TestMailSettingNotification);

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
