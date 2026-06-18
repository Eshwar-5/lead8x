<?php
// backend/api/leads/feedback.php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/ExcelHandler.php';
require_once dirname(__DIR__, 2) . '/core/DuplicateDetector.php';

Response::setCorsHeaders();

$user = Auth::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$validStatuses = ['New','Assigned','Called','Interested','Follow Up','Site Visit','Booked','Not Interested','Wrong Number'];
$pdo      = Database::getConnection();
$detector = new DuplicateDetector($pdo);

// --- Excel Bulk Feedback (file upload) ---
if (!empty($_FILES['file'])) {
    $file = $_FILES['file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx','xls','csv'])) {
        Response::error('Only Excel/CSV files allowed.');
    }
    $tmpPath = sys_get_temp_dir() . '/fb_' . uniqid() . '.' . $ext;
    move_uploaded_file($file['tmp_name'], $tmpPath);

    $rows = ExcelHandler::parseUpload($tmpPath);
    @unlink($tmpPath);

    $stats = ['updated' => 0, 'not_found' => 0, 'invalid_status' => 0];

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $phone  = DuplicateDetector::normalizePhone($row['phone'] ?? '');
            $status = trim($row['status'] ?? '');
            $remark = trim($row['remark'] ?? '');

            if (empty($phone)) { $stats['not_found']++; continue; }

            if ($status && !in_array($status, $validStatuses, true)) {
                $stats['invalid_status']++; continue;
            }

            $stmt = $pdo->prepare("SELECT id, status, assigned_to FROM leads WHERE phone = ? LIMIT 1");
            $stmt->execute([$phone]);
            $lead = $stmt->fetch();
            if (!$lead) { $stats['not_found']++; continue; }

            if (in_array($user['role'], ['Caller', 'Relationship Manager'], true)) {
                if ($lead['assigned_to'] != $user['id']) {
                    $stats['not_found']++; continue;
                }
            }

            $oldStatus = $lead['status'];
            $updates = []; $vals = [];
            if ($status && $status !== $oldStatus) { $updates[] = 'status = ?'; $vals[] = $status; }
            if ($remark) { $updates[] = 'remark = ?'; $vals[] = $remark; }
            if (!empty($updates)) {
                $vals[] = $lead['id'];
                $pdo->prepare("UPDATE leads SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?")
                    ->execute($vals);
                $desc = "Bulk feedback: status {$oldStatus}→{$status}" . ($remark ? ", remark: {$remark}" : '');
                $detector->logTimeline((int)$lead['id'], 'Feedback', $desc, (int)$user['id'], $user['name']);
            }
            $stats['updated']++;
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        Response::error('Feedback processing failed: ' . $e->getMessage(), 500);
    }

    Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'Bulk Feedback', "{$stats['updated']} leads updated.");
    Response::success('Feedback processed.', $stats);
}

// --- Single lead JSON update ---
// Accepts: lead_id (preferred) OR phone; plus optional: status, remark, assigned_to
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$leadId = isset($body['lead_id']) ? (int)$body['lead_id'] : 0;
$status = trim($body['status']     ?? '');
$remark = trim($body['remark']     ?? '');
$assignedTo = isset($body['assigned_to']) ? ($body['assigned_to'] === '' || $body['assigned_to'] === null ? null : (int)$body['assigned_to']) : 'SKIP';

// Look up lead
if ($leadId > 0) {
    $stmt = $pdo->prepare("SELECT id, status, assigned_to FROM leads WHERE id = ? LIMIT 1");
    $stmt->execute([$leadId]);
} else {
    $phone = DuplicateDetector::normalizePhone($body['phone'] ?? '');
    if (empty($phone)) Response::error('lead_id or phone is required.');
    $stmt = $pdo->prepare("SELECT id, status, assigned_to FROM leads WHERE phone = ? LIMIT 1");
    $stmt->execute([$phone]);
}
$lead = $stmt->fetch();
if (!$lead) Response::notFound('Lead not found.');

if (in_array($user['role'], ['Caller', 'Relationship Manager'], true)) {
    if ($lead['assigned_to'] != $user['id']) {
        Response::error('Access denied to this lead.', 403);
    }
}

$oldStatus = $lead['status'];
$updates   = [];
$vals      = [];

if ($status && in_array($status, $validStatuses, true)) {
    $updates[] = 'status = ?'; $vals[] = $status;
}
if ($remark !== '') {
    $updates[] = 'remark = ?'; $vals[] = $remark;
}
if ($assignedTo !== 'SKIP') {
    $updates[] = 'assigned_to = ?'; $vals[] = $assignedTo;
}

if (!empty($updates)) {
    $vals[] = $lead['id'];
    $pdo->prepare("UPDATE leads SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?")
        ->execute($vals);

    $log = [];
    if ($status && $status !== $oldStatus)  $log[] = "status {$oldStatus}→{$status}";
    if ($remark !== '')                      $log[] = "remark updated";
    if ($assignedTo !== 'SKIP')              $log[] = "assigned_to changed";

    $detector->logTimeline((int)$lead['id'], 'Status Updated', implode(', ', $log), (int)$user['id'], $user['name']);
}

Response::success('Lead updated successfully.');
