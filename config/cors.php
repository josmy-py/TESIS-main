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

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Origen del frontend Vue (FRONTEND_URL en .env)
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],

    'allowed_origins_patterns' => [],

    // Incluye Authorization (token JWT Bearer) y Content-Type
    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Autenticación por token en cabecera, no por cookies
    'supports_credentials' => false,

];
