<?php

namespace App\Http\Resources\V1;

use App\Models\PublicLeadPage;
use App\Support\AgencySubdomain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * What a public website front end needs to render an agency's site: branding (managed by the
 * platform team), the agency's chosen theme, and its contact details. A custom domain is only
 * reported once verified.
 *
 * @mixin PublicLeadPage
 */
class SiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenant = $this->tenant;

        return [
            'name' => $tenant->name,
            'currency' => $tenant->currency,
            'url' => $this->hasVerifiedDomain() ? "https://{$this->custom_domain}" : AgencySubdomain::rootFor($tenant),
            'custom_domain' => $this->hasVerifiedDomain() ? $this->custom_domain : null,
            'branding' => [
                'logo_url' => filled($tenant->logo) ? Storage::disk('public')->url($tenant->logo) : null,
                'favicon_url' => filled($tenant->favicon) ? Storage::disk('public')->url($tenant->favicon) : null,
                'primary_color' => $tenant->primary_color,
                'secondary_color' => $tenant->secondary_color,
            ],
            'theme' => [
                'template' => $this->theme['template'] ?? 'classic',
                'accent_color' => $this->theme['accent_color'] ?? $tenant->primary_color,
                'hero_title' => $this->theme['hero_title'] ?? null,
                'hero_subtitle' => $this->theme['hero_subtitle'] ?? null,
            ],
            'contact' => [
                'email' => $this->contact_settings['email'] ?? null,
                'phone' => $this->contact_settings['phone'] ?? null,
                'whatsapp' => $this->contact_settings['whatsapp'] ?? null,
                'address' => $this->contact_settings['address'] ?? null,
                'facebook_url' => $this->contact_settings['facebook_url'] ?? null,
                'instagram_url' => $this->contact_settings['instagram_url'] ?? null,
            ],
        ];
    }
}
