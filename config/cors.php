<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your settings for cross-origin resource sharing or "CORS".
    | This determines what cross-origin operations may execute in web browsers.
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
    ],

    'allowed_methods' => ['*'],

    // Se quiser liberar só para o domínio do seu frontend:
    // 'allowed_origins' => ['http://localhost:3000', 'https://seusite.com'],
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // ⚠️ IMPORTANTE: precisa ser true para enviar cookies (Sanctum)
    'supports_credentials' => true,

];
