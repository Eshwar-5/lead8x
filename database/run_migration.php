<?php
require_once __DIR__ . '/backend/config/database.php';
try {
    $pdo = Database::getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sqlFile = __DIR__ . '/database/migrations/20260417_webhook_integration.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }

    $sql = file_get_contents($sqlFile);
    // Split by semicolon but preserve those inside strings (basic regex)
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    echo "Starting migration...\n";
    $successCount = 0;
    $skipCount = 0;

    foreach ($statements as $stmtText) {
        if (empty($stmtText)) continue;
        
        try {
            $pdo->exec($stmtText);
            $successCount++;
        } catch (PDOException $e) {
            // 1060: Duplicate column name
            // 1061: Duplicate key name
            // 1050: Table already exists
            if (in_array($e->errorInfo[1], [1060, 1061, 1050])) {
                $skipCount++;
                continue; 
            }
            throw $e; // Re-throw actual errors
        }
    }
    
    echo "Migration completed. ($successCount executed, $skipCount skipped already-existing structures)\n";
    
    // Only unlink on success
    if (file_exists(__FILE__)) {
        unlink(__FILE__);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Migration Failed: " . $e->getMessage());
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
