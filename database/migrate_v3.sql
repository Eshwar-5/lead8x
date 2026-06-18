-- ============================================================
-- Lead8X v3 — Database Migration
-- Run AFTER migrate_v2.sql
-- ============================================================

-- Add device column to leads (v2 mapped it but didn't add the column)
ALTER TABLE `leads`
  ADD COLUMN `device` VARCHAR(200) NULL AFTER `ip_address`;
