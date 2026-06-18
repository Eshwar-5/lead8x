<?php
// backend/api/projects/list.php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/utils/Validator.php';

Response::setCorsHeaders();
$user = Auth::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') Response::error('Method not allowed', 405);

$pdo  = Database::getConnection();
$mode = $_GET['mode'] ?? 'active_leads';

// ── MASTER MODE: all projects from master table only ─────────────────────────
if ($mode === 'master') {
    $projects = $pdo->query("SELECT id, name, location, created_at FROM projects ORDER BY name")->fetchAll();
    Response::success('OK', ['projects' => $projects]);
}

// ── COMBINED MODE: union of master table + active leads + project_locations ───
// Used by Project Manager so all "real" projects appear regardless of origin.
elseif ($mode === 'combined') {
    $stmt = $pdo->query(
        "SELECT DISTINCT name FROM (
             SELECT name       FROM projects          WHERE name IS NOT NULL AND name != ''
             UNION
             SELECT project AS name FROM leads        WHERE project IS NOT NULL AND project != '' AND deleted_at IS NULL
             UNION
             SELECT project_name AS name FROM project_locations WHERE project_name IS NOT NULL AND project_name != ''
         ) AS all_projects
         ORDER BY name ASC"
    );
    $rows     = $stmt->fetchAll();
    $projects = array_map(fn($r) => ['id' => $r['name'], 'name' => $r['name']], $rows);
    Response::success('OK', ['projects' => $projects]);
}

// ── BY_LOCATION MODE: projects from active leads that belong to a given location ─
elseif ($mode === 'by_location') {
    $location = Validator::sanitizeString($_GET['location'] ?? null, 150);

    if (empty($location)) {
        // No location given — fall through to active_leads (all valid project names)
        $stmt = $pdo->query(
            "SELECT DISTINCT l.project AS name
             FROM leads l
             WHERE l.project IS NOT NULL
               AND l.project != ''
               AND l.deleted_at IS NULL
             ORDER BY l.project ASC"
        );
    } else {
        // Returns projects that belong to this location via project_locations mapping
        // OR whose leads have this city value directly — so it works even without setup.
        $stmt = $pdo->prepare(
            "SELECT DISTINCT l.project AS name
             FROM leads l
             WHERE l.deleted_at IS NULL
               AND l.project IS NOT NULL
               AND l.project != ''
               AND (
                   l.project IN (SELECT project_name FROM project_locations WHERE TRIM(location) = ?)
                   OR TRIM(l.city) = ?
               )
             ORDER BY l.project ASC"
        );
        $stmt->execute([trim($location), trim($location)]);
    }

    $rows     = $stmt->fetchAll();
    $projects = array_map(fn($r) => ['id' => $r['name'], 'name' => $r['name']], $rows);
    Response::success('OK', ['projects' => $projects]);
}

// ── DEFAULT MODE: distinct project names from active leads (no ghost projects) ─
else {
    /**
     * Returns distinct project names from non-deleted leads only.
     * id === name intentionally: stable React key, no dependency on master table.
     */
    $stmt = $pdo->query(
        "SELECT DISTINCT project AS name
         FROM leads
         WHERE project IS NOT NULL
           AND project != ''
           AND deleted_at IS NULL
         ORDER BY project ASC"
    );
    $rows     = $stmt->fetchAll();
    $projects = array_map(fn($r) => ['id' => $r['name'], 'name' => $r['name']], $rows);
    Response::success('OK', ['projects' => $projects]);
}
