-- ============================================================
-- Lead8X Platform - Advanced Upgrade Schema (Database Migration V4)
-- Date: 2026-04-21
-- ============================================================

-- 1. lead_events: Track every granular action on a lead
CREATE TABLE IF NOT EXISTS `lead_events` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `lead_id`     BIGINT UNSIGNED NOT NULL,
  `event_type`  ENUM('Created', 'Assigned', 'Contacted', 'Qualified', 'Visit', 'Converted', 'Duplicate') NOT NULL,
  `timestamp`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id`     INT UNSIGNED NULL,
  INDEX `idx_lead_id` (`lead_id`),
  INDEX `idx_event_type` (`event_type`),
  INDEX `idx_timestamp` (`timestamp`),
  FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. lead_daily_stats: Pre-aggregated dashboard statistics by day
CREATE TABLE IF NOT EXISTS `lead_daily_stats` (
  `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `stat_date`    DATE NOT NULL,
  `project`      VARCHAR(150) NOT NULL DEFAULT '',
  `location`     VARCHAR(100) NOT NULL DEFAULT '',
  `source`       VARCHAR(200) NOT NULL DEFAULT '',
  `total_leads`  INT UNSIGNED NOT NULL DEFAULT 0,
  `conversions`  INT UNSIGNED NOT NULL DEFAULT 0,
  `duplicates`   INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_daily_stats` (`stat_date`, `project`, `location`, `source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. agent_performance: Pre-calculated agent stats
CREATE TABLE IF NOT EXISTS `agent_performance` (
  `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `agent_id`     INT UNSIGNED NOT NULL,
  `stat_date`    DATE NOT NULL,
  `assigned`     INT UNSIGNED NOT NULL DEFAULT 0,
  `contacted`    INT UNSIGNED NOT NULL DEFAULT 0,
  `converted`    INT UNSIGNED NOT NULL DEFAULT 0,
  `avg_resp_min` FLOAT NOT NULL DEFAULT 0, -- Average response time in minutes
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_agent_daily` (`agent_id`, `stat_date`),
  FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- SQL TRIGGERS FOR ZERO-CODE-CHANGE EVENT LOGGING
-- ============================================================

DELIMITER //

CREATE TRIGGER `after_lead_insert` AFTER INSERT ON `leads`
FOR EACH ROW
BEGIN
    INSERT INTO `lead_events` (`lead_id`, `event_type`, `timestamp`, `user_id`)
    VALUES (NEW.id, 'Created', NOW(), NEW.assigned_to);
END //

CREATE TRIGGER `after_lead_update` AFTER UPDATE ON `leads`
FOR EACH ROW
BEGIN
    -- Handle Assignment (NULL-safe: fires when assigned_to changes even from/to NULL)
    IF NOT (OLD.assigned_to <=> NEW.assigned_to) THEN
        INSERT INTO `lead_events` (`lead_id`, `event_type`, `timestamp`, `user_id`)
        VALUES (NEW.id, 'Assigned', NOW(), NEW.assigned_to);
    END IF;

    -- Handle Status Changes mapped to logical funnel steps (NULL-safe)
    IF NOT (OLD.status <=> NEW.status) THEN
        IF NEW.status IN ('Called') THEN
            INSERT INTO `lead_events` (`lead_id`, `event_type`, `timestamp`, `user_id`) VALUES (NEW.id, 'Contacted', NOW(), NEW.assigned_to);
        ELSEIF NEW.status IN ('Interested', 'Follow Up') THEN
            INSERT INTO `lead_events` (`lead_id`, `event_type`, `timestamp`, `user_id`) VALUES (NEW.id, 'Qualified', NOW(), NEW.assigned_to);
        ELSEIF NEW.status = 'Site Visit' THEN
            INSERT INTO `lead_events` (`lead_id`, `event_type`, `timestamp`, `user_id`) VALUES (NEW.id, 'Visit', NOW(), NEW.assigned_to);
        ELSEIF NEW.status = 'Booked' THEN
            INSERT INTO `lead_events` (`lead_id`, `event_type`, `timestamp`, `user_id`) VALUES (NEW.id, 'Converted', NOW(), NEW.assigned_to);
        END IF;
    END IF;

    -- Handle duplicates
    IF OLD.is_duplicate = 0 AND NEW.is_duplicate = 1 THEN
        INSERT INTO `lead_events` (`lead_id`, `event_type`, `timestamp`, `user_id`)
        VALUES (NEW.id, 'Duplicate', NOW(), NEW.assigned_to);
    END IF;
END //

DELIMITER ;

