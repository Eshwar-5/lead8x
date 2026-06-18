-- ============================================================
-- Lead8X v2 — Database Migration (MySQL 5.7+ compatible)
-- Run this in phpMyAdmin AFTER the original schema.sql
-- ============================================================

-- Add new columns to leads table
ALTER TABLE `leads`
  ADD COLUMN `entry_id`   VARCHAR(100) NULL AFTER `id`,
  ADD COLUMN `refer_url`  TEXT         NULL AFTER `remark`,
  ADD COLUMN `ip_address` VARCHAR(45)  NULL AFTER `refer_url`,
  ADD COLUMN `country`    VARCHAR(100) NULL AFTER `ip_address`,
  ADD COLUMN `is_nri`     TINYINT(1)   NOT NULL DEFAULT 0 AFTER `country`,
  ADD COLUMN `deleted_at` DATETIME     NULL AFTER `updated_at`,
  ADD INDEX  `idx_is_nri`  (`is_nri`),
  ADD INDEX  `idx_deleted` (`deleted_at`);

-- ============================================================
-- TABLE: projects
-- ============================================================
CREATE TABLE IF NOT EXISTS `projects` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(200) NOT NULL,
  `location`   VARCHAR(200) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill projects from existing leads
INSERT IGNORE INTO `projects` (`name`)
SELECT DISTINCT `project` FROM `leads`
WHERE `project` IS NOT NULL AND `project` != '';
