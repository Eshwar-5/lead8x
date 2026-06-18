<?php
// backend/config/database.php

declare(strict_types=1);

// Load .env from root
$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
        putenv(trim($key) . '=' . trim($value));
    }
}

// Ensure PHP and Database use the same timezone to prevent rate-limit desync
$timezone = $_ENV['TIMEZONE'] ?? 'Asia/Kolkata';
date_default_timezone_set($timezone);

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            // Never fall back to hard-coded credentials; require the .env file.
            $host    = $_ENV['DB_HOST']    ?? 'localhost';
            $db      = $_ENV['DB_NAME']    ?? '';
            $user    = $_ENV['DB_USER']    ?? '';
            $pass    = $_ENV['DB_PASS']    ?? '';
            $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

            $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci, time_zone='+05:30'",
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
                exit;
            }
        }
        return self::$instance;
    }

    private function __construct() {}
    private function __clone() {}
}
