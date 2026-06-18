<?php
// backend/api/admin/activity-log.php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Response::setCorsHeaders();
$user = Auth::requireAuth(['Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') Response::error('Method not allowed', 405);

$pdo    = Database::getConnection();
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(100, max(20, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$total = (int)$pdo->query("SELECT COUNT(*) FROM activity_log")->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT id, user_id, user_name, action, description, ip_address, created_at
     FROM activity_log
     ORDER BY created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute([$limit, $offset]);

Response::success('OK', [
    'logs'        => $stmt->fetchAll(),
    'total'       => $total,
    'page'        => $page,
    'total_pages' => (int)ceil($total / $limit),
]);
