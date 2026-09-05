<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Concerns\HasFullWidthForm;
use App\Filament\Resources\TenantResource;
use App\Models\SubscriptionPlan;
use App\Services\TenantOnboarding;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenant extends CreateRecord
{
    use HasFullWidthForm;

    protected static string $resource = TenantResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $plan = SubscriptionPlan::query()->findOrFail($data['plan_id']);

        return app(TenantOnboarding::class)->create(
            [
                'name' => $data['name'],
                'slug' => $data['slug'],
                'status' => $data['status'],
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
                'billing_email' => $data['billing_email'] ?? null,
                'address' => $data['address'] ?? null,
                'phone_number' => $data['phone_number'] ?? null,
                'mobile_number' => $data['mobile_number'] ?? null,
                'timezone' => $data['timezone'],
                'currency' => $data['currency'],
                'logo' => $data['logo'] ?? null,
                'primary_color' => $data['primary_color'] ?? null,
                'secondary_color' => $data['secondary_color'] ?? null,
            ],
            [
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['owner_password'],
            ],
            $plan,
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
