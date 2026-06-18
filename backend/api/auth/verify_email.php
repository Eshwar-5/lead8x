<?php
// backend/api/auth/verify_email.php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Response::setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$token = trim($_GET['token'] ?? '');

if (empty($token) || strlen($token) !== 64) {
    Response::error('Invalid verification token.', 400);
}

$pdo = Database::getConnection();

$stmt = $pdo->prepare(
    'SELECT id, name, email_verified_at
       FROM users WHERE email_verification_token = ? LIMIT 1'
);
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    Response::error('Invalid or expired verification token.', 400);
}

if ($user['email_verified_at'] !== null) {
    Response::success('Email address is already verified. You can log in.');
}

// Mark as verified and clear the token
$pdo->prepare(
    'UPDATE users
        SET email_verified_at = NOW(), email_verification_token = NULL
      WHERE id = ?'
)->execute([$user['id']]);

Auth::logActivity($pdo, (int)$user['id'], null, 'Email Verified', 'User verified email address.');

Response::success('Email verified successfully. You can now log in.');
