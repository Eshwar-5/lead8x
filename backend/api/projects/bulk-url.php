<?php
// backend/api/projects/bulk-url.php
// Bulk-update refer_url for all active leads under a given project

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/utils/Validator.php';

Response::setCorsHeaders();
$user = Auth::requireAuth(['Admin', 'Manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::error('Method not allowed', 405);

$body        = json_decode(file_get_contents('php://input'), true);
$projectName = Validator::sanitizeString($body['project_name'] ?? null, 150);
$referUrl    = Validator::sanitizeString($body['refer_url']    ?? null, 500);

if (empty($projectName)) Response::error('project_name is required', 400);

$pdo = Database::getConnection();

$stmt = $pdo->prepare(
    "UPDATE leads
     SET refer_url = ?, updated_at = NOW()
     WHERE project = ? AND deleted_at IS NULL"
);
$stmt->execute([$referUrl ?: null, $projectName]);
$affected = $stmt->rowCount();

Auth::logActivity($pdo, (int)$user['id'], $user['name'], 'BulkUrlUpdate',
    "Updated refer_url for {$affected} leads in project '{$projectName}'.");

Response::success("Updated {$affected} leads.", ['affected' => $affected]);
