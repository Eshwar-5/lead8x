<?php
// backend/api/leads/upload-confirm.php
// Step 2 of 2: Receive parse_id + user-chosen project name + refer_url, save leads to DB

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/DuplicateDetector.php';
require_once dirname(__DIR__, 2) . '/utils/Validator.php';

Response::setCorsHeaders();

$user = Auth::requireAuth(['Admin', 'Manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    Response::error('Invalid JSON request body.', 400);
}

$parseId     = Validator::sanitizeString($body['parse_id'] ?? null, 128);
$projectName = Validator::sanitizeString($body['project_name'] ?? null, 100);
$referUrl    = Validator::sanitizeUrl($body['refer_url'] ?? null);

if (empty($parseId)) {
    Response::error('parse_id is required.');
}

// Load cached rows
$cacheFile = sys_get_temp_dir() . '/lead8x_cache/' . preg_replace('/[^a-z0-9_.]/', '', $parseId) . '.json';
if (!file_exists($cacheFile)) {
    Response::error('Upload session expired. Please re-upload the file.', 410);
}

$cache    = json_decode(file_get_contents($cacheFile), true);
$rows     = $cache['rows'];
@unlink($cacheFile);

// Generate batch ID
$batchId  = DuplicateDetector::generateBatchId($projectName ?: 'UPLOAD');
$pdo      = Database::getConnection();
$detector = new DuplicateDetector($pdo);

// Ensure project exists in projects table
if ($projectName !== '') {
    $pdo->prepare("INSERT IGNORE INTO projects (name) VALUES (?)")->execute([$projectName]);
}

$stats = ['new' => 0, 'duplicate' => 0, 'skipped' => 0, 'total' => count($rows)];

$pdo->beginTransaction();
try {
    foreach ($rows as $row) {
        // Apply project name: manual override > hidden_field > existing project column
        $row['project'] = $projectName
            ?: trim((string)($row['hidden_field'] ?? $row['project'] ?? ''));

        // Apply refer_url: manual override > file value
        if ($referUrl !== '') {
            $row['refer_url'] = $referUrl;
        }

        // NRI detection (Priority: column value > auto-detect)
        if (isset($row['is_nri']) && $row['is_nri'] !== '') {
            $nriVal = strtolower(trim((string)$row['is_nri']));
            $row['is_nri'] = in_array($nriVal, ['yes', 'true', '1', 'on', 'nri', 'y']) ? 1 : 0;
        } else {
            $phone   = trim((string)($row['phone'] ?? ''));
            $country = trim((string)($row['country'] ?? ''));
            $row['is_nri'] = detectNri($phone, $country) ? 1 : 0;
        }
        
        // Date overriding (from Created Time)
        if (!empty($row['created_time'])) {
            $rawDate = trim((string)$row['created_time']);
            $formats = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'm/d/Y'];
            $dt = null;
            foreach ($formats as $fmt) {
                $parsed = DateTime::createFromFormat($fmt, $rawDate);
                $errors = DateTime::getLastErrors();
                if ($parsed !== false && $errors['error_count'] === 0) {
                    $dt = $parsed;
                    break;
                }
            }
            if ($dt) {
                // Ignore time, save only date portion to align with requested behaviour
                $row['created_at'] = $dt->format('Y-m-d 00:00:00');
            }
        }

        // entry_id
        $row['entry_id'] = trim((string)($row['entry_id'] ?? '')) ?: null;
        $row['ip_address'] = trim((string)($row['ip_address'] ?? '')) ?: null;

        // Device normalization
        $rawDevice = trim((string)($row['device'] ?? ''));
        if ($rawDevice !== '') {
            // Unify | - on /
            $dev = preg_replace('/\s*(?:\||\bon\b|\-|\/)\s*/i', ' / ', $rawDevice);
            $dev = ucwords(strtolower($dev));
            $dev = str_ireplace(['iphone', 'ipad', 'macos', 'ios'], ['iPhone', 'iPad', 'macOS', 'iOS'], $dev);
            $row['device'] = $dev;
        } else {
            $row['device'] = null;
        }

        $result = $detector->processLead($row, $batchId, (int)$user['id']);
        $stats[$result['action']]++;
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    Response::error('Processing failed: ' . $e->getMessage(), 500);
}

Auth::logActivity(
    $pdo, (int)$user['id'], $user['name'],
    'Lead Upload',
    "Batch {$batchId}: {$stats['new']} new, {$stats['duplicate']} duplicates from {$stats['total']} rows. Project: {$projectName}"
);

Response::success('Upload complete.', [
    'batch_id'   => $batchId,
    'total'      => $stats['total'],
    'new'        => $stats['new'],
    'duplicates' => $stats['duplicate'],
    'skipped'    => $stats['skipped'],
]);

// NRI detection helper
function detectNri(string $phone, string $country): bool {
    $clean = preg_replace('/[\s\-\(\)]/', '', $phone);
    // Indian phone: starts with +91 / 91 followed by 10 digit number
    $isIndianPhone = (bool)preg_match('/^(\+91|91)?[6-9]\d{9}$/', $clean);
    $indiaNames    = ['india', 'in', 'bharat'];
    $isIndiaCountry = in_array(strtolower(trim($country)), $indiaNames, true) || $country === '';
    return !$isIndianPhone || !$isIndiaCountry;
}
