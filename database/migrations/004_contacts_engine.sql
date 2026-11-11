-- Module 2 : Contacts Engine (MySQL 8 / MariaDB 10.4+, InnoDB, utf8mb4)
-- Unicité « parmi les lignes actives » : une colonne générée vaut NULL pour une ligne supprimée logiquement,
-- et MySQL autorise plusieurs NULL dans un index UNIQUE. On peut ainsi recréer un numéro après suppression.

CREATE TABLE contacts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)        NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  phone_e164      VARCHAR(20)     NOT NULL,
  first_name      VARCHAR(80)     NULL,
  last_name       VARCHAR(80)     NULL,
  email           VARCHAR(190)    NULL,
  country         CHAR(2)         NULL,
  operator        VARCHAR(20)     NULL,
  status          ENUM('active','blocked') NOT NULL DEFAULT 'active',
  source          ENUM('manual','import','api') NOT NULL DEFAULT 'manual',
  import_id       BIGINT UNSIGNED NULL,
  attributes      JSON            NULL,
  created_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME        NULL,
  phone_unique    VARCHAR(20) GENERATED ALWAYS AS (IF(deleted_at IS NULL, phone_e164, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contact_uuid (uuid),
  UNIQUE KEY uq_contact_phone (organization_id, phone_unique),
  KEY idx_contact_org_created (organization_id, deleted_at, created_at),
  KEY idx_contact_org_email (organization_id, email),
  KEY idx_contact_org_geo (organization_id, country, operator),
  KEY idx_contact_import (import_id),
  KEY idx_contact_deleted (deleted_at),
  CONSTRAINT fk_contact_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_tags (
  contact_id      BIGINT UNSIGNED NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  tag             VARCHAR(50)     NOT NULL,
  PRIMARY KEY (contact_id, tag),
  KEY idx_tag_org (organization_id, tag),
  CONSTRAINT fk_tag_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_groups (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)        NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  name            VARCHAR(100)    NOT NULL,
  description     VARCHAR(255)    NULL,
  created_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME        NULL,
  name_unique     VARCHAR(100) GENERATED ALWAYS AS (IF(deleted_at IS NULL, name, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_group_uuid (uuid),
  UNIQUE KEY uq_group_name (organization_id, name_unique),
  KEY idx_group_org (organization_id, deleted_at),
  CONSTRAINT fk_group_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_group_members (
  group_id        BIGINT UNSIGNED NOT NULL,
  contact_id      BIGINT UNSIGNED NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  added_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id, contact_id),
  KEY idx_member_contact (contact_id),
  KEY idx_member_org (organization_id, group_id),
  CONSTRAINT fk_member_group FOREIGN KEY (group_id) REFERENCES contact_groups (id) ON DELETE CASCADE,
  CONSTRAINT fk_member_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE segments (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)        NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  name            VARCHAR(100)    NOT NULL,
  description     VARCHAR(255)    NULL,
  rules           JSON            NOT NULL,
  created_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME        NULL,
  name_unique     VARCHAR(100) GENERATED ALWAYS AS (IF(deleted_at IS NULL, name, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_segment_uuid (uuid),
  UNIQUE KEY uq_segment_name (organization_id, name_unique),
  KEY idx_segment_org (organization_id, deleted_at),
  CONSTRAINT fk_segment_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_imports (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)        NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  created_by      BIGINT UNSIGNED NULL,
  filename        VARCHAR(190)    NOT NULL,
  file_path       VARCHAR(255)    NULL,
  delimiter       CHAR(1)         NOT NULL DEFAULT ',',
  encoding        VARCHAR(12)     NOT NULL DEFAULT 'UTF-8',
  columns_json    JSON            NULL,
  mapping         JSON            NULL,
  options         JSON            NULL,
  status          ENUM('uploaded','processing','completed','failed','cancelled') NOT NULL DEFAULT 'uploaded',
  total_rows      INT UNSIGNED    NOT NULL DEFAULT 0,
  processed_rows  INT UNSIGNED    NOT NULL DEFAULT 0,
  created_rows    INT UNSIGNED    NOT NULL DEFAULT 0,
  updated_rows    INT UNSIGNED    NOT NULL DEFAULT 0,
  duplicate_rows  INT UNSIGNED    NOT NULL DEFAULT 0,
  invalid_rows    INT UNSIGNED    NOT NULL DEFAULT 0,
  byte_offset     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  started_at      DATETIME        NULL,
  completed_at    DATETIME        NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_import_uuid (uuid),
  KEY idx_import_org (organization_id, created_at),
  KEY idx_import_status (status),
  CONSTRAINT fk_import_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_import_errors (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  import_id       BIGINT UNSIGNED NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  line_number     INT UNSIGNED    NOT NULL,
  kind            ENUM('invalid','duplicate') NOT NULL,
  phone_raw       VARCHAR(100)    NULL,
  reason          VARCHAR(190)    NOT NULL,
  raw_data        TEXT            NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ierr_import (import_id, kind, line_number),
  KEY idx_ierr_created (created_at),
  CONSTRAINT fk_ierr_import FOREIGN KEY (import_id) REFERENCES contact_imports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique des modifications d'un contact (qui, quand, quoi)
CREATE TABLE contact_changes (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  contact_id      BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NULL,
  action          VARCHAR(30)     NOT NULL,
  changes         JSON            NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_change_contact (contact_id, created_at),
  KEY idx_change_org (organization_id, created_at),
  CONSTRAINT fk_change_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
