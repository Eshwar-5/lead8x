<?php
// backend/api/admin/backup.php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/ExcelHandler.php';

Response::setCorsHeaders();
$user = Auth::requireAuth(['Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('Method not allowed', 405);

$pdo     = Database::getConnection();
$tmpDir  = sys_get_temp_dir() . '/lead8x_backup_' . uniqid();
mkdir($tmpDir);

try {
    // 1 — Generate leads Excel
    $leads = $pdo->query("SELECT * FROM leads ORDER BY id ASC")->fetchAll();
    $leadsXlsx = ExcelHandler::generateLeadsExcel($leads, 'All_Leads');
    copy($leadsXlsx, $tmpDir . '/leads.xlsx');
    @unlink($leadsXlsx);

    // 2 — Generate users Excel
    $users = $pdo->query("SELECT id, name, email, role, is_active, last_login, created_at FROM users")->fetchAll();
    $userFile = ExcelHandler::generateLeadsExcel($users, 'Users');
    copy($userFile, $tmpDir . '/users.xlsx');
    @unlink($userFile);

    // 3 — SQL Schema dump (structure only + users + stats)
    $sqlDump = "-- Lead8X Backup | " . date('Y-m-d H:i:s') . "\n\n";

    // Get CREATE TABLE statements
    $tables = ['leads','lead_sources','lead_assignments','lead_timeline','users','activity_log'];
    foreach ($tables as $table) {
        $row = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        $sqlDump .= $row[1] . ";\n\n";
    }

    // Dump users data (for restore)
    $userRows = $pdo->query("SELECT * FROM users")->fetchAll();
    if (!empty($userRows)) {
        $cols = implode('`, `', array_keys($userRows[0]));
        $sqlDump .= "INSERT INTO `users` (`{$cols}`) VALUES\n";
        $inserts = [];
        foreach ($userRows as $r) {
            $vals = array_map(fn($v) => is_null($v) ? 'NULL' : "'" . addslashes((string)$v) . "'", array_values($r));
            $inserts[] = '(' . implode(', ', $vals) . ')';
        }
        $sqlDump .= implode(",\n", $inserts) . ";\n";
    }

    file_put_contents($tmpDir . '/schema_users.sql', $sqlDump);

    // 4 — ZIP everything
    $zipPath = sys_get_temp_dir() . '/Lead8X_Backup_' . date('Y_m_d_His') . '.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);

    foreach (glob($tmpDir . '/*') as $f) {
        $zip->addFile($f, basename($f));
    }
    $zip->close();

    // Cleanup tmp folder
    array_map('unlink', glob($tmpDir . '/*'));
    rmdir($tmpDir);

    Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'Backup', 'Full backup ZIP generated.');

    // Stream ZIP
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="Lead8X_Backup_' . date('Y_m_d_His') . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;

} catch (\Throwable $e) {
    // Cleanup on error
    if (is_dir($tmpDir)) {
        array_map('unlink', glob($tmpDir . '/*') ?: []);
        @rmdir($tmpDir);
    }
    Response::error('Backup failed: ' . $e->getMessage(), 500);
}
