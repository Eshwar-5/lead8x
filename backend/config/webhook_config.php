<?php
// backend/config/webhook_config.php

declare(strict_types=1);

// --- Meta (Facebook/Instagram) Configuration ---
define('META_VERIFY_TOKEN',  getenv('META_VERIFY_TOKEN')  ?: null);
define('META_APP_SECRET',    getenv('META_APP_SECRET')    ?: null);
define('META_GRAPH_TOKEN',   getenv('META_GRAPH_TOKEN')   ?: null);

// --- Google Ads Configuration ---
define('GOOGLE_DEVELOPER_TOKEN', getenv('GOOGLE_DEVELOPER_TOKEN') ?: null);
define('GOOGLE_CLIENT_ID',       getenv('GOOGLE_CLIENT_ID')       ?: null);
define('GOOGLE_CLIENT_SECRET',   getenv('GOOGLE_CLIENT_SECRET')   ?: null);
define('GOOGLE_WEBHOOK_SECRET',  getenv('GOOGLE_WEBHOOK_SECRET')  ?: null);

// --- LinkedIn Configuration ---
define('LINKEDIN_CLIENT_ID',     getenv('LINKEDIN_CLIENT_ID')     ?: null);
define('LINKEDIN_CLIENT_SECRET', getenv('LINKEDIN_CLIENT_SECRET') ?: null);
define('LINKEDIN_WEBHOOK_SECRET',getenv('LINKEDIN_WEBHOOK_SECRET')?: null);

// --- General Notifications ---
define('ADMIN_NOTIFY_EMAIL', getenv('ADMIN_NOTIFY_EMAIL') ?: null);

/**
 * Validate that all required webhook credentials are present.
 * Fails fast if any critical configuration is missing.
 */
function validateWebhookConfig(): void {
    $required = [
        'META_VERIFY_TOKEN', 
        'META_APP_SECRET', 
        'GOOGLE_WEBHOOK_SECRET',
        'LINKEDIN_WEBHOOK_SECRET',
        'ADMIN_NOTIFY_EMAIL'
    ];
    
    foreach ($required as $const) {
        if (!defined($const) || empty(constant($const))) {
            error_log("CRITICAL CONFIG ERROR: Missing definition for $const");
            http_response_code(500);
            exit("Internal configuration error. Please contact admin.");
        }
    }
}
