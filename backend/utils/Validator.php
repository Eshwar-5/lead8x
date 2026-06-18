<?php
// backend/utils/Validator.php

declare(strict_types=1);

class Validator
{
    /**
     * Sanitize a string: trim, remove tags, and handle specialized character cleaning.
     */
    public static function sanitizeString(?string $input, int $maxLength = 1000): string
    {
        if ($input === null) return '';
        $clean = trim($input);
        $clean = strip_tags($clean);
        // Limit length to prevent buffer overflow/DoS
        return mb_substr($clean, 0, $maxLength);
    }

    /**
     * Strict Email Validation & Sanitization
     */
    public static function sanitizeEmail(?string $email): ?string
    {
        if ($email === null) return null;
        $clean = trim(strtolower($email));
        $filtered = filter_var($clean, FILTER_VALIDATE_EMAIL);
        return $filtered ?: null;
    }

    /**
     * Strict Phone Number: Extract only digits (allows a single leading + prefix)
     */
    public static function sanitizePhone(?string $phone): string
    {
        if ($phone === null) return '';
        // Strip everything except digits and plus
        $clean = preg_replace('/[^0-9+]/', '', $phone);
        // Only keep '+' if it is at the very beginning
        $startsWithPlus = str_starts_with($clean, '+');
        $digitsOnly = preg_replace('/[^0-9]/', '', $clean);
        $final = ($startsWithPlus ? '+' : '') . $digitsOnly;
        
        return mb_substr($final, 0, 20);
    }

    /**
     * Force input to Integer with numeric verification
     */
    public static function asInt(mixed $input, int $default = 0): int
    {
        if ($input === null || !is_scalar($input) || !is_numeric($input)) {
            return $default;
        }
        return (int)$input;
    }

    /**
     * Validate and sanitize a URL (e.g. for Refer URL) with scheme whitelist
     */
    public static function sanitizeUrl(?string $url): string
    {
        if ($url === null) return '';
        $clean = trim(strip_tags($url));
        if ($clean === '') return '';

        // Reject protocol-relative URLs
        if (str_starts_with($clean, '//')) return '';

        $parts = parse_url($clean);
        if ($parts === false) return '';

        if (isset($parts['scheme'])) {
            $scheme = strtolower($parts['scheme']);
            $allowed = ['http', 'https', 'mailto'];
            if (!in_array($scheme, $allowed, true)) return '';
        } else {
            // If no scheme, ensure it's not a disguised protocol (e.g. "javascript:...")
            // A colon before any slash often indicates a scheme
            $firstColon = strpos($clean, ':');
            $firstSlash = strpos($clean, '/');
            if ($firstColon !== false && ($firstSlash === false || $firstColon < $firstSlash)) {
                return '';
            }
        }

        return $clean;
    }

    /**
     * Encode data for safe output (XSS Prevention) with Recursive Object Support
     */
    public static function escapeHtml(mixed $data): mixed
    {
        if (is_array($data)) {
            return array_map([self::class, 'escapeHtml'], $data);
        }
        if (is_object($data)) {
            $clone = clone $data;
            foreach ($clone as $k => $v) {
                $clone->$k = self::escapeHtml($v);
            }
            return $clone;
        }
        if (is_string($data)) {
            return htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return $data;
    }

    /**
     * Validate File Upload (MIME type check) with defensive key checks
     */
    public static function isValidUpload(mixed $file, array $allowedMimes, int $maxBytes = 10485760): bool
    {
        if (!is_array($file) || !isset($file['error'], $file['size'], $file['tmp_name'])) {
            return false;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) return false;
        if ($file['size'] > $maxBytes) return false;

        $tmpPath = $file['tmp_name'];
        if (!is_string($tmpPath) || !is_file($tmpPath) || !is_uploaded_file($tmpPath)) {
            return false;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);

        if ($mime === false) return false;

        return in_array($mime, $allowedMimes, true);
    }
}
