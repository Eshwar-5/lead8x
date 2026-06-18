<?php
// backend/api/webhooks/logs.php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Response::setCorsHeaders();
$user = Auth::requireAuth(['Admin']);
$pdo  = Database::getConnection();

const MAX_LIMIT = 100;
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(1, min(MAX_LIMIT, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$status   = $_GET['status'] ?? '';
$platform = $_GET['platform'] ?? '';

$where = [];
$params = [];

if ($status) {
    $where[] = "status = ?";
    $params[] = $status;
}
if ($platform) {
    $where[] = "platform = ?";
    $params[] = $platform;
}

$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Get total count
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM webhook_log $whereSql");
$stmtCount->execute($params);
$total = (int)$stmtCount->fetchColumn();

// Get logs
$stmt = $pdo->prepare("SELECT * FROM webhook_log $whereSql ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

Response::success('Webhook logs retrieved.', [
    'logs'  => $logs,
    'total' => $total,
    'page'  => $page,
    'total_pages' => ceil($total / $limit)
]);
