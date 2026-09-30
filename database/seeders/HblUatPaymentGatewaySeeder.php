<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Puts HBL's UAT (test) card gateway back on the demo agency after `migrate:fresh --seed`, so card
 * payments can be tested with HBL's test cards without re-entering the credentials. The keys are
 * read from the HBL_UAT_* entries in .env (config/services.php `hbl_uat`) — never committed — and
 * the seeder does nothing until they are filled in, and never on production.
 */
class HblUatPaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {
        $credentials = (array) config('services.hbl_uat');

        if (app()->isProduction() || collect($credentials)->contains(fn (mixed $value): bool => blank($value))) {
            $this->command?->warn('HBL UAT gateway not seeded: fill in every HBL_UAT_* value in .env first.');

            return;
        }

        $tenant = Tenant::query()->where('slug', 'demo-travel')->first();

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->wrap($tenant, fn (): TenantPaymentGateway => TenantPaymentGateway::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'gateway' => 'hbl'],
            ['enabled' => true, 'credentials' => ['mode' => 'uat', ...$credentials]],
        ));
    }
}
