-- Réglages globaux de la plateforme (clé/valeur) : palette et mode du thème gérés par le super admin.
CREATE TABLE app_settings (
  setting_key   VARCHAR(100)    NOT NULL,
  setting_value MEDIUMTEXT      NOT NULL,
  updated_by    BIGINT UNSIGNED NULL,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
