<?php
// backend/api/distribution/distribute.php

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

$body    = json_decode(file_get_contents('php://input'), true);
$type    = Validator::sanitizeString($body['type'] ?? 'equal', 20);
$batchId = Validator::sanitizeString($body['batch_id'] ?? null, 100);
$leadIds = array_filter(array_map('intval', (array)($body['lead_ids'] ?? [])), fn($id) => $id > 0);
$userIds = array_filter(array_map('intval', (array)($body['user_ids'] ?? [])), fn($id) => $id > 0);

if (empty($userIds)) {
    Response::error('At least one active user must be selected for distribution.');
}

$pdo = Database::getConnection();
$detector = new DuplicateDetector($pdo);

// --- Validate target users exist and are active ---
$placeholders = implode(',', array_fill(0, count($userIds), '?'));
$userStmt = $pdo->prepare(
    "SELECT id, name, role FROM users WHERE id IN ({$placeholders}) AND is_active = 1"
);
$userStmt->execute($userIds);
$targetUsers = $userStmt->fetchAll();

if (empty($targetUsers)) {
    Response::error('No valid active users found for the provided IDs.');
}

// --- Fetch leads to distribute ---
if ($type === 'equal' && $batchId !== '') {
    // Distribute all unassigned leads in a batch equally
    $stmt = $pdo->prepare(
        "SELECT id FROM leads WHERE first_batch_id = ? AND (assigned_to IS NULL OR assigned_to = 0) ORDER BY id ASC"
    );
    $stmt->execute([$batchId]);
    $leadsToAssign = $stmt->fetchAll(PDO::FETCH_COLUMN);
} elseif ($type === 'manual' && !empty($leadIds)) {
    $leadsToAssign = array_map('intval', $leadIds);
} else {
    Response::error('Provide batch_id for equal distribution or lead_ids for manual.');
}

if (empty($leadsToAssign)) {
    Response::error('No unassigned leads found for the given criteria.');
}

// --- Distribute ---
$totalUsers   = count($targetUsers);
$distributed  = 0;
$assignments  = [];   // [lead_id => user_id]

$pdo->beginTransaction();
try {
    foreach ($leadsToAssign as $idx => $leadId) {
        $targetUser = $targetUsers[$idx % $totalUsers];

        // Update lead
        $pdo->prepare("UPDATE leads SET assigned_to = ?, status = 'Assigned', updated_at = NOW() WHERE id = ?")
            ->execute([$targetUser['id'], $leadId]);

        // Record assignment
        $pdo->prepare(
            "INSERT INTO lead_assignments (lead_id, assigned_to, assigned_by, assignment_type, batch_id, to_role, assigned_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        )->execute([$leadId, $targetUser['id'], $user['id'], $type === 'equal' ? 'Equal' : 'Manual', $batchId ?: null, $targetUser['role']]);

        // Timeline
        $detector->logTimeline($leadId, 'Assigned',
            "Assigned to {$targetUser['name']} ({$type} distribution) by {$user['name']}",
            (int)$user['id'], $user['name']
        );

        $assignments[$leadId] = $targetUser['id'];
        $distributed++;
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    Response::error('Distribution failed: ' . $e->getMessage(), 500);
}

// Build per-user counts
$perUser = [];
foreach ($targetUsers as $u) {
    $perUser[$u['name']] = 0;
}
foreach ($assignments as $leadId => $uid) {
    $uName = array_column($targetUsers, 'name', 'id')[$uid] ?? 'Unknown';
    $perUser[$uName]++;
}

Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'Distribution',
    "{$distributed} leads distributed ({$type}) to " . count($targetUsers) . " users.");

Response::success('Distribution complete.', [
    'distributed' => $distributed,
    'per_user'    => $perUser,
    'batch_id'    => $batchId,
]);
