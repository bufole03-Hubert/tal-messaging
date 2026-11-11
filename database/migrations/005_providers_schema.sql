-- Module 3 : Provider Adapter Layer & Fournisseurs Télécom (MySQL 8 / MariaDB 10.4+, InnoDB, utf8mb4)

CREATE TABLE providers (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)        NOT NULL,
  slug            VARCHAR(50)     NOT NULL,
  name            VARCHAR(100)    NOT NULL,
  channel         ENUM('sms','whatsapp') NOT NULL DEFAULT 'sms',
  status          ENUM('active','inactive','degraded') NOT NULL DEFAULT 'inactive',
  priority        INT UNSIGNED    NOT NULL DEFAULT 100,
  driver          VARCHAR(50)     NOT NULL DEFAULT 'africas_talking',
  is_default      TINYINT(1)      NOT NULL DEFAULT 0,
  settings        JSON            NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_uuid (uuid),
  UNIQUE KEY uq_provider_slug (slug),
  KEY idx_provider_status (status, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Credentials chiffrés AES-256-GCM (jamais exposés aux clients)
CREATE TABLE provider_credentials (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id     BIGINT UNSIGNED NOT NULL,
  environment     ENUM('sandbox','production') NOT NULL DEFAULT 'sandbox',
  account_id      VARCHAR(100)    NOT NULL COMMENT 'ex: username Africa\'s Talking',
  api_key_enc     TEXT            NOT NULL COMMENT 'chiffré AES-256-GCM',
  extra_enc       TEXT            NULL COMMENT 'secrets additionnels chiffrés (ex: webhook secret)',
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cred_provider_env (provider_id, environment),
  CONSTRAINT fk_pcred_provider FOREIGN KEY (provider_id) REFERENCES providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sender IDs (demande + suivi d'approbation par opérateur)
CREATE TABLE sender_ids (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)        NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  sender_id       VARCHAR(20)     NOT NULL,
  channel         ENUM('sms','whatsapp') NOT NULL DEFAULT 'sms',
  status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  rejection_reason VARCHAR(255)   NULL,
  created_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sender_uuid (uuid),
  UNIQUE KEY uq_sender_org_name (organization_id, sender_id, channel),
  KEY idx_sender_status (status),
  KEY idx_sender_org (organization_id),
  CONSTRAINT fk_sender_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
