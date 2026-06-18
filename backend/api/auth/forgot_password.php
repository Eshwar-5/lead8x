<?php
// backend/api/auth/forgot_password.php

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

$pdo = Database::getConnection();

// --- IP-based Rate limit (Abuse Protection) ---
$ip = RateLimiter::getIp();
$rateLimit = RateLimiter::check($pdo, $ip, 'forgot_password', 5, 3600); // 5 attempts per hour
if (!$rateLimit['allowed']) {
    $resetTime = date('H:i', strtotime($rateLimit['reset_at']));
    Response::tooManyRequests("Password reset quota exceeded for this IP. Try again after $resetTime.");
}

$body  = json_decode(file_get_contents('php://input'), true);
$email = Validator::sanitizeEmail($body['email'] ?? null);

if (!$email) {
    Response::error('Valid email address is required.');
}

$stmt = $pdo->prepare('SELECT id, name FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

// Always return success to prevent email enumeration attacks
if (!$user) {
    Response::success('If that email is registered, a reset link has been sent.');
}

// Generate a 1-hour expiring reset token
$token = Auth::generateResetToken($pdo, (int)$user['id']);

// ── Send reset email ──────────────────────────────────────────────────────
// Build the reset URL; configure APP_URL in your .env file.
$appUrl   = rtrim($_ENV['APP_URL'] ?? 'http://localhost', '/');
$resetUrl = "{$appUrl}/reset-password?token={$token}";

// TODO: Replace the block below with your preferred mailer (PHPMailer / SendGrid / etc.)
// For now, we log the reset URL so it can be picked up during development / testing.
error_log("[Lead8X] Password reset for {$email} → {$resetUrl}");

// Example PHPMailer integration (uncomment and configure after installing phpmailer/phpmailer):
// use PHPMailer\PHPMailer\PHPMailer;
// $mail = new PHPMailer(true);
// $mail->isSMTP();
// $mail->Host       = $_ENV['SMTP_HOST']     ?? 'smtp.example.com';
// $mail->SMTPAuth   = true;
// $mail->Username   = $_ENV['SMTP_USER']     ?? '';
// $mail->Password   = $_ENV['SMTP_PASS']     ?? '';
// $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
// $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);
// $mail->setFrom($_ENV['MAIL_FROM'] ?? 'no-reply@lead8x.com', 'Lead8X');
// $mail->addAddress($email, $user['name']);
// $mail->Subject = 'Reset your Lead8X password';
// $mail->Body    = "Click the link below to reset your password (expires in 1 hour):\n\n{$resetUrl}";
// $mail->send();

Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'Password Reset Requested',
    'Reset token generated for ' . ($_SERVER['REMOTE_ADDR'] ?? ''));

Response::success('If that email is registered, a reset link has been sent.');
