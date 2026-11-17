<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | e-Factura Environment
    |--------------------------------------------------------------------------
    |
    | Set to true to use the ANAF sandbox/test environment.
    | Set to false (or 0) for production. A blank or any other value keeps the sandbox.
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
    | A blank or non-integer retry value uses the default. retry_times below 1
    | counts as 1, and a negative retry_delay counts as 0.
    |
    | retry_times: on an e-Factura call, a retry after an attempt that may have
    | reached ANAF spends one unit of every limit the call draws on (global plus
    | per-message or per-CUI); a retry after a provably unsent attempt spends
    | nothing. A company lookup retry always spends one lookup unit. AnafAuthenticator
    | uses the setting only to allow one immediate extra token request (when it
    | is above 1) after a request that provably never left.
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
    | A blank or false channel logs to your default channel.
    |
    */
    'logging' => [
        'channel' => env('EFACTURA_LOG_CHANNEL', 'efactura-sdk'),
        'debug' => env('EFACTURA_DEBUG', false),
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
            // ANAF also serves it with OAuth at https://api.anaf.ro/api/validate/signature.
            'verify_signature' => 'https://webservicesp.anaf.ro/api/validate/signature',
        ],
        'company_lookup' => 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting to prevent exceeding ANAF API quotas.
    | Defaults are set to 50% of ANAF's actual limits for safety margin, with one
    | exception: company_lookup_per_second is 1, which is 100% of ANAF's cap. A
    | 50% margin cannot be expressed as an integer limit over a 1-second window.
    | status_per_day_message_provider is ANAF's full status limit on purpose: only
    | reads that opt in with useProviderLimit: true are checked against it.
    |
    | Each window is fixed: it starts at the first counted call and does not slide
    | (60 s global, 24 h per day, 2 s company lookup). Calls at the end of one window
    | and the start of the next can add up to twice a limit, so the 50% defaults are
    | the highest values that keep that worst case within ANAF's figures.
    | status_per_day_message_provider at ANAF's full figure can reach 200 reads
    | across a boundary.
    |
    | Only false or 0 turns limiting off. A blank or non-integer limit uses the
    | default. 0 or a negative limit is refused when the client is built.
    |
    | ANAF Official Limits:
    | - Global: 1000 calls/minute
    | - RASP upload: 1000/day/CUI
    | - Status queries: 100/day/message
    | - Simple list: 1500/day/CUI
    | - Paginated list: 100,000/day/CUI
    | - Downloads: 10/day/message
    | - Company lookup: 1 request/second (not halved - see above)
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

        // ANAF's own status ceiling, checked on the same per-message counter only by
        // reads that pass useProviderLimit: true (e.g. a check a user asked for).
        'status_per_day_message_provider' => env('EFACTURA_RATE_LIMIT_STATUS_PROVIDER', 100),

        // Simple list queries per CUI per day (ANAF limit: 1500)
        'simple_list_per_day_cui' => env('EFACTURA_RATE_LIMIT_SIMPLE_LIST', 750),

        // Paginated list queries per CUI per day (ANAF limit: 100,000)
        'paginated_list_per_day_cui' => env('EFACTURA_RATE_LIMIT_PAGINATED_LIST', 50000),

        // Downloads per message ID per day (ANAF limit: 10)
        'download_per_day_message' => env('EFACTURA_RATE_LIMIT_DOWNLOAD', 5),

        // Company details lookup (PlatitorTvaRest) — ANAF limit: 1 request/second.
        // This is the one default that is NOT halved: 1 is already ANAF's own cap,
        // and any lower integer would disable lookups entirely. It is counted over a
        // 2-second window so cache stores that expire keys at whole seconds
        // (database, file, memcached) still keep lookups at least 1 s apart (on Redis, 2 s). A value N
        // above 1 therefore allows N lookups per 2 s, not per second.
        'company_lookup_per_second' => env('EFACTURA_RATE_LIMIT_COMPANY_LOOKUP', 1),
    ],
];
