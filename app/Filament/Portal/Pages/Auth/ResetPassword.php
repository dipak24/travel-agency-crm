<?php

namespace App\Filament\Portal\Pages\Auth;

use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Livewire\Attributes\Locked;
use SensitiveParameter;

/**
 * Customer emails are only unique per tenant, so an email alone can match customers of several
 * agencies — Filament's stock page would reset whichever one the database returns first. Every
 * customer reset link therefore carries `tenant` (added by AccountSetupLinks / PasswordResetRequested
 * via getResetPasswordUrl()'s extra parameters). The route is `signed`, so the value can't be
 * altered, and it is added to the credentials so the broker resolves exactly that tenant's customer.
 */
class ResetPassword extends BaseResetPassword
{
    #[Locked]
    public ?int $tenantId = null;

    public function mount(?string $email = null, #[SensitiveParameter] ?string $token = null): void
    {
        parent::mount($email, $token);

        $this->tenantId = request()->integer('tenant') ?: null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        if ($this->tenantId !== null) {
            $data['tenant_id'] = $this->tenantId;
        }

        return $data;
    }
}
