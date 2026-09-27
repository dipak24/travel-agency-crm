<?php

namespace App\Services;

use App\Models\PublicLeadPage;
use App\Services\Dns\TxtRecordLookup;

/**
 * Proves an agency controls the custom domain it entered: the domain must publish the page's
 * verification token as a TXT record at _travelcrm-verification.{domain}. Until that succeeds the
 * domain is stored but never served (ResolvePublicTenant only matches verified domains).
 */
class CustomDomainVerifier
{
    public function __construct(private TxtRecordLookup $dns) {}

    public function verify(PublicLeadPage $page): bool
    {
        $expected = $page->verificationRecordValue();

        if ($expected === null) {
            return false;
        }

        $found = in_array($expected, array_map('trim', $this->dns->lookup($page->verificationRecordName())), true);

        if ($found) {
            $page->forceFill(['domain_verified_at' => now()])->save();
        }

        return $found;
    }
}
