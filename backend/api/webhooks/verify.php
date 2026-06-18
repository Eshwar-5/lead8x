<?php
// backend/api/webhooks/verify.php

declare(strict_types=1);

class WebhookVerifier
{
    /**
     * Verify Meta X-Hub-Signature-256
     */
    public static function verifyMeta(string $payload, string $signature, ?string $appSecret): bool
    {
        if (empty($appSecret)) {
            error_log("Webhook Verification Error: Meta App Secret is not configured.");
            return false;
        }
        if (empty($signature)) return false;
        
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);
        return hash_equals($expected, $signature);
    }

    /**
     * Verify Google X-Google-Signature
     */
    public static function verifyGoogle(string $payload, string $signature, ?string $secret): bool
    {
        if (empty($secret)) {
            error_log("Webhook Verification Error: Google Webhook Secret is not configured.");
            return false;
        }
        if (empty($signature)) return false;

        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Verify LinkedIn X-LI-Signature-256
     */
    public static function verifyLinkedIn(string $payload, string $signature, ?string $secret): bool
    {
        if (empty($secret)) {
            error_log("Webhook Verification Error: LinkedIn Webhook Secret is not configured.");
            return false;
        }
        if (empty($signature)) return false;

        // LinkedIn signature is base64 encoded HMAC-SHA256
        $expected = base64_encode(hash_hmac('sha256', $payload, $secret, true));
        return hash_equals($expected, $signature);
    }
}
