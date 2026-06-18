<?php
// backend/tests/test_encryption_integrity.php

require_once __DIR__ . '/../utils/Encryption.php';

// Mock getenv to provide a test key
putenv("ENCRYPTION_KEY=test_secret_key_123");

function runTest(string $label, callable $test) {
    echo "Testing $label: ";
    try {
        $test();
        echo "PASSED\n";
    } catch (Throwable $e) {
        echo "FAILED - " . $e->getMessage() . "\n";
    }
}

$plaintext = "Sensitive data 123";

runTest("Basic Encrypt/Decrypt", function() use ($plaintext) {
    $encrypted = Encryption::encrypt($plaintext);
    $decrypted = Encryption::decrypt($encrypted);
    if ($plaintext !== $decrypted) throw new Exception("Data mismatch!");
});

runTest("Tamper Integrity Check", function() use ($plaintext) {
    $encrypted = Encryption::encrypt($plaintext);
    $raw = base64_decode($encrypted);
    
    // Tamper with one byte in the ciphertext part
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    
    $tampered = base64_encode($raw);
    
    try {
        Encryption::decrypt($tampered);
        throw new Exception("Decryption should have failed due to tampering!");
    } catch (Exception $e) {
        if (strpos($e->getMessage(), "Integrity check failed") === false) {
            throw new Exception("Wrong error message: " . $e->getMessage());
        }
        // Success: Integrity check caught the tampering
    }
});

runTest("Invalid Base64", function() {
    try {
        Encryption::decrypt("!!!NotBase64!!!");
        throw new Exception("Decryption should have failed for invalid base64!");
    } catch (Exception $e) {
        if (strpos($e->getMessage(), "Malformed base64") === false) {
            throw new Exception("Wrong error message: " . $e->getMessage());
        }
    }
});

runTest("Truncated Data", function() {
    try {
        Encryption::decrypt(base64_encode("too-short"));
        throw new Exception("Decryption should have failed for short data!");
    } catch (Exception $e) {
        if (strpos($e->getMessage(), "too short") === false) {
            throw new Exception("Wrong error message: " . $e->getMessage());
        }
    }
});
