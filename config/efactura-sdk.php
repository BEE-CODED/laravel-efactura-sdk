<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | e-Factura Environment
    |--------------------------------------------------------------------------
    |
    | Set to true to use the ANAF sandbox/test environment.
    | Set to false for production.
    |
    */
    'sandbox' => env('EFACTURA_SANDBOX', true),

    /*
    |--------------------------------------------------------------------------
    | OAuth2 Credentials
    |--------------------------------------------------------------------------
    |
    | These credentials are obtained from ANAF's OAuth2 system.
    | Visit: https://www.anaf.ro/CompensareFacturi/ to register your application.
    |
    */
    'oauth' => [
        'client_id' => env('EFACTURA_CLIENT_ID'),
        'client_secret' => env('EFACTURA_CLIENT_SECRET'),
        'redirect_uri' => env('EFACTURA_REDIRECT_URI'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the HTTP client used to communicate with ANAF.
    |
    */
    'http' => [
        'timeout' => env('EFACTURA_TIMEOUT', 30),
        'retry_times' => env('EFACTURA_RETRY_TIMES', 3),
        'retry_delay' => env('EFACTURA_RETRY_DELAY', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the logging channel for API calls.
    | You should add this channel to your config/logging.php:
    |
    | 'efactura-sdk' => [
    |     'driver' => 'daily',
    |     'path' => storage_path('logs/efactura-sdk.log'),
    |     'level' => 'debug',
    |     'days' => 30,
    | ],
    |
    */
    'logging' => [
        'channel' => env('EFACTURA_LOG_CHANNEL', 'efactura-sdk'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Endpoints
    |--------------------------------------------------------------------------
    |
    | Base URLs for ANAF e-Factura API endpoints.
    | These should not need to be changed unless ANAF updates their API.
    |
    */
    'endpoints' => [
        'api' => [
            'test' => 'https://api.anaf.ro/test/FCTEL/rest',
            'production' => 'https://api.anaf.ro/prod/FCTEL/rest',
        ],
        'oauth' => [
            'authorize' => 'https://logincert.anaf.ro/anaf-oauth2/v1/authorize',
            'token' => 'https://logincert.anaf.ro/anaf-oauth2/v1/token',
        ],
        'services' => [
            'validate' => 'https://webservicesp.anaf.ro/prod/FCTEL/rest/validare',
            'transform' => 'https://webservicesp.anaf.ro/prod/FCTEL/rest/transformare',
            'verify_signature' => 'https://webservicesp.anaf.ro/prod/FCTEL/rest/verificare-semnatura',
        ],
        'company_lookup' => 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting to prevent exceeding ANAF API quotas.
    | All defaults are set to 50% of ANAF's actual limits for safety margin.
    |
    | ANAF Official Limits:
    | - Global: 1000 calls/minute
    | - RASP upload: 1000/day/CUI
    | - Status queries: 100/day/message
    | - Simple list: 1500/day/CUI
    | - Paginated list: 100,000/day/CUI
    | - Downloads: 10/day/message
    |
    */
    'rate_limits' => [
        'enabled' => env('EFACTURA_RATE_LIMIT_ENABLED', true),

        // Global limit: 500/minute (ANAF limit: 1000)
        'global_per_minute' => env('EFACTURA_RATE_LIMIT_GLOBAL', 500),

        // RASP file uploads per CUI per day (ANAF limit: 1000)
        'rasp_upload_per_day_cui' => env('EFACTURA_RATE_LIMIT_RASP_UPLOAD', 500),

        // Status queries per message ID per day (ANAF limit: 100)
        'status_per_day_message' => env('EFACTURA_RATE_LIMIT_STATUS', 50),

        // Simple list queries per CUI per day (ANAF limit: 1500)
        'simple_list_per_day_cui' => env('EFACTURA_RATE_LIMIT_SIMPLE_LIST', 750),

        // Paginated list queries per CUI per day (ANAF limit: 100,000)
        'paginated_list_per_day_cui' => env('EFACTURA_RATE_LIMIT_PAGINATED_LIST', 50000),

        // Downloads per message ID per day (ANAF limit: 10)
        'download_per_day_message' => env('EFACTURA_RATE_LIMIT_DOWNLOAD', 5),
    ],
];
