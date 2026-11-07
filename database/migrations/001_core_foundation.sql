-- Module 1 : Core Foundation (MySQL 8+, InnoDB, utf8mb4)

CREATE TABLE organizations (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid        CHAR(36)        NOT NULL,
  name        VARCHAR(150)    NOT NULL,
  slug        VARCHAR(160)    NOT NULL,
  email       VARCHAR(190)    NULL,
  phone       VARCHAR(32)     NULL,
  country     CHAR(2)         NOT NULL DEFAULT 'CD',
  status      ENUM('pending','active','suspended','closed') NOT NULL DEFAULT 'pending',
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at  DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_org_uuid (uuid),
  UNIQUE KEY uq_org_slug (slug),
  KEY idx_org_status (status, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(50)  NOT NULL,
  name        VARCHAR(100) NOT NULL,
  scope       ENUM('internal','client') NOT NULL,
  level       SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_role_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_perm_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id       SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- organization_id NULL = compte interne TAL ; renseigné = compte client rattaché à une organisation.
CREATE TABLE users (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                 CHAR(36)     NOT NULL,
  organization_id      BIGINT UNSIGNED NULL,
  role_id              SMALLINT UNSIGNED NOT NULL,
  email                VARCHAR(190) NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  first_name           VARCHAR(80)  NOT NULL,
  last_name            VARCHAR(80)  NOT NULL,
  status               ENUM('active','suspended') NOT NULL DEFAULT 'active',
  totp_secret          VARCHAR(255) NULL COMMENT 'chiffré AES-256-GCM',
  totp_enabled         TINYINT(1)   NOT NULL DEFAULT 0,
  totp_last_step       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'anti-rejeu TOTP',
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  last_login_at        DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at           DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_uuid (uuid),
  UNIQUE KEY uq_user_email (email),
  KEY idx_user_org (organization_id, deleted_at),
  KEY idx_user_role (role_id),
  CONSTRAINT fk_user_org  FOREIGN KEY (organization_id) REFERENCES organizations (id),
  CONSTRAINT fk_user_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE totp_recovery_codes (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id   BIGINT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  used_at   DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_rc_user (user_id, code_hash),
  CONSTRAINT fk_rc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- id = SHA-256 de l'identifiant de session (jamais stocké en clair)
CREATE TABLE sessions (
  id            CHAR(64)        NOT NULL,
  user_id       BIGINT UNSIGNED NULL,
  payload       MEDIUMTEXT      NOT NULL,
  ip            VARCHAR(45)     NOT NULL,
  user_agent    VARCHAR(255)    NOT NULL,
  last_activity INT UNSIGNED    NOT NULL,
  created_at    INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sess_user (user_id),
  KEY idx_sess_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier VARCHAR(190) NOT NULL,
  ip         VARCHAR(45)  NOT NULL,
  success    TINYINT(1)   NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_identifier (identifier, success, created_at),
  KEY idx_la_ip (ip, success, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only : l'application n'émet jamais UPDATE/DELETE. En production, retirer ces droits à l'utilisateur SQL.
-- Pas de clés étrangères : le journal doit survivre à la suppression des entités.
CREATE TABLE audit_logs (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED NULL,
  organization_id BIGINT UNSIGNED NULL,
  action          VARCHAR(100) NOT NULL,
  resource_type   VARCHAR(60)  NULL,
  resource_id     VARCHAR(64)  NULL,
  ip              VARCHAR(45)  NOT NULL,
  user_agent      VARCHAR(255) NOT NULL,
  metadata        JSON         NULL,
  created_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_audit_org_date (organization_id, created_at),
  KEY idx_audit_user_date (user_id, created_at),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_resource (resource_type, resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- organization_scope = 0 : valeur globale ; > 0 : surcharge pour une organisation.
CREATE TABLE feature_flags (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  flag_key           VARCHAR(100) NOT NULL,
  organization_scope BIGINT UNSIGNED NOT NULL DEFAULT 0,
  enabled            TINYINT(1)   NOT NULL DEFAULT 0,
  description        VARCHAR(255) NULL,
  updated_by         BIGINT UNSIGNED NULL,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_flag_scope (flag_key, organization_scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
