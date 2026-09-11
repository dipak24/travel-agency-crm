<?php

namespace App\Providers\Filament;

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
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class TenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('tenant')
            ->path('tenant')
            ->authGuard('tenant')
            ->authPasswordBroker('tenant_users')
            ->login()
            ->passwordReset()
            ->brandName(fn (): string => auth('tenant')->user()?->tenant?->name ?? config('app.name'))
            ->brandLogo(fn (): ?string => ($logo = auth('tenant')->user()?->tenant?->logo)
                ? Storage::disk('public')->url($logo)
                : null)
            ->colors([
                'primary' => Color::Amber,
                'secondary' => Color::Blue,
            ])
            ->maxContentWidth(Width::Full)
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => static::tenantBrandColorStyles())
            ->discoverResources(in: app_path('Filament/Tenant/Resources'), for: 'App\Filament\Tenant\Resources')
            ->discoverPages(in: app_path('Filament/Tenant/Pages'), for: 'App\Filament\Tenant\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Tenant/Widgets'), for: 'App\Filament\Tenant\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
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
            ], isPersistent: true);
    }

    /**
     * `Panel::colors()` closures are evaluated inside `Panel::boot()`, which
     * runs via the `SetUpPanel` middleware — before session/auth middleware
     * ever runs. `auth('tenant')->user()` is therefore always null there, so
     * a tenant-aware closure passed to `colors()` can never actually reflect
     * the logged-in tenant. A render hook is evaluated later, inside the
     * page's own render pass (after auth), so it's applied here instead —
     * as a `<style>` block that overrides the default CSS custom properties
     * `colors()` already registered.
     */
    private static function tenantBrandColorStyles(): string
    {
        $tenant = auth('tenant')->user()?->tenant;

        if (! $tenant) {
            return '';
        }

        $css = '';

        foreach (['primary' => $tenant->primary_color, 'secondary' => $tenant->secondary_color] as $name => $color) {
            if (blank($color)) {
                continue;
            }

            foreach (Color::generatePalette($color) as $shade => $value) {
                $css .= "--{$name}-{$shade}:{$value};";
            }
        }

        return $css === '' ? '' : "<style>:root{{$css}}</style>";
    }
}
