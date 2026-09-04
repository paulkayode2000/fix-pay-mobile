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

    // ── FixPay custom providers ─────────────────────────────────────────────

    'kyc' => [
        'provider' => env('KYC_PROVIDER', 'mock'),
    ],

    'aml' => [
        'provider' => env('AML_PROVIDER', 'mock'),
    ],

    'youverify' => [
        'api_key' => env('YOUVERIFY_API_KEY', ''),
        'base_url' => env('YOUVERIFY_BASE_URL', 'https://api.youverify.co/v2'),
    ],

    'smileid' => [
        'api_key' => env('SMILEID_API_KEY', ''),
        'partner_id' => env('SMILEID_PARTNER_ID', ''),
        'base_url' => env('SMILEID_BASE_URL', 'https://testapi.smileidentity.com/v1'),
    ],

    'providus' => [
        'client_id' => env('PROVIDUS_CLIENT_ID', ''),
        'client_secret' => env('PROVIDUS_CLIENT_SECRET', ''),
        'base_url' => env('PROVIDUS_BASE_URL', 'https://api.providusbank.com'),
        'mock' => env('PROVIDUS_MOCK', true),
    ],

    'vtpass' => [
        'api_key' => env('VTPASS_API_KEY', ''),
        'secret_key' => env('VTPASS_SECRET_KEY', ''),
        'public_key' => env('VTPASS_PUBLIC_KEY', ''),
        'base_url' => env('VTPASS_BASE_URL', 'https://sandbox.vtpass.com/api'),
    ],

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY', ''),
        'public_key' => env('PAYSTACK_PUBLIC_KEY', ''),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    ],

    'gateway' => [
        'base_url'    => env('PAYFIXY_GATEWAY_BASE_URL', 'http://localhost:8999'),
        'api_key'     => env('PAYFIXY_GATEWAY_API_KEY', ''),
        'secret_key'  => env('PAYFIXY_GATEWAY_SECRET_KEY', ''),
        'business_id' => env('PAYFIXY_GATEWAY_BUSINESS_ID', ''),
        'enabled'     => env('PAYFIXY_GATEWAY_ENABLED', false),
        // Directory scanned for hot-loadable processor plugins (JAR/ZIP).
        'plugins_dir' => env('PAYFIXY_PLUGINS_DIR', base_path('plugins')),
    ],

    // ── TMS AML / Antifraud integration ───────────────────────────────────────
    // TMS is the external AML/antifraud platform (aml-system + antifraud-service).
    // fixpay fires asynchronous checks at TMS and tags transactions/users with the
    // returned scores. See App\Services\Tms\AmlClient / AntifraudClient.

    'tms' => [
        'enabled'        => env('TMS_ENABLED', false),
        'base_url'       => env('TMS_BASE_URL', 'http://aml.127.0.0.1.nip.io'),        // aml-system (Laravel) via the TMS hostname router
        'api_token'      => env('TMS_API_TOKEN', ''),                          // AuthenticateApiClient token
        'webhook_secret' => env('TMS_WEBHOOK_SECRET', ''),                     // HMAC secret for TMS webhooks
        'timeout'        => (int) env('TMS_TIMEOUT', 15),
    ],

    'antifraud' => [
        'enabled'  => env('TMS_ANTIFRAUD_ENABLED', false),
        'base_url' => env('TMS_ANTIFRAUD_URL', 'http://antifraud.127.0.0.1.nip.io'),  // antifraud-service (FastAPI) via the TMS hostname router
        'api_key'  => env('TMS_ANTIFRAUD_API_KEY', ''),                        // X-API-Key (empty = dev mode)
        'timeout'  => (int) env('TMS_ANTIFRAUD_TIMEOUT', 15),
        // TTL for the in-memory cache of the TMS-published ruleset (X-Rules-Version).
        'rules_cache_ttl' => (int) env('TMS_ANTIFRAUD_RULES_CACHE_TTL', 60),
    ],

    'ninepsb' => [
        'base_url' => env('9PSB_BASE_URL', 'http://102.216.128.75:9090/waas'),
        'username' => env('9PSB_USERNAME', 'payfixy'),
        'password' => env('9PSB_PASSWORD', ''),
        'client_id' => env('9PSB_CLIENT_ID', 'waas'),
        'client_secret' => env('9PSB_CLIENT_SECRET', ''),
        'webhook_username' => env('9PSB_WEBHOOK_USERNAME', ''),
        'webhook_password' => env('9PSB_WEBHOOK_PASSWORD', ''),
    ],

];
