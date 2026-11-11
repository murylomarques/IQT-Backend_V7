-- Portal FUP (/fup) no IQT: SQL equivalente à migration 2026_11_11_000100_create_fup_acesso_tables.php
-- Banco: o do IQT (conexão padrão). Não mexe no dw_fup.
-- Preferível rodar a migration:  php artisan migrate --path=database/migrations/2026_11_11_000100_create_fup_acesso_tables.php

CREATE TABLE IF NOT EXISTS fup_usuarios (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(40) NOT NULL,
  nome VARCHAR(80) NOT NULL,
  senha VARCHAR(255) NOT NULL COMMENT 'bcrypt (Hash::make); nunca a senha',
  admin TINYINT(1) NOT NULL DEFAULT 0,
  dono TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'protegido: só ele mesmo se altera',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  telas JSON NOT NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY fup_usuarios_usuario_unique (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fup_sessoes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL COMMENT 'sha256 do token',
  criada_em DATETIME NOT NULL, usada_em DATETIME NOT NULL, expira_em DATETIME NOT NULL,
  UNIQUE KEY fup_sessoes_token_hash_unique (token_hash),
  CONSTRAINT fup_sessoes_usuario_id_foreign FOREIGN KEY (usuario_id) REFERENCES fup_usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fup_compromisso_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  em DATETIME NOT NULL, usuario VARCHAR(40) NOT NULL, acao VARCHAR(12) NOT NULL, data DATE NOT NULL,
  cod_ger TINYINT UNSIGNED NULL, tipo VARCHAR(10) NULL, de INT NULL, para INT NULL,
  para_quem VARCHAR(40) NULL, ate VARCHAR(5) NULL,
  KEY fup_compromisso_log_data_em_index (data, em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fup_prorrogacoes (
  data DATE NOT NULL, alvo VARCHAR(40) NOT NULL COMMENT 'usuário ou * = todos', ate VARCHAR(5) NOT NULL,
  PRIMARY KEY (data, alvo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No dw_fup: o usuário que o IQT usa para GRAVAR o compromisso precisa só disto (troque pelo usuário/host reais):
--   GRANT SELECT, INSERT, UPDATE ON dw_fup.man_compromisso TO 'usuario_escrita_iqt'@'host_do_iqt';
-- As leituras continuam com o usuário somente leitura (FUP_DB_USERNAME).
