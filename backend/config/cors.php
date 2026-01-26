<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CORS Paths
    |--------------------------------------------------------------------------
    |
    | These patterns are matched against the request path. For matched paths,
    | CORS headers will be added to the response.
    |
    */
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Methods
    |--------------------------------------------------------------------------
    */
    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | IMPORTANT: When supports_credentials=true, wildcard origins (*) are
    | forbidden by browsers. Origins must be explicitly listed or matched
    | via patterns.
    |
    */
    'allowed_origins' => array_filter(array_map('trim', explode(
        separator: ',',
        string: env(
            key: 'CORS_ALLOWED_ORIGINS',
            default: 'http://localhost:3000,http://127.0.0.1:3000,https://skillhub.pro',
        ),
    ))),


    /*
    |--------------------------------------------------------------------------
    | Allowed Headers
    |--------------------------------------------------------------------------
    */
    'allowed_headers' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Exposed Headers
    |--------------------------------------------------------------------------
    */
    'exposed_headers' => [],

    /*
    |--------------------------------------------------------------------------
    | Max Age
    |--------------------------------------------------------------------------
    */
    'max_age' => 0,

    /*
    |--------------------------------------------------------------------------
    | Supports Credentials
    |--------------------------------------------------------------------------
    */
    'supports_credentials' => true,
];

