<?php
// backend/api/projects/locations.php
//
// GET  ?all_locations=1           → list all distinct locations (for Location filter dropdown)
// GET  ?project_name=X            → get the single location for one project
// POST {project_name, location}   → set / update location for a project (one per project)
// DELETE ?id=N                    → remove a project's location mapping

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/utils/Validator.php';

Response::setCorsHeaders();
$user = Auth::requireAuth(['Admin', 'Manager']);

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // Mode A: all distinct locations — for independent Location filter dropdown
    if (!empty($_GET['all_locations'])) {
        // UNION: locations from project_locations mapping + distinct city values in leads.
        // This ensures the dropdown is populated even when project_locations is empty.
        $stmt = $pdo->query(
            "SELECT DISTINCT loc FROM (
                 -- Source 1: named locations from project_locations that have active leads
                 SELECT TRIM(pl.location) AS loc
                 FROM project_locations pl
                 INNER JOIN leads l ON l.project = pl.project_name AND l.deleted_at IS NULL
                 WHERE pl.location IS NOT NULL AND TRIM(pl.location) != ''

                 UNION

                 -- Source 2: city column from active leads (works without project_locations setup)
                 SELECT TRIM(l.city) AS loc
                 FROM leads l
                 WHERE l.city IS NOT NULL
                   AND TRIM(l.city) != ''
                   AND l.deleted_at IS NULL
             ) AS all_locs
             WHERE loc IS NOT NULL AND TRIM(loc) != ''
             ORDER BY loc ASC"
        );
        $raw       = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $locations = array_values(array_filter($raw, fn($v) => is_string($v) && trim($v) !== ''));
        Response::success('OK', ['locations' => $locations]);
        return;
    }

    // Mode B: single project's location
    $projectName = Validator::sanitizeString($_GET['project_name'] ?? null, 150);
    if (empty($projectName)) Response::error('project_name or all_locations is required', 400);

    $stmt = $pdo->prepare(
        "SELECT id, project_name, location, created_at
         FROM project_locations
         WHERE project_name = ?
         LIMIT 1"
    );
    $stmt->execute([$projectName]);
    $row = $stmt->fetch();
    // Always return an array for consistency; empty array = no location set yet
    Response::success('OK', ['locations' => $row ? [$row] : []]);
}

// ── POST (set / update) ───────────────────────────────────────────────────────
elseif ($method === 'POST') {
    $body        = json_decode(file_get_contents('php://input'), true);
    $projectName = Validator::sanitizeString($body['project_name'] ?? null, 150);
    $location    = Validator::sanitizeString($body['location']     ?? null, 150);

    if (empty($projectName) || empty($location)) {
        Response::error('project_name and location are required', 400);
    }

    // ONE location per project: INSERT or UPDATE (upsert)
    $stmt = $pdo->prepare(
        "INSERT INTO project_locations (project_name, location)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE location = VALUES(location)"
    );
    $stmt->execute([$projectName, $location]);

    // lastInsertId() returns 0 on UPDATE path of ON DUPLICATE KEY UPDATE,
    // so always fetch the actual row id after the upsert.
    $idStmt = $pdo->prepare("SELECT id FROM project_locations WHERE project_name = ? LIMIT 1");
    $idStmt->execute([$projectName]);
    $newId = (int)($idStmt->fetchColumn() ?: 0);

    Response::success('Location saved.', [
        'id'           => $newId,
        'project_name' => $projectName,
        'location'     => $location,
    ]);
}

// ── DELETE ────────────────────────────────────────────────────────────────────
elseif ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) Response::error('Valid id required', 400);

    $stmt = $pdo->prepare("DELETE FROM project_locations WHERE id = ?");
    $stmt->execute([$id]);
    Response::success('Location removed.');
}

else {
    Response::error('Method not allowed', 405);
}
