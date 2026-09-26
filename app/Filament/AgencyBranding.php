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
            ->favicon(fn (): ?string => static::publicUrl(static::agency()?->favicon))
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => static::colorStyles());
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
