<?php
// backend/core/WebhookProcessor.php

declare(strict_types=1);

require_once __DIR__ . '/DuplicateDetector.php';

class WebhookProcessor
{
    private PDO $pdo;
    private DuplicateDetector $detector;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->detector = new DuplicateDetector($pdo);
    }

    /**
     * Log a raw webhook payload
     */
    public function logPayload(string $platform, array $payload, ?string $signature): int
    {
        // Handle encoding errors
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Webhook JSON Encode Error: " . json_last_error_msg());
            $encodedPayload = json_encode([
                'error' => 'Encoding failed',
                'msg'   => json_last_error_msg(),
                'platform' => $platform
            ]);
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO webhook_log (platform, raw_payload, signature, status, created_at)
             VALUES (?, ?, ?, 'received', NOW())"
        );
        $stmt->execute([
            $platform,
            $encodedPayload,
            $signature
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Process a normalized lead from any platform
     */
    public function processLead(int $logId, array $leadData, string $platformName): array
    {
        try {
            // 1. Generate Batch ID
            $batchId = DuplicateDetector::generateBatchId($platformName);

            // 2. Data Minimization & Privacy Hardening
            // Anonymize IP Address (mask host portion)
            $ipAddress = $leadData['ip_address'] ?? null;
            if ($ipAddress) {
                if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ipAddress = preg_replace('/\.\d+$/', '.0', $ipAddress);
                } elseif (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    // IPv6: Mask the interface ID (keep first 8 bytes aka /64)
                    $binIp = inet_pton($ipAddress);
                    if ($binIp !== false) {
                        $maskedBin = substr($binIp, 0, 8) . str_repeat("\x00", 8);
                        $ipAddress = inet_ntop($maskedBin);
                    } else {
                        $ipAddress = null;
                    }
                }
            }

            // Clean Refer URL (strip query parameters and fragments)
            $referUrl = $leadData['refer_url'] ?? null;
            if ($referUrl) {
                $urlParts = parse_url($referUrl);
                if ($urlParts && isset($urlParts['host'])) {
                    $scheme   = isset($urlParts['scheme']) ? $urlParts['scheme'] . '://' : '//';
                    $referUrl = $scheme . $urlParts['host'] . ($urlParts['path'] ?? '');
                } else {
                    $referUrl = null; // Don't build malformed "http://" URLs
                }
            }

            // Calculate Retention Date (Default: 365 days)
            $retentionDays = 365;
            $createdAt = $leadData['created_at'] ?? date('Y-m-d H:i:s');
            $retentionDate = date('Y-m-d H:i:s', strtotime($createdAt . " + $retentionDays days"));

            // Extract Consent
            $hasUserConsent = isset($leadData['has_user_consent']) ? (int)$leadData['has_user_consent'] : 0;

            // Normalized Device
            $rawDevice = trim((string)($leadData['device'] ?? ''));
            if ($rawDevice !== '') {
                $dev = preg_replace('/\s*(?:\||\bon\b|\-|\/)\s*/i', ' / ', $rawDevice);
                $dev = ucwords(strtolower($dev));
                $dev = str_ireplace(['iphone', 'ipad', 'macos', 'ios'], ['iPhone', 'iPad', 'macOS', 'iOS'], $dev);
                $device = $dev;
            } else {
                $device = 'Unknown Device';
            }

            // 3. Prepare row for DuplicateDetector
            $row = [
                'phone'            => $leadData['phone']      ?? '',
                'name'             => $leadData['name']       ?? '',
                'email'            => $leadData['email']      ?? '',
                'source'           => $platformName,
                'project'          => $leadData['project']    ?? 'AUTO_IMPORT',
                'campaign'         => $leadData['campaign']   ?? '',
                'device'           => $device,
                'country'          => $leadData['country']    ?? null,
                'ip_address'       => $ipAddress,
                'refer_url'        => $referUrl,
                'has_user_consent' => $hasUserConsent,
                'retention_date'   => $retentionDate,
                'created_at'       => $createdAt,
                'is_nri'           => $leadData['is_nri']     ?? 0
            ];

            // 3. Process via existing detector
            $result = $this->detector->processLead($row, $batchId, 0);

            // Guard result
            $leadId = isset($result['lead_id']) ? (int)$result['lead_id'] : 0;
            $action = $result['action'] ?? 'error';

            // 4. Update lead with platform-specific IDs if available
            if ($leadId > 0) {
                $stmt = $this->pdo->prepare(
                    "UPDATE leads SET
                        platform_lead_id = ?,
                        ad_id = ?,
                        form_id = ?,
                        campaign_id = ?,
                        auto_imported = 1
                     WHERE id = ?"
                );
                $stmt->execute([
                    $leadData['platform_lead_id'] ?? null,
                    $leadData['ad_id']            ?? null,
                    $leadData['form_id']          ?? null,
                    $leadData['campaign_id']      ?? null,
                    $leadId
                ]);
            }

            // 5. Update webhook log status
            $status = ($action === 'duplicate') ? 'duplicate' : 'processed';
            $this->updateLogStatus($logId, $status, $leadId ?: null);

            return $result;

        } catch (Throwable $e) {
            error_log("Webhook Processing Error: " . $e->getMessage());
            $this->updateLogStatus($logId, 'failed');
            throw $e;
        }
    }

    public function updateLogStatus(int $logId, string $status, ?int $leadId = null, ?string $errorMessage = null): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE webhook_log SET status = ?, lead_id = ?, error_message = ? WHERE id = ?"
        );
        $stmt->execute([$status, $leadId, $errorMessage, $logId]);
    }
}
