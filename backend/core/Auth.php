<?php
// backend/core/Auth.php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/utils/Response.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth
{
    /** Maximum failed login attempts before an account is locked. */
    private const MAX_ATTEMPTS   = 5;

    /** Lockout duration in seconds once the threshold is exceeded. */
    private const LOCKOUT_SECONDS = 900; // 15 minutes

    private static string $secret;
    private static int    $expiry;

    // -----------------------------------------------------------------------
    // Internal bootstrap
    // -----------------------------------------------------------------------
    private static function init(): void
    {
        // Never fall back to a hard-coded secret; fail loudly instead.
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Server misconfiguration: JWT_SECRET is not set.']);
            exit;
        }
        self::$secret = $secret;
        self::$expiry = (int)($_ENV['JWT_EXPIRY'] ?? 28800); // 8 hours default
    }

    // -----------------------------------------------------------------------
    // JWT – Generate token
    // -----------------------------------------------------------------------
    public static function generateToken(array $payload): string
    {
        self::init();
        $now  = time();
        $data = array_merge($payload, [
            'iat' => $now,
            'exp' => $now + self::$expiry,
        ]);
        return JWT::encode($data, self::$secret, 'HS256');
    }

    // -----------------------------------------------------------------------
    // JWT – Decode & validate token
    // -----------------------------------------------------------------------
    public static function decodeToken(string $token): ?array
    {
        self::init();
        try {
            $decoded = JWT::decode($token, new Key(self::$secret, 'HS256'));
            return (array) $decoded;
        } catch (\Throwable) {
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // JWT – Extract Bearer token from Authorization header
    // -----------------------------------------------------------------------
    public static function getBearerToken(): ?string
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? (@apache_request_headers()['Authorization'] ?? null);

        if ($auth && preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            return $matches[1];
        }
        return null;
    }

    // -----------------------------------------------------------------------
    // JWT – Require valid token; optionally enforce roles
    // -----------------------------------------------------------------------
    public static function requireAuth(array $allowedRoles = []): array
    {
        $token = self::getBearerToken();
        if (!$token) {
            Response::unauthorized('Authentication required.');
        }

        $payload = self::decodeToken($token);
        if (!$payload) {
            Response::unauthorized('Invalid or expired token.');
        }

        if (!empty($allowedRoles) && !in_array($payload['role'], $allowedRoles, true)) {
            Response::forbidden('You do not have permission to access this resource.');
        }

        return $payload;
    }

    // -----------------------------------------------------------------------
    // Password – Hash (Argon2id, upgraded from bcrypt)
    // -----------------------------------------------------------------------
    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID);
    }

    // -----------------------------------------------------------------------
    // Password – Verify (works with both legacy bcrypt and new argon2id)
    // -----------------------------------------------------------------------
    public static function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    // -----------------------------------------------------------------------
    // Password – Check if an existing hash should be rehashed to argon2id
    // -----------------------------------------------------------------------
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    // -----------------------------------------------------------------------
    // Rate limiting – Check if an account is currently locked out
    // Returns null if not locked, or a human-readable message if locked.
    // -----------------------------------------------------------------------
    public static function checkRateLimit(PDO $pdo, string $email): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT login_attempts, lockout_until FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row) {
            return null; // Unknown email – handled later as bad credentials
        }

        // Still within lockout window?
        if ($row['lockout_until'] !== null && strtotime($row['lockout_until']) > time()) {
            $remaining = (int)ceil((strtotime($row['lockout_until']) - time()) / 60);
            return "Account temporarily locked. Try again in {$remaining} minute(s).";
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Rate limiting – Record a failed login attempt
    // -----------------------------------------------------------------------
    public static function recordFailedAttempt(PDO $pdo, string $email): void
    {
        $pdo->prepare(
            'UPDATE users
                SET login_attempts = login_attempts + 1,
                    lockout_until  = IF(login_attempts + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? SECOND), lockout_until)
              WHERE email = ?'
        )->execute([self::MAX_ATTEMPTS, self::LOCKOUT_SECONDS, $email]);
    }

    // -----------------------------------------------------------------------
    // Rate limiting – Reset on successful login
    // -----------------------------------------------------------------------
    public static function resetLoginAttempts(PDO $pdo, string $email): void
    {
        $pdo->prepare(
            'UPDATE users SET login_attempts = 0, lockout_until = NULL WHERE email = ?'
        )->execute([$email]);
    }

    // -----------------------------------------------------------------------
    // Email verification – Generate a secure token and store it
    // -----------------------------------------------------------------------
    public static function generateVerificationToken(PDO $pdo, int $userId): string
    {
        $token = bin2hex(random_bytes(32)); // 64-char hex string
        $pdo->prepare(
            'UPDATE users SET email_verification_token = ? WHERE id = ?'
        )->execute([$token, $userId]);
        return $token;
    }

    // -----------------------------------------------------------------------
    // Password reset – Generate a short-lived token and store it
    // -----------------------------------------------------------------------
    public static function generateResetToken(PDO $pdo, int $userId): string
    {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
        $pdo->prepare(
            'UPDATE users SET reset_token = ?, reset_token_expires_at = ? WHERE id = ?'
        )->execute([$token, $expires, $userId]);
        return $token;
    }

    // -----------------------------------------------------------------------
    // Activity logging
    // -----------------------------------------------------------------------
    public static function logActivity(
        PDO     $pdo,
        ?int    $userId,
        ?string $userName,
        string  $action,
        string  $description = ''
    ): void {
        try {
            $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $stmt = $pdo->prepare(
                'INSERT INTO activity_log (user_id, user_name, action, description, ip_address)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $userName, $action, $description, $ip]);
        } catch (\Throwable $e) {
            // Silently fail logging so it doesn't break the main user flow (login/access)
            error_log("Activity Logging Failed: " . $e->getMessage());
        }
    }

    /**
     * Anonymize an email by masking the localized part.
     * e.g., johndoe@example.com -> jo***@example.com
     */
    public static function maskEmail(?string $email): string
    {
        if (!$email || !str_contains($email, '@')) {
            return 'unknown';
        }
        $parts = explode('@', $email);
        $name  = $parts[0];
        $len   = strlen($name);

        if ($len <= 2) {
            $masked = $name . '***';
        } else {
            $masked = substr($name, 0, 2) . '***';
        }

        return $masked . '@' . ($parts[1] ?? 'unknown');
    }
}
