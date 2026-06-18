<?php
// backend/api/auth/reset_password.php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

require_once dirname(__DIR__, 2) . '/utils/Validator.php';

Response::setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$body        = json_decode(file_get_contents('php://input'), true);
$token       = Validator::sanitizeString($body['token'] ?? null, 128);
$newPassword = trim((string)($body['new_password'] ?? ''));

if (empty($token) || strlen($token) !== 64) {
    Response::error('Invalid reset token.', 400);
}

if (strlen($newPassword) < 8) {
    Response::error('New password must be at least 8 characters long.', 400);
}

$pdo = Database::getConnection();

$stmt = $pdo->prepare(
    'SELECT id, name, reset_token_expires_at
       FROM users
      WHERE reset_token = ? LIMIT 1'
);
$stmt->execute([$token]);
$user = $stmt->fetch();

// Validate token existence and expiry
if (!$user || $user['reset_token_expires_at'] === null
    || strtotime($user['reset_token_expires_at']) < time()) {
    Response::error('Invalid or expired reset token.', 400);
}

// Hash and save new password; clear the reset token
$hash = Auth::hashPassword($newPassword);
$pdo->prepare(
    'UPDATE users
        SET password_hash          = ?,
            reset_token            = NULL,
            reset_token_expires_at = NULL,
            login_attempts         = 0,
            lockout_until          = NULL
      WHERE id = ?'
)->execute([$hash, $user['id']]);

Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'Password Reset',
    'Password was reset from ' . ($_SERVER['REMOTE_ADDR'] ?? ''));

Response::success('Password has been reset successfully. You can now log in.');
