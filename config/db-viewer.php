<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Viewer Server
    |--------------------------------------------------------------------------
    */
    'host' => env('DB_VIEWER_HOST', '127.0.0.1'),
    'port' => (int) env('DB_VIEWER_PORT', 7331),

    /*
    |--------------------------------------------------------------------------
    | Route Query Tracer
    |--------------------------------------------------------------------------
    | The tracer forwards requests to your running Laravel application and
    | captures every database query fired during that request lifecycle.
    |
    | 'app_url' is auto-detected from APP_URL when this package is installed
    | inside your app. Override with DB_TRACER_APP_URL if needed.
    |
    | The tracer middleware is a strict no-op unless:
    |   (a) APP_ENV=local, AND
    |   (b) the incoming request carries the header  X-DB-Tracer: 1
    |--------------------------------------------------------------------------
    */
    'tracer' => [
        'enabled'         => env('DB_TRACER_ENABLED', true),
        'app_url'         => env('DB_TRACER_APP_URL', env('APP_URL', 'http://127.0.0.1:8000')),
        'timeout_seconds' => (int) env('DB_TRACER_TIMEOUT', 30),

        // The user ID the tracer middleware will automatically authenticate as.
        // - 'auto' or null (default): Automatically logs in as the first user found in the DB.
        // - Specific integer (e.g. 1): Logs in as that specific User ID.
        // - 0 or false: Disables auto-authentication.
        'auth_user_id'    => env('DB_TRACER_AUTH_USER_ID', 'auto'),
    ],
];
