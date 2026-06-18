<?php
// backend/api/leads/merge.php
// Power Merge: consolidate duplicate leads into one master record

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Response::setCorsHeaders();

$user = Auth::requireAuth(['Admin', 'Manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
$ids  = $body['ids'] ?? [];

if (empty($ids) || count($ids) < 2) {
    Response::error('At least 2 lead IDs are required to merge.');
}

$ids = array_map('intval', $ids);
$ids = array_filter($ids, fn($id) => $id > 0);

$pdo = Database::getConnection();

$placeholders = implode(',', array_fill(0, count($ids), '?'));

// Fetch all leads to merge
$stmt = $pdo->prepare("SELECT * FROM leads WHERE id IN ({$placeholders}) ORDER BY created_at ASC");
$stmt->execute(array_values($ids));
$leads = $stmt->fetchAll();

if (count($leads) < 2) Response::error('Could not find enough leads to merge.');

// Master = oldest created_at
$master      = $leads[0];
$masterId    = (int)$master['id'];
$duplicateIds = array_map(fn($l) => (int)$l['id'], array_slice($leads, 1));

$pdo->beginTransaction();
try {
    // Collect refer_urls and ip_addresses from duplicates for timeline
    $mergedData = [];
    foreach ($leads as $l) {
        if (!empty($l['refer_url'])) $mergedData[] = 'URL: ' . $l['refer_url'];
        if (!empty($l['ip_address'])) $mergedData[] = 'IP: '  . $l['ip_address'];
    }

    // Log merged data into master lead's timeline
    if (!empty($mergedData)) {
        $tlStmt = $pdo->prepare(
            "INSERT INTO lead_timeline (lead_id, event_type, description, actor_id, actor_name)
             VALUES (?, 'Status Updated', ?, ?, ?)"
        );
        $tlStmt->execute([$masterId, 'Power Merge — absorbed data: ' . implode(' | ', $mergedData), $user['id'], $user['name']]);
    }

    // Re-assign assignments from duplicates to master
    $dpPlaceholders = implode(',', array_fill(0, count($duplicateIds), '?'));
    $pdo->prepare("UPDATE lead_assignments SET lead_id = ? WHERE lead_id IN ({$dpPlaceholders})")
        ->execute(array_merge([$masterId], $duplicateIds));

    // Re-assign timeline entries
    $pdo->prepare("UPDATE lead_timeline SET lead_id = ? WHERE lead_id IN ({$dpPlaceholders})")
        ->execute(array_merge([$masterId], $duplicateIds));

    // Update lead_sources
    $pdo->prepare("UPDATE lead_sources SET lead_id = ? WHERE lead_id IN ({$dpPlaceholders})")
        ->execute(array_merge([$masterId], $duplicateIds));

    // Hard-delete duplicate leads
    $pdo->prepare("DELETE FROM leads WHERE id IN ({$dpPlaceholders})")
        ->execute($duplicateIds);

    // Update master: clear duplicate flag if it was set
    $pdo->prepare("UPDATE leads SET is_duplicate = 0, duplicate_count = 0 WHERE id = ?")
        ->execute([$masterId]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    Response::error('Merge failed: ' . $e->getMessage(), 500);
}

Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'Power Merge',
    "Merged " . count($duplicateIds) . " duplicate(s) into lead #{$masterId}.");

Response::success('Merge complete.', [
    'master_id'    => $masterId,
    'merged_count' => count($duplicateIds),
]);
