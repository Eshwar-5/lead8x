<?php
// backend/api/leads/upload-feedback.php
// Excel Feedback Sync: re-upload exported sheet with ID, Status, Remarks to batch-update leads

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/ExcelHandler.php';

Response::setCorsHeaders();

$user = Auth::requireAuth(['Admin', 'Manager', 'Caller', 'Relationship Manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

if (empty($_FILES['file'])) {
    Response::error('No file uploaded.');
}

$file    = $_FILES['file'];
$allowed = ['xlsx', 'xls', 'csv'];
$ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowed, true)) {
    Response::error('Only Excel (.xlsx, .xls) and CSV files are allowed.');
}
if ($file['error'] !== UPLOAD_ERR_OK) {
    Response::error('File upload error: ' . $file['error']);
}

$tmpPath = sys_get_temp_dir() . '/lead8x_fb_' . uniqid() . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
    Response::error('Failed to process uploaded file.');
}

// Parse raw rows (we need ID, Status, Remarks columns)
$rows = [];
try {
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
    $sheet = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
    @unlink($tmpPath);
    if (empty($sheet)) Response::error('Empty file.');

    // Header row
    $headers = array_map(fn($h) => strtolower(trim((string)$h)), $sheet[0]);

    // Map columns
    $colMap = [];
    foreach ($headers as $i => $h) {
        if (in_array($h, ['id', 'unique id', 'unique_id', 'lead id', 'lead_id'], true)) $colMap['id'] = $i;
        if (in_array($h, ['status'], true)) $colMap['status'] = $i;
        if (in_array($h, ['remarks', 'remark', 'notes', 'note'], true)) $colMap['remark'] = $i;
    }

    if (!isset($colMap['id'])) {
        Response::error('File must have an "ID" column. Please use an exported Lead8X file.');
    }

    $validStatuses = ['New','Assigned','Called','Interested','Follow Up','Site Visit','Booked','Not Interested','Wrong Number'];

    for ($i = 1; $i < count($sheet); $i++) {
        $row = $sheet[$i];
        $id  = (int)($row[$colMap['id']] ?? 0);
        if ($id <= 0) continue;

        $status = isset($colMap['status']) ? trim((string)($row[$colMap['status']] ?? '')) : '';
        $remark = isset($colMap['remark']) ? trim((string)($row[$colMap['remark']] ?? '')) : '';

        if ($status && !in_array($status, $validStatuses, true)) continue;

        $rows[] = ['id' => $id, 'status' => $status, 'remark' => $remark];
    }
} catch (\Throwable $e) {
    @unlink($tmpPath);
    Response::error('Could not parse file: ' . $e->getMessage());
}

if (empty($rows)) Response::error('No valid rows with Lead ID found.');

$pdo = Database::getConnection();
$updated = 0;

$pdo->beginTransaction();
try {
    foreach ($rows as $row) {
        $setParts = [];
        $params   = [];

        if (!empty($row['status'])) { $setParts[] = 'status = ?'; $params[] = $row['status']; }
        if ($row['remark'] !== '')  { $setParts[] = 'remark = ?'; $params[] = $row['remark']; }

        if (empty($setParts)) continue;

        $params[] = $row['id'];
        $where = "WHERE id = ?";
        if (in_array($user['role'], ['Caller', 'Relationship Manager'], true)) {
            $where .= " AND assigned_to = ?";
            $params[] = $user['id'];
        }

        $stmt = $pdo->prepare("UPDATE leads SET " . implode(', ', $setParts) . " " . $where);
        $stmt->execute($params);

        if ($stmt->rowCount() > 0) {
            $updated++;
            // Log timeline
            $pdo->prepare("INSERT INTO lead_timeline (lead_id, event_type, description, actor_id, actor_name)
                           VALUES (?, 'Feedback', ?, ?, ?)")
                ->execute([$row['id'],
                    "Feedback sync: status={$row['status']}, remark={$row['remark']}",
                    $user['id'], $user['name']]);
        }
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    Response::error('Sync failed: ' . $e->getMessage(), 500);
}

Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'Feedback Sync',
    "Updated {$updated} of " . count($rows) . " leads via Excel feedback sync.");

Response::success("Feedback sync complete.", [
    'processed' => count($rows),
    'updated'   => $updated,
]);
