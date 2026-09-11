<?php

namespace App\Console\Commands;

use App\Models\GiftVoucher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:expire-gift-vouchers')]
#[Description('Mark unredeemed/partially-redeemed gift vouchers past their expires_at date as expired')]
class ExpireGiftVouchers extends Command
{
    /**
     * Runs across every tenant — `withoutGlobalScopes()` bypasses `BelongsToTenant`'s
     * `TenantScope`, which otherwise scopes to `tenant_id = 0` (matching nothing) when no
     * `TenantContext` is set, as is always the case for a scheduled console command.
     */
    public function handle(): int
    {
        $count = GiftVoucher::query()
            ->withoutGlobalScopes()
            ->whereIn('status', ['unredeemed', 'partially_redeemed'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        $this->info("Expired {$count} gift voucher(s).");

        return self::SUCCESS;
    }
}
