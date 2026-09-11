<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('architecture business tables are present', function () {
    $tables = [
        'leads',
        'bookings',
        'booking_travelers',
        'booking_documents',
        'booking_addons',
        'booking_include_exclude',
        'services',
        'promo_codes',
        'gift_vouchers',
        'booking_waitlist',
        'reminders',
        'public_lead_pages',
        'invoices',
        'invoice_items',
        'payments',
        'email_template_types',
        'email_templates',
        'platform_email_templates',
        'saas_leads',
        'email_campaigns',
        'email_campaign_recipients',
        'email_unsubscribes',
        'notifications',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing table: {$table}");
    }
});

test('tenant owned architecture tables require tenant ownership', function () {
    $tables = [
        'leads',
        'bookings',
        'booking_travelers',
        'booking_documents',
        'booking_addons',
        'booking_include_exclude',
        'services',
        'promo_codes',
        'gift_vouchers',
        'booking_waitlist',
        'reminders',
        'public_lead_pages',
        'invoices',
        'invoice_items',
        'payments',
        'email_templates',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasColumn($table, 'tenant_id'))->toBeTrue("Missing tenant_id: {$table}");
    }
});
