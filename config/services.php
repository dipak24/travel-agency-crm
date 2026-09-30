<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // PayPal and HBL credentials are tenant-scoped, not global — see
    // App\Filament\Tenant\Pages\PaymentGateways and the tenant_payment_gateways table.
    // These are only read by Database\Seeders\HblUatPaymentGatewaySeeder, to put HBL's UAT test
    // credentials back on the demo agency after `migrate:fresh --seed`. Never used at runtime.
    'hbl_uat' => [
        'office_id' => env('HBL_UAT_OFFICE_ID'),
        'api_key' => env('HBL_UAT_API_KEY'),
        'encryption_key_id' => env('HBL_UAT_ENCRYPTION_KEY_ID'),
        'merchant_signing_private_key' => env('HBL_UAT_MERCHANT_SIGNING_PRIVATE_KEY'),
        'merchant_decryption_private_key' => env('HBL_UAT_MERCHANT_DECRYPTION_PRIVATE_KEY'),
        'paco_encryption_public_key' => env('HBL_UAT_PACO_ENCRYPTION_PUBLIC_KEY'),
        'paco_signing_public_key' => env('HBL_UAT_PACO_SIGNING_PUBLIC_KEY'),
    ],

];
