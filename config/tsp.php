<?php

return [
    'connection' => env('TSP_DB_CONNECTION', 'tsp'),

    'source_connection' => env('TSP_SOURCE_CONNECTION', 'melhoria_continua'),
    'source_table' => env('TSP_SOURCE_TABLE', 'agendamentos_geovane'),
    'source_filters' => [
        'empresa_tecnico' => env('TSP_FILTER_EMPRESA_TECNICO', 'TSP SERVICOS ELETRICOS LTDA'),
        'cidade' => env('TSP_FILTER_CIDADE', 'Sorocaba'),
    ],

    'company_cnpj' => env('TSP_COMPANY_CNPJ', ''),
    'timezone' => env('TSP_TIMEZONE', 'America/Sao_Paulo'),
    'demo_mode' => filter_var(env('TSP_DEMO_MODE', false), FILTER_VALIDATE_BOOLEAN),
    'token_ttl_minutes' => (int) env('TSP_TOKEN_TTL_MINUTES', 60),
    'page_size_default' => (int) env('TSP_PAGE_SIZE_DEFAULT', 500),
    'page_size_max' => (int) env('TSP_PAGE_SIZE_MAX', 2000),
    'max_image_kb' => (int) env('TSP_MAX_IMAGE_KB', 2048),

    'salesforce' => [
        'domain' => env('SALESFORCE_DOMAIN'),
        'client_id' => env('SALESFORCE_CLIENT_ID'),
        'client_secret' => env('SALESFORCE_CLIENT_SECRET'),
        'api_version' => env('SALESFORCE_API_VERSION', 'v60.0'),
    ],
];
