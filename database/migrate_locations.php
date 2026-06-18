<?php
/**
 * migrate_locations.php — Create project_locations table
 * SECURITY: CLI only
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('ERROR: CLI only.');
}

require_once __DIR__ . '/backend/config/database.php';

try {
    $pdo = Database::getConnection();
    fwrite(STDOUT, "--- Project Locations Migration ---\n");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS project_locations (
          id           INT AUTO_INCREMENT PRIMARY KEY,
          project_name VARCHAR(150) NOT NULL,
          location     VARCHAR(150) NOT NULL,
          created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_project_location (project_name, location),
          INDEX idx_project_name (project_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    fwrite(STDOUT, "✅ project_locations table ready.\n");
    fwrite(STDOUT, "--- Migration Complete ---\n");
} catch (Throwable $e) {
    error_log("Migration Failed (migrate_locations.php): " . $e->getMessage());
    fwrite(STDERR, "❌ ERROR: Check server error logs.\n");
    exit(1);
}
