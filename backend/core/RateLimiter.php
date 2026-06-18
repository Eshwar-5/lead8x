<?php
// backend/core/RateLimiter.php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

class RateLimiter
{
    /**
     * Check if a request is allowed and record the hit.
     * 
     * @param PDO    $pdo
     * @param string $identifier   The IP or Email to track
     * @param string $endpoint     The key for the endpoint (e.g., 'login')
     * @param int    $limit        Max hits allowed
     * @param int    $windowSec    Window duration in seconds
     * @return array ['allowed' => bool, 'hits' => int, 'reset_at' => string, 'remaining' => int]
     */
    public static function check(PDO $pdo, string $identifier, string $endpoint, int $limit, int $windowSec): array
    {
        try {
            $now = date('Y-m-d H:i:s');
            
            // 1. Cleanup expired entries occasionally (10% chance per request)
            if (rand(1, 100) <= 10) {
                $pdo->prepare("DELETE FROM rate_limits WHERE reset_at < ?")->execute([$now]);
            }

            // 2. Look up current entry
            $stmt = $pdo->prepare(
                "SELECT hits, reset_at FROM rate_limits WHERE identifier = ? AND endpoint = ? LIMIT 1"
            );
            $stmt->execute([$identifier, $endpoint]);
            $row = $stmt->fetch();

            if (!$row) {
                // First hit: Initialize
                $resetAt = date('Y-m-d H:i:s', time() + $windowSec);
                $stmt = $pdo->prepare(
                    "INSERT INTO rate_limits (identifier, endpoint, hits, reset_at) VALUES (?, ?, 1, ?)"
                );
                $stmt->execute([$identifier, $endpoint, $resetAt]);
                
                return [
                    'allowed'   => true,
                    'hits'      => 1,
                    'reset_at'  => $resetAt,
                    'remaining' => $limit - 1
                ];
            }

            $hits    = (int)$row['hits'];
            $resetAt = $row['reset_at'];

            // If reset time has passed, start fresh
            if (strtotime($resetAt) <= time()) {
                $newReset = date('Y-m-d H:i:s', time() + $windowSec);
                $pdo->prepare(
                    "UPDATE rate_limits SET hits = 1, reset_at = ?, updated_at = NOW() WHERE identifier = ? AND endpoint = ?"
                )->execute([$newReset, $identifier, $endpoint]);

                return [
                    'allowed'   => true,
                    'hits'      => 1,
                    'reset_at'  => $newReset,
                    'remaining' => $limit - 1
                ];
            }

            // Within window: Increment
            $hits++;
            $pdo->prepare(
                "UPDATE rate_limits SET hits = hits + 1, updated_at = NOW() WHERE identifier = ? AND endpoint = ?"
            )->execute([$identifier, $endpoint]);

            $allowed = $hits <= $limit;
            
            return [
                'allowed'   => $allowed,
                'hits'      => $hits,
                'reset_at'  => $resetAt,
                'remaining' => max(0, $limit - $hits)
            ];
        } catch (\Throwable $e) {
            // If table doesn't exist yet or DB error, fail OPEN so user isn't blocked
            error_log("RateLimiter Error: " . $e->getMessage());
            return [
                'allowed'   => true, 
                'hits'      => 0, 
                'reset_at'  => date('Y-m-d H:i:s'), 
                'remaining' => $limit
            ];
        }
    }

    /**
     * Get the client IP address, handling proxies.
     */
    public static function getIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] 
            ?? $_SERVER['HTTP_CLIENT_IP'] 
            ?? $_SERVER['REMOTE_ADDR'] 
            ?? '0.0.0.0';
    }
}
