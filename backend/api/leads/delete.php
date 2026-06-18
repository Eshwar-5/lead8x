<?php
// backend/api/leads/delete.php
// Modes: soft (move to trash), purge (hard-delete from trash), project-wise

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

require_once dirname(__DIR__, 2) . '/utils/Validator.php';

Response::setCorsHeaders();

$user = Auth::requireAuth(['Admin', 'Manager']);

if (!in_array($_SERVER['REQUEST_METHOD'], ['DELETE', 'POST'], true)) {
    Response::error('Method not allowed', 405);
}

$body    = json_decode(file_get_contents('php://input'), true);
$mode    = Validator::sanitizeString($body['mode'] ?? 'single', 20);
$ids     = array_filter(array_map('intval', (array)($body['ids'] ?? [])), fn($id) => $id > 0);
$project = Validator::sanitizeString($body['project'] ?? null, 100) ?: '';

$pdo = Database::getConnection();

/**
 * Cleanup: removes project_locations and projects master rows
 * for any project_name that has zero active (non-deleted) leads.
 * Safe — only deletes mapping/master rows, never touches leads themselves.
 */
function cleanupOrphanProjects(\PDO $pdo): void {
    // 1. Remove from project_locations where project has no active leads
    $pdo->exec(
        "DELETE FROM project_locations
         WHERE project_name NOT IN (
             SELECT DISTINCT project FROM leads
             WHERE project IS NOT NULL AND project != '' AND deleted_at IS NULL
         )"
    );
    // 2. Remove from projects master table where project has no active leads
    $pdo->exec(
        "DELETE FROM projects
         WHERE name NOT IN (
             SELECT DISTINCT project FROM leads
             WHERE project IS NOT NULL AND project != '' AND deleted_at IS NULL
         )"
    );
}

// --- PROJECT-WISE SOFT DELETE ---
if ($mode === 'project') {
    if ($project === '') Response::error('Project name required.');
    $stmt = $pdo->prepare("UPDATE leads SET deleted_at = NOW() WHERE project = ? AND deleted_at IS NULL");
    $stmt->execute([$project]);
    $count = $stmt->rowCount();
    Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'Lead Delete',
        "Project-wise delete: {$count} leads from '{$project}'.");
    cleanupOrphanProjects($pdo);
    Response::success("{$count} leads deleted from project '{$project}'.", ['deleted' => $count]);
}

// --- PURGE ALL FROM TRASH (hard delete all soft-deleted) ---
if ($mode === 'purge_all') {
    $stmt = $pdo->prepare("DELETE FROM leads WHERE deleted_at IS NOT NULL");
    $stmt->execute();
    $count = $stmt->rowCount();
    Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'Lead Purge',
        "Permanently purged {$count} leads from trash.");
    cleanupOrphanProjects($pdo);
    Response::success("{$count} leads permanently deleted.", ['deleted' => $count]);
}

// --- VALIDATE IDs ---
if (empty($ids) || !is_array($ids)) Response::error('ids array is required.');
$ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
if (empty($ids)) Response::error('No valid lead IDs provided.');

// Limit to 1000
if (count($ids) > 1000) $ids = array_slice($ids, 0, 1000);

$placeholders = implode(',', array_fill(0, count($ids), '?'));

// --- PURGE (hard delete from trash) ---
if ($mode === 'purge') {
    $stmt = $pdo->prepare("DELETE FROM leads WHERE id IN ({$placeholders}) AND deleted_at IS NOT NULL");
    $stmt->execute(array_values($ids));
    $count = $stmt->rowCount();
    Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'Lead Purge',
        "Permanently purged {$count} lead(s) from trash.");
    cleanupOrphanProjects($pdo);
    Response::success("{$count} lead(s) permanently deleted.", ['deleted' => $count]);
}

// --- SOFT DELETE (single / bulk) ---
$stmt = $pdo->prepare("UPDATE leads SET deleted_at = NOW() WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
$stmt->execute(array_values($ids));
$count = $stmt->rowCount();
Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'Lead Delete',
    "{$count} lead(s) moved to trash.");
// Note: soft-delete (trash) only hides leads — cleanup runs on purge, not soft-delete.
// This keeps project visible while leads are in trash (recoverable).
Response::success("{$count} lead(s) moved to trash.", ['deleted' => $count]);
