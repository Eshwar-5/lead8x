<?php
/**
 * migrate_privacy.php - Update database for PII compliance (Refined V3)
 * SECURITY: Only runnable from CLI.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('ERROR: This script must be run from the command line (CLI) for security reasons.');
}

require_once __DIR__ . '/backend/config/database.php';

try {
    $pdo = Database::getConnection();
    
    fwrite(STDOUT, "--- Privacy Compliance Migration (V3.1) ---\n");
    
    // Fetch all columns to check existence
    $stmt = $pdo->query("SHOW COLUMNS FROM leads");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $hasConsent = in_array('has_user_consent', $columns);
    $oldConsent = in_array('user_consent', $columns);
    $hasRetention = in_array('retention_date', $columns);

    $alters = [];

    // 1. Handle Consent Column
    if (!$hasConsent) {
        if ($oldConsent) {
            fwrite(STDOUT, "Renaming 'user_consent' to 'has_user_consent'...\n");
            $alters[] = "CHANGE COLUMN user_consent has_user_consent TINYINT(1) DEFAULT 0 COMMENT '0=no; 1=yes'";
        } else {
            fwrite(STDOUT, "Adding 'has_user_consent' column...\n");
            $alters[] = "ADD COLUMN has_user_consent TINYINT(1) DEFAULT 0 COMMENT '0=no; 1=yes'";
        }
    }

    // 2. Handle Retention Column
    if (!$hasRetention) {
        fwrite(STDOUT, "Adding 'retention_date' column...\n");
        $alters[] = "ADD COLUMN retention_date DATETIME NULL DEFAULT NULL COMMENT 'Purge date'";
    }

    if (!empty($alters)) {
        $sql = "ALTER TABLE leads " . implode(", ", $alters);
        
        // Also add indexes if they don't exist
        // Note: Checking indexes is more complex, but we'll try to add them and ignore errors or add them separately
        $pdo->exec($sql);
        
        // Add indexes safely (using separate exec to not block the columns)
        try { $pdo->exec("ALTER TABLE leads ADD INDEX idx_consent (has_user_consent)"); } catch(Throwable $e) {}
        try { $pdo->exec("ALTER TABLE leads ADD INDEX idx_retention (retention_date)"); } catch(Throwable $e) {}
        
        fwrite(STDOUT, "✅ Privacy schema updated successfully.\n");
    } else {
        fwrite(STDOUT, "ℹ️ Privacy schema is already up to date.\n");
    }

    fwrite(STDOUT, "--- Migration Complete ---\n");

} catch (Throwable $e) {
    // Log the full error to server-side logs, don't leak to console/web
    error_log("Migration Failed (migrate_privacy.php): " . $e->getMessage() . "\n" . $e->getTraceAsString());
    fwrite(STDERR, "❌ ERROR: Migration failed. Check server error logs for details.\n");
    exit(1);
}
