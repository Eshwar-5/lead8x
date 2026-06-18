-- 20260417_enforce_one_location_per_project.sql
-- Enforce: each project_name can have ONLY ONE location
-- Includes: backup of removed rows, transactional delete, safe index management.

-- ── Step 1: Backup rows that will be deleted (duplicates) ────────────────────
-- Creates audit table with the exact rows that will be removed.
CREATE TABLE IF NOT EXISTS project_locations_backup_20260417 LIKE project_locations;

INSERT IGNORE INTO project_locations_backup_20260417
SELECT pl.*
FROM project_locations pl
INNER JOIN (
  SELECT project_name, MIN(id) AS keep_id
  FROM project_locations
  GROUP BY project_name
) keep_map ON pl.project_name = keep_map.project_name
WHERE pl.id != keep_map.keep_id;

-- ── Step 2: Delete duplicate rows inside a transaction ───────────────────────
-- Keeps only the oldest row (MIN id) per project_name.
START TRANSACTION;

DELETE pl FROM project_locations pl
INNER JOIN (
  SELECT project_name, MIN(id) AS keep_id
  FROM project_locations
  GROUP BY project_name
) keep_map ON pl.project_name = keep_map.project_name
WHERE pl.id != keep_map.keep_id;

COMMIT;

-- ── Step 3: Drop old composite unique key (safe — procedure + info_schema) ───
DROP PROCEDURE IF EXISTS _drop_idx_if_exists;
DELIMITER $$
CREATE PROCEDURE _drop_idx_if_exists()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'project_locations'
      AND INDEX_NAME   = 'uq_project_location'
  ) THEN
    ALTER TABLE project_locations DROP INDEX uq_project_location;
  END IF;
END$$
DELIMITER ;
CALL _drop_idx_if_exists();
DROP PROCEDURE IF EXISTS _drop_idx_if_exists;

-- ── Step 4: Add single-column unique key (one location per project) ───────────
DROP PROCEDURE IF EXISTS _add_idx_if_missing;
DELIMITER $$
CREATE PROCEDURE _add_idx_if_missing()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'project_locations'
      AND INDEX_NAME   = 'uq_project_name'
  ) THEN
    ALTER TABLE project_locations ADD UNIQUE KEY uq_project_name (project_name);
  END IF;
END$$
DELIMITER ;
CALL _add_idx_if_missing();
DROP PROCEDURE IF EXISTS _add_idx_if_missing;

-- Done. Backup of removed rows is in: project_locations_backup_20260417
-- To restore: INSERT IGNORE INTO project_locations SELECT * FROM project_locations_backup_20260417;
-- To drop backup when no longer needed: DROP TABLE project_locations_backup_20260417;
