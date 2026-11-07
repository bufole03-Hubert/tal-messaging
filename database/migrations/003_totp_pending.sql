-- Secret 2FA en cours d'enrôlement : stocké (chiffré) en base plutôt qu'en session,
-- pour éviter qu'une requête concurrente n'écrase la session et ne désynchronise le QR code.
ALTER TABLE users ADD COLUMN totp_pending_secret VARCHAR(255) NULL AFTER totp_last_step;
ALTER TABLE users ADD COLUMN totp_pending_at DATETIME NULL AFTER totp_pending_secret;
