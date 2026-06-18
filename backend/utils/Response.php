<?php
// backend/utils/Response.php

declare(strict_types=1);


class Response
{
    /** @return void */
    public static function json(bool $success, string $message, mixed $data = null, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        $payload = ['success' => $success, 'message' => $message];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /** @return void */
    public static function success(string $message = 'OK', mixed $data = null, int $code = 200): void
    {
        self::json(true, $message, $data, $code);
        exit;
    }

    /** @return void */
    public static function error(string $message = 'Error', int $code = 400, mixed $data = null): void
    {
        // Log security-related errors for traffic monitoring
        if (in_array($code, [401, 403, 429], true)) {
            $cleanMsg = str_replace(["\r", "\n"], ' ', $message);
            $cleanMsg = preg_replace('/[\x00-\x1F\x7F]/', '', $cleanMsg);
            $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $uri      = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'unknown';
            $method   = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
            error_log("[Security] Code $code: $cleanMsg | IP: $ip | URI: $method $uri");
        }
        self::json(false, $message, $data, $code);
        exit;
    }

    /** @return void */
    public static function unauthorized(string $message = 'Unauthorized'): void
    {
        self::error($message, 401);
        exit;
    }

    /** @return void */
    public static function forbidden(string $message = 'Forbidden'): void
    {
        self::error($message, 403);
        exit;
    }

    /** @return void */
    public static function notFound(string $message = 'Not Found'): void
    {
        self::error($message, 404);
        exit;
    }

    /** @return void */
    public static function tooManyRequests(string $message = 'Too many requests. Please try again later.'): void
    {
        self::error($message, 429);
        exit;
    }

    public static function setCorsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
}
