<?php
// backend/api/webhooks/linkedin.php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/webhook_config.php';
require_once dirname(__DIR__, 2) . '/core/WebhookProcessor.php';
require_once __DIR__ . '/verify.php';

// 1. Guard against non-POST methods
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method Not Allowed');
}

$rawPayload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LI_SIGNATURE_256'] ?? $_SERVER['HTTP_X_LI_SIGNATURE'] ?? '';
if (strpos($signature, 'hmacsha256=') === 0) {
    $signature = substr($signature, 11);
}

// 2. Verify Signature
if (!WebhookVerifier::verifyLinkedIn($rawPayload, $signature, LINKEDIN_WEBHOOK_SECRET)) {
    http_response_code(401);
    exit('Unauthorized');
}

// 3. JSON Validation
$data = json_decode($rawPayload, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    exit('Invalid JSON');
}

$pdo = Database::getConnection();
$processor = new WebhookProcessor($pdo);
$logId = $processor->logPayload('linkedin', $data, $signature);

try {
    // 4. LinkedIn Lead Gen data mapping
    $urn = null;
    $apiData = [];
    $answersSrc = [];
    
    if (isset($data['leadGenFormResponse'])) {
        if (is_string($data['leadGenFormResponse'])) {
            $urn = $data['leadGenFormResponse'];
        } elseif (is_array($data['leadGenFormResponse']) && isset($data['leadGenFormResponse']['answers'])) {
            $answersSrc = $data['leadGenFormResponse']['answers'];
        }
    } elseif (isset($data['adFormResponseUrn']) && is_string($data['adFormResponseUrn'])) {
        $urn = $data['adFormResponseUrn'];
    }

    if ($urn && function_exists('fetchLinkedInLeadDetails')) {
        $apiData = fetchLinkedInLeadDetails($urn);
    }
    
    // Answers extraction fallback
    $nestedAnswers = [];
    if (empty($answersSrc) && !empty($data['elements'][0]['formResponse'])) {
        $answersSrc = $data['elements'][0]['formResponse'];
    }
    
    if (is_array($answersSrc)) {
        foreach ($answersSrc as $ans) {
            $key = $ans['questionName'] ?? $ans['field'] ?? '';
            if ($key) $nestedAnswers[$key] = $ans['answer'] ?? '';
        }
    }

    $leadId = $urn ?? $data['leadgen_id'] ?? $data['id'] ?? null;
    $formId = $data['form_id'] ?? $data['formId'] ?? null;

    $firstName = $apiData['FIRST_NAME'] ?? $apiData['first_name'] ?? $nestedAnswers['FIRST_NAME'] ?? $nestedAnswers['first_name'] ?? $data['firstName'] ?? '';
    $lastName  = $apiData['LAST_NAME'] ?? $apiData['last_name'] ?? $nestedAnswers['LAST_NAME'] ?? $nestedAnswers['last_name'] ?? $data['lastName'] ?? '';
    $email     = $apiData['EMAIL'] ?? $apiData['email'] ?? $nestedAnswers['EMAIL'] ?? $nestedAnswers['email'] ?? $data['email'] ?? '';
    $phone     = $apiData['PHONE'] ?? $apiData['phone'] ?? $nestedAnswers['PHONE'] ?? $nestedAnswers['phone'] ?? $data['phone'] ?? '';

    $normalized = [
        'platform_lead_id' => $leadId,
        'form_id'          => $formId,
        'name'             => trim($firstName . ' ' . $lastName) ?: ($data['name'] ?? ''),
        'email'            => $email,
        'phone'            => $phone,
        'project'          => 'LinkedIn Ads'
    ];

    $processor->processLead($logId, $normalized, 'LinkedIn Ads');
    echo "OK";
} catch (Throwable $e) {
    error_log("LinkedIn Webhook Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    exit('Internal Server Error');
}

function fetchLinkedInLeadDetails(string $urn): array {
    $token = getenv('LINKEDIN_ACCESS_TOKEN');
    if (!$token) return [];
    
    $url = "https://api.linkedin.com/rest/leadFormResponses/" . urlencode($urn);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $token,
        "LinkedIn-Version: 2024-01"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400 || !$response) return [];
    
    $resData = json_decode($response, true);
    if (!is_array($resData)) return [];

    $result = [];
    if (isset($resData['answers']) && is_array($resData['answers'])) {
        foreach ($resData['answers'] as $ans) {
            $key = $ans['questionName'] ?? '';
            $result[$key] = $ans['answer'] ?? '';
        }
    }
    return $result;
}
