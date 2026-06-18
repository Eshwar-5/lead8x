<?php
// backend/api/webhooks/google_sheets.php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/WebhookProcessor.php';
require_once dirname(__DIR__, 2) . '/utils/Encryption.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method Not Allowed');
}

$rawPayload = file_get_contents('php://input');
$data = json_decode($rawPayload, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    exit('Invalid JSON');
}

// 1. Initial Logging & Setup
try {
    $pdo = Database::getConnection();
    $processor = new WebhookProcessor($pdo);
    $logId = $processor->logPayload('google_sheets', $data, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? 'Token Auth');
} catch (Throwable $e) {
    error_log("Webhook Initial Error: " . $e->getMessage());
    http_response_code(500);
    exit("Server Error: Unable to log payload");
}

try {
    // 2. Authenticate using Security Token (Check Header or Payload)
    $providedToken = $_SERVER['HTTP_X_SECURITY_TOKEN'] ?? 
                     $_SERVER['HTTP_AUTHORIZATION'] ?? 
                     $data['security_token'] ?? 
                     '';
    
    // Normalize token if it came from Authorization: Bearer header
    if (stripos($providedToken, 'Bearer ') === 0) {
        $providedToken = trim(substr($providedToken, 7));
    }
    
    unset($data['security_token']); // Remove token from internal data log

    // Check if any source matches this token
    $stmt = $pdo->prepare("SELECT * FROM webhook_sources WHERE platform = 'google' AND is_active = 1");
    $stmt->execute();
    $sources = $stmt->fetchAll();

    $matchedSource = null;
    foreach ($sources as $source) {
        if (isset($source['verify_token']) && hash_equals((string)$source['verify_token'], (string)$providedToken)) {
            $matchedSource = $source;
            break;
        }
    }

    if (!$matchedSource) {
        $processor->updateLogStatus($logId, 'failed', null, "Invalid Security Token");
        http_response_code(401);
        exit('Unauthorized: Invalid security token');
    }

    // 3. Smart Mapping
    // We try to find common headers in the flat JSON from Sheets
    $normalized = [
        'name'    => '',
        'phone'   => '',
        'email'   => '',
        'project' => $data['Project'] ?? $data['project'] ?? 'Google Sheet Import',
        'source'  => 'Google Sheets'
    ];

    foreach ($data as $key => $value) {
        $cleanKey = strtolower(trim((string)$key));
        $val = trim((string)$value);
        if (empty($val)) continue;

        // Name Mapping
        if (in_array($cleanKey, ['full name', 'name', 'customer name', 'lead name', 'client'])) {
            $normalized['name'] = $val;
        }
        // Phone Mapping
        elseif (in_array($cleanKey, ['phone', 'mobile', 'contact', 'phone number', 'number', 'mobile number'])) {
            $normalized['phone'] = $val;
        }
        // Email Mapping
        elseif (in_array($cleanKey, ['email', 'email id', 'email address'])) {
            $normalized['email'] = $val;
        }
        // Project/City Mapping
        elseif (in_array($cleanKey, ['project', 'property', 'location', 'city', 'hidden field'])) {
            $normalized['project'] = $val;
        }
        // Device/Platform Info
        elseif (in_array($cleanKey, ['device', 'platform', 'os', 'browser'])) {
            $normalized['device'] = $val;
        }
        // Country/IP
        // Country/IP Mapping (Compliance: Anonymize or mask PII)
        elseif (in_array($cleanKey, ['country', 'region', 'nationality'])) {
            $normalized['country'] = $val;
        }
        elseif (in_array($cleanKey, ['ip', 'ip_address', 'ip address'])) {
            /** 
             * PRIVACY COMPLIANCE: IPs are PII. We only assign the raw value here; 
             * WebhookProcessor will anonymize it before storage.
             * Retention Policy: Leads are stored for 365 days by default.
             */
            $normalized['ip_address'] = $val;
        }
        // Consent Mapping
        elseif (in_array($cleanKey, ['consent', 'user_consent', 'consent_given', 'opt_in'])) {
            $normalized['has_user_consent'] = (in_array(strtolower($val), ['yes', 'true', '1', 'on'])) ? 1 : 0;
        }
        // NRI Mapping
        elseif (in_array($cleanKey, ['nri', 'is nri', 'is_nri', 'lead_nri', 'lead nri'])) {
            $nriVal = strtolower($val);
            $normalized['is_nri'] = in_array($nriVal, ['yes', 'true', '1', 'on', 'nri', 'y']) ? 1 : 0;
        }
        // UTM/URL
        elseif (in_array($cleanKey, ['url', 'refer_url', 'source_url', 'page url', 'refer url'])) {
            $normalized['refer_url'] = $val;
        }
        // Date Handling (Robust Parsing V4: handles ISO 8601 with ms and Z timezone)
        elseif (in_array($cleanKey, ['date', 'time', 'timestamp', 'created at', 'created time', 'datetime', 'submission time'])) {
            $formats = [
                'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y',
                'Y-m-d H:i:s', 'Y-m-d', 'm/d/Y',
                'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP'
            ];
            $parsedDate = null;
            // Try explicit formats first
            foreach ($formats as $fmt) {
                $dt = DateTime::createFromFormat($fmt, $val);
                $errors = DateTime::getLastErrors();
                if ($dt !== false && is_array($errors) && $errors['error_count'] === 0) {
                    $parsedDate = $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d H:i:s');
                    break;
                }
            }
            // Fallback: native DateTime handles ISO 8601 variants like 2025-06-08T16:33:31.000Z
            if (!$parsedDate) {
                try {
                    $dt = new DateTime($val, new DateTimeZone('UTC'));
                    $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
                    $parsedDate = $dt->format('Y-m-d H:i:s');
                } catch (Throwable $ex) {
                    $parsedDate = null;
                }
            }
            if ($parsedDate) {
                // Keep the exact parsed timestamp for audit and original submission time
                $normalized['submitted_at'] = $parsedDate;
                // Strip time for creation consistency if needed, but retaining real time usually preferred
                $normalized['created_at'] = substr($parsedDate, 0, 10) . ' 00:00:00';
            } else {
                error_log("Google Sheets Webhook: Unable to parse date '{$val}'.");
            }
        }
    }

    if (empty($normalized['phone'])) {
        throw new Exception("Missing required field: Phone. Ensure your sheet has a 'Phone' or 'Number' column.");
    }

    // 4. Process Lead
    $processor->processLead($logId, $normalized, 'Google Sheets');
    echo "OK";

} catch (Throwable $e) {
    if (isset($processor) && isset($logId)) {
        $processor->updateLogStatus($logId, 'failed', null, $e->getMessage());
    }
    error_log("Google Sheets Webhook Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    exit("Internal Server Error");
}
