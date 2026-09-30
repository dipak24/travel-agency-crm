<?php

namespace App\Support;

use App\Models\FixedDeparture;
use App\Models\Package;
use App\Models\Tenant;

/**
 * The links and embed code staff copy onto their own marketing site: a booking link per package
 * (own dates) and per fixed departure, and the departures widget snippet. Built on the agency's
 * subdomain, since that is where the public booking pages answer.
 */
class PublicBookingLinks
{
    public static function package(Tenant $tenant, Package $package): string
    {
        return AgencySubdomain::within($tenant, fn (): string => route('public.book.show', $package->package_code));
    }

    public static function departure(Tenant $tenant, FixedDeparture $departure): string
    {
        return AgencySubdomain::within($tenant, fn (): string => route('public.departures.show', $departure->getKey()));
    }

    public static function widgetSnippet(Tenant $tenant, ?string $packageCode = null): string
    {
        $package = $packageCode === null ? '' : ' data-package="'.e($packageCode).'"';

        return '<div data-crm-departures'.$package.'></div>'."\n"
            .'<script src="'.e(AgencySubdomain::url($tenant, '/js/departures-widget.js')).'" async></script>';
    }
}
