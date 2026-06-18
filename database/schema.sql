-- ============================================================
-- Lead8X Platform - Database Schema
-- Database: a1679hju_leadpro
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+05:30";

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(100)  NOT NULL,
  `email`         VARCHAR(150)  NOT NULL UNIQUE,
  `password_hash` VARCHAR(255)  NOT NULL,
  `role`          ENUM('Admin','Caller','Relationship Manager','Manager') NOT NULL DEFAULT 'Caller',
  `is_active`     TINYINT(1)    NOT NULL DEFAULT 1,
  `last_login`    DATETIME      NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_role` (`role`),
  INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: leads
-- ============================================================
CREATE TABLE IF NOT EXISTS `leads` (
  `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `phone`           VARCHAR(20)   NOT NULL,
  `name`            VARCHAR(150)  NULL,
  `email`           VARCHAR(150)  NULL,
  `city`            VARCHAR(100)  NULL,
  `project`         VARCHAR(150)  NULL,
  `status`          ENUM('New','Assigned','Called','Interested','Follow Up','Site Visit','Booked','Not Interested','Wrong Number') NOT NULL DEFAULT 'New',
  `first_source`    VARCHAR(200)  NULL,
  `first_batch_id`  VARCHAR(100)  NULL,
  `assigned_to`     INT UNSIGNED  NULL,
  `duplicate_count` INT UNSIGNED  NOT NULL DEFAULT 0,
  `is_duplicate`    TINYINT(1)    NOT NULL DEFAULT 0,
  `remark`          TEXT          NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_phone` (`phone`),
  INDEX `idx_status` (`status`),
  INDEX `idx_assigned_to` (`assigned_to`),
  INDEX `idx_is_duplicate` (`is_duplicate`),
  INDEX `idx_first_batch` (`first_batch_id`),
  INDEX `idx_created` (`created_at`),
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: lead_sources
-- ============================================================
CREATE TABLE IF NOT EXISTS `lead_sources` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `lead_id`     BIGINT UNSIGNED NOT NULL,
  `source_name` VARCHAR(200) NULL,
  `campaign`    VARCHAR(200) NULL,
  `batch_id`    VARCHAR(100) NOT NULL,
  `uploaded_by` INT UNSIGNED NULL,
  `uploaded_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_lead_id` (`lead_id`),
  INDEX `idx_batch_id` (`batch_id`),
  FOREIGN KEY (`lead_id`)     REFERENCES `leads`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: lead_assignments
-- ============================================================
CREATE TABLE IF NOT EXISTS `lead_assignments` (
  `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `lead_id`         BIGINT UNSIGNED NOT NULL,
  `assigned_to`     INT UNSIGNED    NOT NULL,
  `assigned_by`     INT UNSIGNED    NULL,
  `assignment_type` ENUM('Manual','Equal','Rule') NOT NULL DEFAULT 'Manual',
  `batch_id`        VARCHAR(100) NULL,
  `from_role`       VARCHAR(50)  NULL,
  `to_role`         VARCHAR(50)  NULL,
  `assigned_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_lead_id`     (`lead_id`),
  INDEX `idx_assigned_to` (`assigned_to`),
  INDEX `idx_batch_id`    (`batch_id`),
  FOREIGN KEY (`lead_id`)     REFERENCES `leads`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: lead_timeline
-- ============================================================
CREATE TABLE IF NOT EXISTS `lead_timeline` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `lead_id`     BIGINT UNSIGNED NOT NULL,
  `event_type`  ENUM('Uploaded','Assigned','Transferred','Status Updated','Remark Added','Downloaded','Feedback') NOT NULL,
  `description` TEXT NULL,
  `actor_id`    INT UNSIGNED NULL,
  `actor_name`  VARCHAR(100) NULL,
  `old_value`   VARCHAR(255) NULL,
  `new_value`   VARCHAR(255) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_lead_id` (`lead_id`),
  INDEX `idx_event_type` (`event_type`),
  INDEX `idx_created` (`created_at`),
  FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: activity_log
-- ============================================================
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NULL,
  `user_name`   VARCHAR(100) NULL,
  `action`      VARCHAR(200) NOT NULL,
  `description` TEXT NULL,
  `ip_address`  VARCHAR(45) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_id`  (`user_id`),
  INDEX `idx_created`  (`created_at`),
  INDEX `idx_action`   (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DEFAULT ADMIN USER (password: Admin@Lead8X)
-- ============================================================
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `is_active`)
VALUES (
  'Super Admin',
  'admin@digital8x.site',
  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'Admin',
  1
);
