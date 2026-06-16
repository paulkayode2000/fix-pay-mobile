<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Explicitly list allowed origins — withCredentials:true forbids wildcard '*'.
    // Add your domain here when you go live (e.g. 'https://app.fixpay.ng').
    'allowed_origins' => [
        'http://129.153.42.30',   // OCI IP (testing)
        'http://localhost',        // local dev
        'http://localhost:5173',   // Vite dev server
    ],

    // Patterns must be valid PHP regex strings. Leave empty when using allowed_origins.
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
