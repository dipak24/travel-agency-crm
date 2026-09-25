<?php

use App\Models\EmailTemplate;
use App\Models\EmailTemplateType;
use App\Models\PlatformEmailTemplate;
use App\Models\Tenant;
use App\Support\SystemEmailTypes;
use App\Support\TransactionalEmailTypes;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the seeder creates every system email and every tenant\'s transactional templates', function () {
    $first = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind']);
    $second = Tenant::query()->create(['name' => 'Everest Travel', 'slug' => 'everest']);

    $this->seed(EmailTemplateSeeder::class);

    expect(EmailTemplateType::query()->pluck('key')->sort()->values()->all())
        ->toBe(collect(TransactionalEmailTypes::all())->keys()->sort()->values()->all())
        ->and(PlatformEmailTemplate::query()->system()->pluck('key')->sort()->values()->all())
        ->toBe(collect(SystemEmailTypes::all())->keys()->sort()->values()->all());

    foreach ([$first, $second] as $tenant) {
        expect(EmailTemplate::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())
            ->toBe(count(TransactionalEmailTypes::all()));
    }
});

test('re-running the seeder adds nothing twice and keeps edited wording', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind']);
    $this->seed(EmailTemplateSeeder::class);

    $tenantTemplate = EmailTemplate::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
    $tenantTemplate->update(['subject' => 'Edited by the agency']);
    $systemTemplate = PlatformEmailTemplate::query()->system()->firstOrFail();
    $systemTemplate->update(['subject' => 'Edited by the Super Admin']);

    $this->seed(EmailTemplateSeeder::class);

    expect(EmailTemplate::query()->withoutGlobalScopes()->count())->toBe(count(TransactionalEmailTypes::all()))
        ->and(PlatformEmailTemplate::query()->system()->count())->toBe(count(SystemEmailTypes::all()))
        ->and($tenantTemplate->fresh()->subject)->toBe('Edited by the agency')
        ->and($systemTemplate->fresh()->subject)->toBe('Edited by the Super Admin');
});

test('the main database seeder runs the email template seeder', function () {
    $this->seed();

    expect(PlatformEmailTemplate::query()->system()->count())->toBe(count(SystemEmailTypes::all()))
        ->and(EmailTemplate::query()->withoutGlobalScopes()->count())->toBe(count(TransactionalEmailTypes::all()) * Tenant::query()->count());
});
