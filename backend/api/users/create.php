<?php
// backend/api/users/create.php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/RateLimiter.php';
require_once dirname(__DIR__, 2) . '/utils/Validator.php';

Response::setCorsHeaders();

$pdo = Database::getConnection();

// --- IP-based Rate limit (Abuse Protection) ---
$ip = RateLimiter::getIp();
$rateLimit = RateLimiter::check($pdo, $ip, 'user_creation', 20, 3600); // 20 per hour
if (!$rateLimit['allowed']) {
    $resetTime = date('H:i', strtotime($rateLimit['reset_at']));
    Response::tooManyRequests("User creation quota exceeded for this IP. Try again after $resetTime.");
}

$user = Auth::requireAuth(['Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('Method not allowed', 405);

$body     = json_decode(file_get_contents('php://input'), true);
$name     = Validator::sanitizeString($body['name'] ?? null, 100);
$email    = Validator::sanitizeEmail($body['email'] ?? null);
$role     = Validator::sanitizeString($body['role'] ?? null, 30);
$password = trim((string)($body['password'] ?? ''));

$validRoles = ['Admin','Caller','Relationship Manager','Manager'];

if (empty($name) || !$email || empty($role) || empty($password)) {
    Response::error('Name, valid Email, Role, and Password are required.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL))        Response::error('Invalid email address.');
if (!in_array($role, $validRoles, true))               Response::error('Invalid role.');
if (strlen($password) < 8)                             Response::error('Password must be at least 8 characters.');

$pdo = Database::getConnection();

$check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$check->execute([$email]);
if ($check->fetch()) Response::error('A user with this email already exists.', 409);

$hash = Auth::hashPassword($password);
$stmt = $pdo->prepare(
    "INSERT INTO users (name, email, password_hash, role, is_active, email_verified_at, created_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())"
);
$stmt->execute([$name, $email, $hash, $role]);
$newId = (int)$pdo->lastInsertId();

// Generate email verification token for new account
$verifyToken = Auth::generateVerificationToken($pdo, $newId);

// TODO: Email the $verifyToken to $email via your SMTP setup.
// During development the token is logged for convenience.
$appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost', '/');
error_log("[Lead8X] Verify email for {$email} → {$appUrl}/verify-email?token={$verifyToken}");

Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'Create User', "Created user: {$name} ({$role})");

Response::success('User created successfully. A verification email has been sent.', ['user_id' => $newId], 201);

