<?php

return [
    'connection' => env('MOAVI_DB_CONNECTION', 'moavi'),

    'source_connection' => env('MOAVI_SOURCE_CONNECTION', 'melhoria_continua'),
    'source_table' => env('MOAVI_SOURCE_TABLE', 'agendamentos_geovane'),

    'company_cnpj' => env('MOAVI_COMPANY_CNPJ', '08.170.849/0053-46'),
    'timezone' => env('MOAVI_TIMEZONE', 'America/Sao_Paulo'),
    'min_schedule_date' => env('MOAVI_MIN_SCHEDULE_DATE', '2026-01-01'),

    'token_ttl_minutes' => (int) env('MOAVI_TOKEN_TTL_MINUTES', 60),
    'sync_key_ttl_days' => (int) env('MOAVI_SYNC_KEY_TTL_DAYS', 7),

    'page_size_default' => (int) env('MOAVI_PAGE_SIZE_DEFAULT', 500),
    'page_size_max' => (int) env('MOAVI_PAGE_SIZE_MAX', 2000),
    'snapshot_insert_chunk' => (int) env('MOAVI_SNAPSHOT_INSERT_CHUNK', 1000),
];
