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
    | Default CIF (Fiscal Identification Code)
    |--------------------------------------------------------------------------
    |
    | The default CIF to use when none is specified.
    | This should be your company's CIF without the 'RO' prefix.
    |
    */
    'cif' => env('EFACTURA_CIF'),

    /*
    |--------------------------------------------------------------------------
    | Token Table
    |--------------------------------------------------------------------------
    |
    | The database table used to store OAuth tokens.
    | Tokens are automatically encrypted using Laravel's encryption.
    |
    */
    'tokens_table' => env('EFACTURA_TOKENS_TABLE', 'efactura_tokens'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the HTTP client used to communicate with ANAF.
    |
    */
    'http' => [
        'timeout' => env('EFACTURA_HTTP_TIMEOUT', 30),
        'connect_timeout' => env('EFACTURA_HTTP_CONNECT_TIMEOUT', 10),
        'retry' => [
            'times' => env('EFACTURA_HTTP_RETRY_TIMES', 3),
            'sleep' => env('EFACTURA_HTTP_RETRY_SLEEP', 100),
        ],
    ],
];
