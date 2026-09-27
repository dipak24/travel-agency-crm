<?php

namespace App\Filament;

use App\Models\Tenant;
use App\Support\AgencySubdomain;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Storage;

/**
 * Brands a panel (staff /tenant and customer /portal) as the agency whose subdomain it is served
 * on: its name, logo, favicon and primary/secondary colours, all set by the Super Admin on the
 * tenant. Because the agency comes from the subdomain, the login and password pages are branded
 * too — before anyone signs in. Falls back to the signed-in user's agency (e.g. in component tests
 * that don't run the subdomain middleware) and then to the platform's own name.
 */
class AgencyBranding
{
    public static function apply(Panel $panel): Panel
    {
        return $panel
            ->brandName(fn (): string => static::agency()?->name ?? config('app.name'))
            ->brandLogo(fn (): ?string => static::publicUrl(static::agency()?->logo))
            // 'auto' instead of Filament's fixed 1.5rem: the logo keeps its own size (see logoStyles()).
            ->brandLogoHeight('auto')
            ->favicon(fn (): ?string => static::publicUrl(static::agency()?->favicon))
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => static::logoStyles().static::headingStyles().static::colorStyles());
    }

    /**
     * One page-heading size on every screen: Filament's default grows the heading from 1.5rem/700
     * to 1.875rem on wider screens, which made page titles look inconsistent next to each other.
     */
    private static function headingStyles(): string
    {
        return '<style>'
            .'.fi-header-heading{font-size:1.5rem;line-height:2rem;font-weight:600}'
            .'</style>';
    }

    /**
     * The logo is shown at its own natural width and height — never cropped, stretched or boxed
     * into a fixed size — so horizontal and vertical logos both keep their real shape. It only
     * shrinks (proportionally) if it's wider than the space it's in. Without a logo, Filament
     * shows the business name instead, kept to one line in the header.
     */
    private static function logoStyles(): string
    {
        return '<style>'
            .'img.fi-logo{width:auto;height:auto;max-width:100%}'
            .'.fi-sidebar-header div.fi-logo,.fi-topbar div.fi-logo{display:block;max-width:14rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            .'.fi-simple-header div.fi-logo{text-align:center}'
            .'</style>';
    }

    public static function agency(): ?Tenant
    {
        return app(AgencySubdomain::class)->get()
            ?? auth('tenant')->user()?->tenant
            ?? auth('customer')->user()?->tenant;
    }

    private static function publicUrl(?string $path): ?string
    {
        return filled($path) ? Storage::disk('public')->url($path) : null;
    }

    /**
     * `Panel::colors()` closures are evaluated inside `Panel::boot()`, which runs via the
     * `SetUpPanel` middleware — before the subdomain/auth middleware has run, so an agency-aware
     * closure there could never see the agency. A render hook is evaluated later, in the page's own
     * render pass, so the brand colours are applied here instead — as a `<style>` block overriding
     * the CSS custom properties `colors()` already registered. Colour values are validated hex
     * codes from ColorPicker, and generatePalette() only emits colour values.
     */
    private static function colorStyles(): string
    {
        $agency = static::agency();

        if ($agency === null) {
            return '';
        }

        $css = '';

        foreach (['primary' => $agency->primary_color, 'secondary' => $agency->secondary_color] as $name => $color) {
            if (blank($color) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                continue;
            }

            foreach (Color::generatePalette($color) as $shade => $value) {
                $css .= "--{$name}-{$shade}:{$value};";
            }
        }

        return $css === '' ? '' : "<style>:root{{$css}}</style>";
    }
}
