<?php
// backend/api/auth/login.php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/RateLimiter.php';
require_once dirname(__DIR__, 2) . '/utils/Validator.php';

Response::setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$body     = json_decode(file_get_contents('php://input'), true);
$email    = Validator::sanitizeEmail($body['email'] ?? null);
$password = trim((string)($body['password'] ?? ''));

if (!$email || empty($password)) {
    Response::error('Valid email and password are required.');
}

$pdo = Database::getConnection();

// ── 0. IP-based Rate-limit (Abuse Protection) ──────────────────────────────
$ip = RateLimiter::getIp();
$rateLimit = RateLimiter::check($pdo, $ip, 'login', 10, 900); // 10 attempts per 15 mins
if (!$rateLimit['allowed']) {
    $resetTime = date('H:i', strtotime($rateLimit['reset_at']));
    Response::tooManyRequests("Too many attempts from this IP. Please try again after $resetTime.");
}

// ── 1. Rate-limit check (Account-based) ────────────────────────────────────
$lockMessage = Auth::checkRateLimit($pdo, $email);
if ($lockMessage !== null) {
    $masked = Auth::maskEmail($email);
    Auth::logActivity($pdo, null, 'unknown', 'Rate Limit Hit', "IP: {$_SERVER['REMOTE_ADDR']} hit rate limit for {$masked}: {$lockMessage}");
    Response::error($lockMessage, 429);
}

// ── 2. Fetch user ──────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT id, name, email, password_hash, role, is_active, email_verified_at
       FROM users WHERE email = ? LIMIT 1'
);
$stmt->execute([$email]);
$user = $stmt->fetch();

// ── 3. Verify credentials ──────────────────────────────────────────────────
if (!$user || !Auth::verifyPassword($password, $user['password_hash'])) {
    // Record the failure (operates safely even if $user is false)
    if ($user) {
        Auth::recordFailedAttempt($pdo, $email);
        $masked = Auth::maskEmail($email);
        Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'Login Failed', "Incorrect password for {$masked} from {$_SERVER['REMOTE_ADDR']}");
    } else {
        $masked = Auth::maskEmail($email);
        Auth::logActivity($pdo, null, 'unknown', 'Login Failed', "Attempt with non-existent email {$masked} from {$_SERVER['REMOTE_ADDR']}");
    }
    // Use a vague message to avoid disclosing whether the email exists
    Response::error('Invalid email or password.', 401);
}

// ── 4. Account active? ─────────────────────────────────────────────────────
if (!(bool)$user['is_active']) {
    Response::error('Your account has been deactivated. Contact admin.', 403);
}

// ── 5. Email verified? ─────────────────────────────────────────────────────
if ($user['email_verified_at'] === null) {
    Response::error('Please verify your email address before logging in.', 403);
}

// ── 6. Successful login: reset rate limit ──────────────────────────────────
Auth::resetLoginAttempts($pdo, $email);

// ── 7. Opportunistic hash upgrade (bcrypt → argon2id) ─────────────────────
if (Auth::needsRehash($user['password_hash'])) {
    $newHash = Auth::hashPassword($password);
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([$newHash, $user['id']]);
}

// ── 8. Update last login timestamp ────────────────────────────────────────
$pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
    ->execute([$user['id']]);

// ── 9. Issue JWT ───────────────────────────────────────────────────────────
$token = Auth::generateToken([
    'id'    => $user['id'],
    'name'  => $user['name'],
    'email' => $user['email'],
    'role'  => $user['role'],
]);

// ── 10. Log activity ───────────────────────────────────────────────────────
Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'Login',
    'User logged in from ' . ($_SERVER['REMOTE_ADDR'] ?? ''));

Response::success('Login successful.', [
    'token' => $token,
    'user'  => [
        'id'    => $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ],
]);
