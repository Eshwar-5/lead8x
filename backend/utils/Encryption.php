<?php
// backend/utils/Encryption.php

declare(strict_types=1);

class Encryption
{
    private static string $method = 'aes-256-gcm';

    /**
     * Encrypt a plaintext string using Authenticated Encryption (GCM)
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::getKey();
        
        $ivLength = openssl_cipher_iv_length(self::$method);
        if ($ivLength === false) {
            throw new Exception("Encryption Error: Could not determine IV length.");
        }

        $iv = openssl_random_pseudo_bytes($ivLength);
        if ($iv === false) {
            throw new Exception("Encryption Error: Failed to generate secure IV.");
        }

        // Tag will be populated by openssl_encrypt
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::$method, $key, OPENSSL_RAW_DATA, $iv, $tag);
        
        if ($ciphertext === false) {
            throw new Exception("Encryption Error: Encryption failed.");
        }

        // Return IV + Tag + Ciphertext (all raw bytes concatenated, then base64 encoded)
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a ciphertext string and verify integrity
     */
    public static function decrypt(string $ciphertext): string
    {
        $key = self::getKey();
        $data = base64_decode($ciphertext, true);
        
        if ($data === false) {
            throw new Exception("Decryption Error: Malformed base64 input.");
        }

        $ivLength = openssl_cipher_iv_length(self::$method);
        $tagLength = 16; // Standard GCM tag length

        if (strlen($data) < ($ivLength + $tagLength)) {
            throw new Exception("Decryption Error: Ciphertext is too short.");
        }
        
        $iv        = substr($data, 0, $ivLength);
        $tag       = substr($data, $ivLength, $tagLength);
        $encrypted = substr($data, $ivLength + $tagLength);
        
        $plaintext = openssl_decrypt($encrypted, self::$method, $key, OPENSSL_RAW_DATA, $iv, $tag);
        
        if ($plaintext === false) {
            throw new Exception("Decryption Error: Integrity check failed or invalid key.");
        }

        return $plaintext;
    }

    private static function getKey(): string
    {
        // Should be defined in .env as ENCRYPTION_KEY
        // If not found, we use a fallback for safety but warn in logs.
        $key = getenv('ENCRYPTION_KEY') ?: $_ENV['ENCRYPTION_KEY'] ?? '';
        if (empty($key)) {
            // High risk: fail if key is missing in production
            error_log("CRITICAL: ENCRYPTION_KEY is missing!");
            throw new Exception("ENCRYPTION_KEY is not configured.");
        }
        return hash('sha256', $key, true);
    }
}
