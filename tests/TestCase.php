<?php

namespace Tests;

use App\Models\TenantUser;
use App\Support\AgencySubdomain;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\URL;

abstract class TestCase extends BaseTestCase
{
    /**
     * Acts as a staff member on their agency's own subdomain: the staff panel only answers there
     * (App\Http\Middleware\ResolveAgencySubdomain), so relative requests such as
     * `->get('/tenant/bookings')` are rooted at that subdomain for the rest of the test.
     */
    public function actingAsStaff(TenantUser $staff): static
    {
        URL::useOrigin(AgencySubdomain::rootFor($staff->tenant));

        return $this->actingAs($staff, 'tenant');
    }
}
