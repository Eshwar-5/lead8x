-- ============================================================
-- Lead8X Security Migration – Auth Hardening
-- Run AFTER migrate_v3.sql
-- ============================================================

-- Rate limiting: track failed login attempts and account lockout
ALTER TABLE `users`
  ADD COLUMN `login_attempts`          TINYINT UNSIGNED  NOT NULL DEFAULT 0           AFTER `is_active`,
  ADD COLUMN `lockout_until`           DATETIME          NULL                         AFTER `login_attempts`,

-- Email verification
  ADD COLUMN `email_verified_at`       DATETIME          NULL                         AFTER `lockout_until`,
  ADD COLUMN `email_verification_token`VARCHAR(64)       NULL                         AFTER `email_verified_at`,

-- Password reset
  ADD COLUMN `reset_token`             VARCHAR(64)       NULL                         AFTER `email_verification_token`,
  ADD COLUMN `reset_token_expires_at`  DATETIME          NULL                         AFTER `reset_token`,

  ADD INDEX `idx_email_verification_token` (`email_verification_token`),
  ADD INDEX `idx_reset_token` (`reset_token`);

-- Mark the default admin as already verified (so they can log in immediately)
UPDATE `users`
   SET `email_verified_at` = NOW()
 WHERE `email` = 'admin@digital8x.site';
