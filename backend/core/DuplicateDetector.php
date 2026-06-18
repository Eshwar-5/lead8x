<?php
// backend/core/DuplicateDetector.php

declare(strict_types=1);

class DuplicateDetector
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Process a single lead row:
     * - If phone not found → INSERT as new lead
     * - If phone exists → increment duplicate_count, add lead_source entry
     *
     * Returns: ['action' => 'new'|'duplicate', 'lead_id' => int]
     */
    public function processLead(array $row, string $batchId, int $uploadedBy): array
    {
        $phone    = $this->normalizePhone($row['phone'] ?? '');
        $name     = trim($row['name']      ?? '');
        $email    = trim($row['email']     ?? '');
        $source   = trim($row['source']    ?? '');
        $campaign = trim($row['campaign']  ?? '');
        $city     = trim($row['city']      ?? '');
        $project  = trim($row['project']   ?? '');
        $entryId  = trim((string)($row['entry_id']   ?? '')) ?: null;
        $referUrl = trim((string)($row['refer_url']  ?? '')) ?: null;
        $ipAddr   = trim((string)($row['ip_address'] ?? '')) ?: null;
        $country  = trim((string)($row['country']    ?? '')) ?: null;
        $device   = $row['device'] ?? null;
        $isNri    = isset($row['is_nri']) ? (int)$row['is_nri'] : 0;

        if (empty($phone)) {
            return ['action' => 'skipped', 'lead_id' => 0];
        }

        // Check for existing lead
        $stmt = $this->pdo->prepare("SELECT id, duplicate_count FROM leads WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
        $existing = $stmt->fetch();

        if ($existing) {
            // --- DUPLICATE ---
            $newCount = (int)$existing['duplicate_count'] + 1;
            $this->pdo->prepare(
                "UPDATE leads SET duplicate_count = ?, is_duplicate = 1, updated_at = NOW() WHERE id = ?"
            )->execute([$newCount, $existing['id']]);

            $this->addLeadSource($existing['id'], $source, $campaign, $batchId, $uploadedBy);
            $this->logTimeline($existing['id'], 'Uploaded', "Duplicate upload from batch {$batchId}", $uploadedBy, null);

            return ['action' => 'duplicate', 'lead_id' => $existing['id']];
        } else {
        // --- NEW LEAD ---
        $createdAt      = $row['created_at']      ?? date('Y-m-d H:i:s');
        $hasUserConsent = (int)($row['has_user_consent'] ?? 0);
        
        // Validate retention_date format
        $retentionDate = $row['retention_date'] ?? null;
        if ($retentionDate) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $retentionDate);
            if (!$dt || $dt->format('Y-m-d H:i:s') !== $retentionDate) {
                $retentionDate = null; // Invalidate malformed date
            }
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO leads
                (phone, name, email, city, project, entry_id, refer_url, ip_address, country, device, is_nri,
                 first_source, first_batch_id, status, has_user_consent, retention_date, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'New', ?, ?, ?)"
        );
        $stmt->execute([
            $phone,
            $name    ?: null,
            $email   ?: null,
            $city    ?: null,
            $project ?: null,
            $entryId,
            $referUrl,
            $ipAddr,
            $country,
            $device,
            $isNri,
            $source  ?: null,
            $batchId,
            $hasUserConsent,
            $retentionDate,
            $createdAt
        ]);
            $leadId = (int)$this->pdo->lastInsertId();

            $this->addLeadSource($leadId, $source, $campaign, $batchId, $uploadedBy);
            $this->logTimeline($leadId, 'Uploaded', "New lead uploaded via batch {$batchId}", $uploadedBy, null);

            return ['action' => 'new', 'lead_id' => $leadId];
        }
    }

    public function addLeadSource(int $leadId, string $sourceName, string $campaign, string $batchId, int $uploadedBy): void
    {
        $this->pdo->prepare(
            "INSERT INTO lead_sources (lead_id, source_name, campaign, batch_id, uploaded_by, uploaded_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        )->execute([$leadId, $sourceName ?: null, $campaign ?: null, $batchId, $uploadedBy ?: null]);
    }

    public function logTimeline(int $leadId, string $eventType, string $description, ?int $actorId, ?string $actorName): void
    {
        $this->pdo->prepare(
            "INSERT INTO lead_timeline (lead_id, event_type, description, actor_id, actor_name, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        )->execute([$leadId, $eventType, $description, $actorId, $actorName]);
    }

    /**
     * Generate batch ID like: PROP_2024_04_13_001
     */
    public static function generateBatchId(string $source): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $source));
        $prefix = substr($prefix ?: 'LEAD', 0, 6);
        $date   = date('Y_m_d');
        $nano   = substr((string)microtime(true), -3);
        return "{$prefix}_{$date}_{$nano}";
    }

    /**
     * Normalize phone: strip non-digits, remove country codes, keep 10 digits
     */
    public static function normalizePhone(mixed $raw): string
    {
        // Handle scientific notation from Excel (e.g., 9.1E+11 → 910000000000)
        if (is_float($raw) || (is_string($raw) && stripos($raw, 'e') !== false)) {
            $raw = number_format((float)$raw, 0, '.', '');
        }

        $phone = preg_replace('/[^0-9]/', '', (string)$raw);

        // Remove leading country code +91
        if (strlen($phone) === 12 && str_starts_with($phone, '91')) {
            $phone = substr($phone, 2);
        }
        if (strlen($phone) === 13 && str_starts_with($phone, '091')) {
            $phone = substr($phone, 3);
        }

        return strlen($phone) >= 10 ? substr($phone, -10) : $phone;
    }
}
