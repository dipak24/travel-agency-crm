<?php

namespace App\Filament\Tenant\Pages;

use App\Models\PublicLeadPage;
use App\Services\CustomDomainVerifier;
use App\Support\AgencySubdomain;
use App\Support\TenantContext;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Spatie\Permission\Models\Permission;

/**
 * The agency's public website (served by the public API to whatever front end renders it): theme,
 * contact details, publish switch, and an optional custom domain that only goes live after its
 * DNS TXT record is verified. Logo, name and brand colours come from the agency's branding, which
 * the platform team manages.
 */
class WebsiteSettings extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Website';

    protected static ?string $title = 'Website';

    protected static ?string $slug = 'website';

    protected static ?int $navigationSort = 102;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $page = $this->websitePage();

        $this->form->fill([
            'is_active' => $page?->is_active ?? false,
            'theme' => array_merge(['template' => 'classic', 'accent_color' => null, 'hero_title' => null, 'hero_subtitle' => null], $page?->theme ?? []),
            'contact_settings' => array_merge(
                ['email' => null, 'phone' => null, 'whatsapp' => null, 'address' => null, 'facebook_url' => null, 'instagram_url' => null],
                $page?->contact_settings ?? [],
            ),
            'custom_domain' => $page?->custom_domain,
        ]);
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
            Section::make('Publishing')
                ->schema([
                    Toggle::make('is_active')
                        ->label('Website published')
                        ->helperText('While unpublished, the public API returns no site details for your agency.'),
                    Placeholder::make('default_address')
                        ->label('Your website address')
                        ->content(fn (): string => AgencySubdomain::rootFor(auth('tenant')->user()->tenant)),
                ])
                ->columns(2),
            Section::make('Theme')
                ->schema([
                    Select::make('theme.template')->label('Theme')->options(PublicLeadPage::THEMES)->required(),
                    ColorPicker::make('theme.accent_color')->label('Accent colour')
                        ->regex('/^#[0-9a-fA-F]{6}$/')
                        ->helperText('Optional — defaults to your brand colour.'),
                    TextInput::make('theme.hero_title')->label('Headline')->maxLength(120),
                    TextInput::make('theme.hero_subtitle')->label('Tagline')->maxLength(255),
                ])
                ->columns(2),
            Section::make('Contact details')
                ->description('Shown on your website\'s contact section and footer.')
                ->schema([
                    TextInput::make('contact_settings.email')->label('Email')->email()->maxLength(255),
                    TextInput::make('contact_settings.phone')->label('Phone')->tel()->maxLength(50),
                    TextInput::make('contact_settings.whatsapp')->label('WhatsApp')->tel()->maxLength(50),
                    TextInput::make('contact_settings.address')->label('Address')->maxLength(255),
                    TextInput::make('contact_settings.facebook_url')->label('Facebook page')->url()->maxLength(255),
                    TextInput::make('contact_settings.instagram_url')->label('Instagram profile')->url()->maxLength(255),
                ])
                ->columns(2),
            Section::make('Custom domain')
                ->description('Optional. Use your own domain (for example trips.youragency.com) instead of your agency address. It goes live only after you prove you own it with a DNS TXT record.')
                ->schema([
                    TextInput::make('custom_domain')
                        ->label('Domain')
                        ->placeholder('trips.youragency.com')
                        ->maxLength(253)
                        ->rules([
                            'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',
                            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                $platformDomain = Str::lower((string) config('agency.domain'));
                                $domain = Str::lower((string) $value);

                                if ($domain === $platformDomain || Str::endsWith($domain, '.'.$platformDomain)) {
                                    $fail('Use your own domain here — your agency address already works without one.');
                                }
                            },
                            fn (): Unique => Rule::unique('public_lead_pages', 'custom_domain')->ignore($this->websitePage()?->getKey()),
                        ])
                        ->validationMessages([
                            'regex' => 'Enter just the domain, like trips.youragency.com — without https or a path.',
                            'unique' => 'This domain is already connected to another agency.',
                        ]),
                    Placeholder::make('domain_status')
                        ->label('Status')
                        ->content(fn (): HtmlString => $this->domainStatus()),
                ]),
        ])->statePath('data')->columns(1);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $page = $this->websitePage() ?? new PublicLeadPage;
        $page->fill([
            'is_active' => (bool) $data['is_active'],
            'theme' => $data['theme'],
            'contact_settings' => $data['contact_settings'],
            'custom_domain' => filled($data['custom_domain']) ? $data['custom_domain'] : null,
        ]);
        $page->save();

        Notification::make()->success()->title('Website settings saved')->send();
    }

    public function verifyDomain(): void
    {
        $page = $this->websitePage();

        if ($page?->custom_domain === null) {
            Notification::make()->warning()->title('Save a custom domain first')->send();

            return;
        }

        if (! app(CustomDomainVerifier::class)->verify($page)) {
            Notification::make()->danger()
                ->title('TXT record not found yet')
                ->body('DNS changes can take up to a few hours to appear. Check the record name and value, then try again.')
                ->send();

            return;
        }

        Notification::make()->success()->title("{$page->custom_domain} is verified")->send();
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Save')->submit('save'),
            Action::make('verifyDomain')
                ->label('Verify domain')
                ->color('gray')
                ->visible(fn (): bool => $this->websitePage()?->custom_domain !== null && ! $this->websitePage()->hasVerifiedDomain())
                ->action(fn () => $this->verifyDomain()),
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

    private function websitePage(): ?PublicLeadPage
    {
        return PublicLeadPage::query()->first();
    }

    private function domainStatus(): HtmlString
    {
        $page = $this->websitePage();

        if ($page?->custom_domain === null) {
            return new HtmlString('No custom domain.');
        }

        $domain = e($page->custom_domain);

        if ($page->hasVerifiedDomain()) {
            return new HtmlString("<strong>{$domain}</strong> is verified and live. Point it at the platform with a CNAME record to ".e((string) config('agency.domain')).'.');
        }

        return new HtmlString(
            "<strong>{$domain}</strong> is waiting for verification. Add this DNS record at your domain provider, then click <em>Verify domain</em>:"
            .'<br>Type: <code>TXT</code>'
            .'<br>Name: <code>'.e($page->verificationRecordName()).'</code>'
            .'<br>Value: <code>'.e($page->verificationRecordValue()).'</code>'
        );
    }
}
