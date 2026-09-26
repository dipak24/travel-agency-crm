<?php

namespace App\Providers\Filament;

use App\Filament\AgencyBranding;
use App\Http\Middleware\ResolveAgencySubdomain;
use App\Http\Middleware\ResolveTenant;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The agency staff panel, served only on the agency's own subdomain ({slug}.{agency.domain}/tenant)
 * with the agency's branding — see ResolveAgencySubdomain and AgencyBranding.
 */
class TenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return AgencyBranding::apply($panel
            ->id('tenant')
            ->path('tenant')
            ->authGuard('tenant')
            ->authPasswordBroker('tenant_users')
            ->login()
            ->passwordReset()
            ->colors([
                'primary' => Color::Amber,
                'secondary' => Color::Blue,
            ])
            ->maxContentWidth(Width::Full)
            ->discoverResources(in: app_path('Filament/Tenant/Resources'), for: 'App\Filament\Tenant\Resources')
            ->discoverPages(in: app_path('Filament/Tenant/Pages'), for: 'App\Filament\Tenant\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Tenant/Widgets'), for: 'App\Filament\Tenant\Widgets')
            ->middleware([
                ResolveAgencySubdomain::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ResolveTenant::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                ResolveTenant::class,
            ], isPersistent: true)
            ->persistentMiddleware([ResolveAgencySubdomain::class]));
    }
}
