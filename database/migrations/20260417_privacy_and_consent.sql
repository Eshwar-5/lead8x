-- 20260417_privacy_and_consent.sql
-- Privacy compliance: consent and retention tracking
-- UPDATED: Renamed user_consent to has_user_consent for clarity

ALTER TABLE leads
  ADD COLUMN has_user_consent TINYINT(1) DEFAULT 0 COMMENT '0=no consent; 1=consent given',
  ADD COLUMN retention_date    DATETIME NULL DEFAULT NULL COMMENT 'Date when lead data should be purged';

ALTER TABLE leads
  ADD INDEX idx_consent (has_user_consent),
  ADD INDEX idx_retention (retention_date);
