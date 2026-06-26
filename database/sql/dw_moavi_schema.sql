CREATE DATABASE IF NOT EXISTS `dw_moavi`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `dw_moavi`;

CREATE TABLE IF NOT EXISTS `api_clients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `cnpj_empresa` varchar(32) DEFAULT NULL,
  `secret_hash` varchar(255) NOT NULL,
  `scopes` json DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `token_ttl_minutes` smallint unsigned NOT NULL DEFAULT 60,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_clients_client_id_unique` (`client_id`),
  KEY `api_clients_active_client_id_index` (`active`, `client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `api_client_id` bigint unsigned NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `scopes` json DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_tokens_token_hash_unique` (`token_hash`),
  KEY `api_tokens_api_client_id_expires_at_index` (`api_client_id`, `expires_at`),
  KEY `api_tokens_revoked_at_expires_at_index` (`revoked_at`, `expires_at`),
  CONSTRAINT `api_tokens_api_client_id_foreign`
    FOREIGN KEY (`api_client_id`) REFERENCES `api_clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `api_client_id` bigint unsigned NOT NULL,
  `sync_key_hash` varchar(64) NOT NULL,
  `endpoint` varchar(64) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `baseline_started_at` datetime NOT NULL,
  `baseline_completed_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `source_count` int unsigned NOT NULL DEFAULT 0,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `metadata` json DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sync_sessions_sync_key_hash_unique` (`sync_key_hash`),
  KEY `moavi_sync_client_status_exp_idx` (`api_client_id`, `status`, `expires_at`),
  KEY `moavi_sync_period_idx` (`period_start`, `period_end`),
  CONSTRAINT `sync_sessions_api_client_id_foreign`
    FOREIGN KEY (`api_client_id`) REFERENCES `api_clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_session_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sync_session_id` bigint unsigned NOT NULL,
  `source_id` varchar(255) NOT NULL,
  `source_numero_compromisso` varchar(255) NOT NULL,
  `row_hash` varchar(64) NOT NULL,
  `source_updated_at` datetime DEFAULT NULL,
  `source_schedule_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `moavi_sync_item_source_unique` (`sync_session_id`, `source_id`),
  KEY `moavi_sync_item_updated_idx` (`sync_session_id`, `source_updated_at`, `source_id`),
  KEY `moavi_sync_item_hash_idx` (`sync_session_id`, `row_hash`),
  CONSTRAINT `sync_session_items_sync_session_id_foreign`
    FOREIGN KEY (`sync_session_id`) REFERENCES `sync_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_request_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_id` char(36) NOT NULL,
  `api_client_id` bigint unsigned DEFAULT NULL,
  `endpoint` varchar(128) NOT NULL,
  `method` varchar(16) NOT NULL,
  `status_code` smallint unsigned NOT NULL,
  `duration_ms` int unsigned NOT NULL DEFAULT 0,
  `ip_hash` varchar(64) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `error_code` varchar(64) DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_request_logs_request_id_unique` (`request_id`),
  KEY `moavi_log_client_created_idx` (`api_client_id`, `created_at`),
  KEY `moavi_log_endpoint_created_idx` (`endpoint`, `created_at`),
  CONSTRAINT `api_request_logs_api_client_id_foreign`
    FOREIGN KEY (`api_client_id`) REFERENCES `api_clients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
