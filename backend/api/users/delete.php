<?php
// backend/api/users/delete.php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Response::setCorsHeaders();
$authUser = Auth::requireAuth(['Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') Response::error('Method not allowed', 405);

$body     = json_decode(file_get_contents('php://input'), true);
$targetId = (int)($body['id'] ?? $_GET['id'] ?? 0);

if (!$targetId) Response::error('User ID is required.');
if ($targetId === (int)$authUser['id']) Response::error('You cannot delete your own account.');

$pdo = Database::getConnection();
$stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$targetId]);
$target = $stmt->fetch();
if (!$target) Response::notFound('User not found.');

// Soft-delete: deactivate instead
$pdo->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ?")->execute([$targetId]);

Auth::logActivity($pdo, (int)$authUser['id'], $authUser['name'], 'Deactivate User',
    "Deactivated user: {$target['name']} (ID: {$targetId})");

Response::success('User deactivated successfully.');
