<?php

use App\Models\Tenant;
use App\Support\AgencySubdomain;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * An absolute URL on the agency's own portal subdomain — the customer portal only answers there
 * (App\Http\Middleware\ResolveAgencySubdomain).
 */
function portalUrl(Tenant $tenant, string $path = '/portal'): string
{
    return AgencySubdomain::url($tenant, $path);
}

/**
 * A named route's URL on the agency's portal subdomain.
 *
 * @param  array<string, mixed>  $parameters
 */
function portalRoute(Tenant $tenant, string $name, array $parameters = []): string
{
    return AgencySubdomain::within($tenant, fn (): string => route($name, $parameters));
}
