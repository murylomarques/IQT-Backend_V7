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
        'storage/*'
    ],

    'allowed_methods' => ['*'],

    // Se quiser liberar só para o domínio do seu frontend:
    // 'allowed_origins' => ['http://localhost:3000', 'https://seusite.com'],
    'allowed_origins' => [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'https://iqt.desktop.com.br'
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // ⚠️ IMPORTANTE: precisa ser true para enviar cookies (Sanctum)
    'supports_credentials' => true,

];
