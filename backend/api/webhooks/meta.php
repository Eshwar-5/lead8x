<?php
// backend/api/webhooks/meta.php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/webhook_config.php';
require_once dirname(__DIR__, 2) . '/core/WebhookProcessor.php';
require_once __DIR__ . '/verify.php';

// 1. Handle Verification (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? null;
    $token = (string)($_GET['hub_verify_token'] ?? '');
    $challenge = $_GET['hub_challenge'] ?? null;

    if ($mode === 'subscribe' && hash_equals((string)META_VERIFY_TOKEN, $token)) {
        echo $challenge;
        exit;
    }
    http_response_code(403);
    exit('Invalid verification token');
}

// 2. Handle Payload (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawPayload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    
    // Fail-closed signature verification
    if (empty(META_APP_SECRET)) {
        error_log("CRITICAL: Meta App Secret is not configured.");
        http_response_code(500);
        exit('Internal Server Error');
    }

    if (!WebhookVerifier::verifyMeta($rawPayload, $signature, META_APP_SECRET)) {
        http_response_code(401);
        exit('Unauthorized');
    }

    $data = json_decode($rawPayload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        exit('Invalid JSON');
    }

    $pdo = Database::getConnection();
    $processor = new WebhookProcessor($pdo);
    $logId = $processor->logPayload('meta', $data, $signature);

    try {
        $entries = $data['entry'] ?? [];
        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            foreach ($changes as $change) {
                if (($change['field'] ?? '') === 'leadgen') {
                    $val = $change['value'] ?? [];
                    if (!isset($val['leadgen_id'], $val['form_id'], $val['page_id'])) {
                        error_log("Meta Webhook Error: Missing required leadgen fields in payload.");
                        continue;
                    }

                    $leadId = (string)$val['leadgen_id'];
                    $formId = (string)$val['form_id'];
                    $pageId = (string)$val['page_id'];
                    $adId   = $val['ad_id'] ?? null;

                    // Fetch full lead data from Graph API
                    $details = fetchMetaLeadDetails($leadId);
                    
                    if (empty($details)) {
                        error_log("Meta Webhook: Failed to fetch lead details for leadgen_id: $leadId. Skipping.");
                        continue;
                    }

                    // Safe name concatenation
                    $firstName = trim((string)($details['first_name'] ?? ''));
                    $lastName  = trim((string)($details['last_name'] ?? ''));
                    $fullName  = trim($firstName . ' ' . $lastName);

                    $normalized = [
                        'platform_lead_id' => $leadId,
                        'form_id'          => $formId,
                        'ad_id'            => $adId,
                        'phone'            => $details['phone'] ?? $details['phone_number'] ?? '',
                        'name'             => $fullName ?: ($details['full_name'] ?? ''),
                        'email'            => $details['email'] ?? '',
                        'campaign'         => $details['campaign_name'] ?? '',
                        'project'          => 'Meta Lead Ads'
                    ];

                    $processor->processLead($logId, $normalized, 'Facebook Ads');
                }
            }
        }
        echo "OK";
    } catch (Throwable $e) {
        error_log("Meta Webhook Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        http_response_code(500);
        exit("Internal Server Error");
    }
}

/**
 * Fetch lead details from Meta Graph API
 */
function fetchMetaLeadDetails(string $leadgenId): array {
    $url = "https://graph.facebook.com/v19.0/{$leadgenId}?access_token=" . META_GRAPH_TOKEN;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("Meta Graph API Curl Error: $curlErr");
        return [];
    }

    if ($httpCode >= 400) {
        $sanitized = preg_replace('/"(access_token|id|email|phone[^"]*)":\s*"[^"]*"/i', '"$1":"***"', $response);
        $sanitized = substr(str_replace(["\r", "\n"], ' ', $sanitized), 0, 250);
        error_log("Meta Graph API HTTP Error: $httpCode. Response: $sanitized");
        return [];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Meta Graph API JSON Error: " . json_last_error_msg());
        return [];
    }

    $result = [];
    if (isset($data['field_data']) && is_array($data['field_data'])) {
        foreach ($data['field_data'] as $field) {
            $name = $field['name'] ?? '';
            $val  = $field['values'][0] ?? '';
            if ($name) $result[$name] = $val;
        }
    }
    return $result;
}
