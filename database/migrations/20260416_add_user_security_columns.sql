-- ============================================================
-- LeadPro Migration: Add Security Columns to Users Table
-- Created: 2026-04-16
-- Description: Adds tracking for login attempts and account lockouts.
-- ============================================================

ALTER TABLE `users` 
ADD COLUMN `login_attempts` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_active`,
ADD COLUMN `lockout_until` DATETIME NULL AFTER `login_attempts`,
ADD COLUMN `email_verified_at` DATETIME NULL AFTER `lockout_until`,
ADD COLUMN `email_verification_token` VARCHAR(100) NULL AFTER `email_verified_at`,
ADD COLUMN `reset_token` VARCHAR(100) NULL AFTER `email_verification_token`,
ADD COLUMN `reset_token_expires_at` DATETIME NULL AFTER `reset_token`;
