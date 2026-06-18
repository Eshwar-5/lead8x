-- 20260417_project_locations.sql
-- Project locations: canonical location names per project

CREATE TABLE IF NOT EXISTS project_locations (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  project_name VARCHAR(150) NOT NULL,
  location     VARCHAR(150) NOT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_project_location (project_name, location),
  INDEX idx_project_name (project_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
