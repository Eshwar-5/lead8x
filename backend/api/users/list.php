<?php
// backend/api/users/list.php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Response::setCorsHeaders();
$user = Auth::requireAuth(['Admin', 'Manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') Response::error('Method not allowed', 405);

$pdo = Database::getConnection();

$stmt = $pdo->query(
    "SELECT id, name, email, role, is_active, last_login, created_at,
            (SELECT COUNT(*) FROM leads WHERE assigned_to = users.id) AS lead_count
     FROM users ORDER BY role ASC, name ASC"
);

Response::success('OK', ['users' => $stmt->fetchAll()]);
