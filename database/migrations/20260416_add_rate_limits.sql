-- ============================================================
-- LeadPro Migration: Add Rate Limiting Table
-- Created: 2026-04-16
-- Description: Supports IP-based and endpoint-based rate limiting.
-- ============================================================

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `identifier`      VARCHAR(100) NOT NULL, -- IP address or Email
  `endpoint`        VARCHAR(100) NOT NULL, -- e.g., 'login', 'forgot_password', 'api'
  `hits`            INT UNSIGNED NOT NULL DEFAULT 1,
  `reset_at`        DATETIME     NOT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_identifier_endpoint` (`identifier`, `endpoint`),
  INDEX `idx_reset_at` (`reset_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
