-- 20260417_webhook_integration.sql
-- Ad Platform Webhook Integration Migration

-- 1. Webhook sources registry
CREATE TABLE IF NOT EXISTS webhook_sources (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  platform      ENUM('google','meta','linkedin') NOT NULL,
  source_name   VARCHAR(100) NOT NULL,
  verify_token  TEXT NULL,
  app_secret    TEXT NULL,
  graph_token   TEXT NULL,
  is_active     TINYINT(1) DEFAULT 1,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Webhook log (every raw payload received)
CREATE TABLE IF NOT EXISTS webhook_log (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  platform      VARCHAR(50),
  raw_payload   JSON,
  signature     VARCHAR(255),
  status        ENUM('received','processed','failed','duplicate'),
  lead_id       BIGINT NULL,
  error_message TEXT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_platform (platform),
  INDEX idx_status (status),
  INDEX idx_created (created_at)
);

-- 3. Extend existing leads table
ALTER TABLE leads
  ADD COLUMN platform_lead_id VARCHAR(255) NULL,
  ADD COLUMN ad_id            VARCHAR(255) NULL,
  ADD COLUMN form_id          VARCHAR(255) NULL,
  ADD COLUMN campaign_id      VARCHAR(255) NULL,
  ADD COLUMN auto_imported    TINYINT(1) DEFAULT 0;

ALTER TABLE leads
  ADD INDEX idx_platform_lead_id (platform_lead_id);
