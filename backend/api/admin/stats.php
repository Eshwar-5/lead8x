<?php
// backend/api/admin/stats.php — v4
// Supports optional ?location=X to scope all stats to a single location's projects.
// Supports optional ?date_from=YYYY-MM-DD and ?date_to=YYYY-MM-DD for date-range filtering.

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/utils/Validator.php';

Response::setCorsHeaders();
$user = Auth::requireAuth(['Admin', 'Manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') Response::error('Method not allowed', 405);

$pdo = Database::getConnection();

// ── Filters ───────────────────────────────────────────────────────────────────
$locationFilter = Validator::sanitizeString($_GET['location']  ?? null, 150);
$dateFrom       = Validator::sanitizeString($_GET['date_from'] ?? null, 20);
$dateTo         = Validator::sanitizeString($_GET['date_to']   ?? null, 20);

// Validate dates format
if (!empty($dateFrom)) {
    $d = DateTime::createFromFormat('Y-m-d', $dateFrom);
    if (!$d || $d->format('Y-m-d') !== $dateFrom) $dateFrom = null;
}
if (!empty($dateTo)) {
    $d = DateTime::createFromFormat('Y-m-d', $dateTo);
    if (!$d || $d->format('Y-m-d') !== $dateTo) $dateTo = null;
}

// Base condition: active (non-deleted) leads only
$active = "deleted_at IS NULL";

// Location condition
$locationCond     = '';
$locationBindings = [];
if (!empty($locationFilter)) {
    $locationCond     = " AND (project IN (SELECT project_name FROM project_locations WHERE TRIM(location) = ?) OR TRIM(city) = ?)";
    $locationBindings = [trim($locationFilter), trim($locationFilter)];
}

// Date condition
$dateCond     = '';
$dateBindings = [];
if (!empty($dateFrom)) { $dateCond .= ' AND DATE(created_at) >= ?'; $dateBindings[] = $dateFrom; }
if (!empty($dateTo))   { $dateCond .= ' AND DATE(created_at) <= ?'; $dateBindings[] = $dateTo;   }

// ── Base WHERE and bindings used by all simple (single-table) queries ─────────
$baseWhere    = "WHERE $active" . $locationCond . $dateCond;
$baseBindings = array_merge($locationBindings, $dateBindings);

// ── Overview counts ───────────────────────────────────────────────────────────
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM leads $baseWhere");
    $countStmt->execute($baseBindings);
    $totalLeads = (int)$countStmt->fetchColumn();

    $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM leads $baseWhere AND is_duplicate = 1");
    $dupStmt->execute($baseBindings);
    $duplicateLeads = (int)$dupStmt->fetchColumn();

    $assignStmt = $pdo->prepare("SELECT COUNT(*) FROM leads $baseWhere AND assigned_to IS NOT NULL");
    $assignStmt->execute($baseBindings);
    $assignedLeads = (int)$assignStmt->fetchColumn();
} catch (\PDOException $e) {
    error_log('Stats overview error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    Response::error('Stats error', 500);
}

try {
    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
} catch (\PDOException $e) {
    error_log('Stats totalUsers error: ' . $e->getMessage());
    $totalUsers = 0;
}

// ── Status breakdown ──────────────────────────────────────────────────────────
try {
    $stStmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS count
         FROM leads
         $baseWhere
         GROUP BY status
         ORDER BY count DESC"
    );
    $stStmt->execute($baseBindings);
    $statusRows = $stStmt->fetchAll();
} catch (\PDOException $e) {
    $statusRows = [];
}

// ── Project breakdown ─────────────────────────────────────────────────────────
try {
    $projStmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(TRIM(project), ''), 'Unknown') AS project,
                COUNT(*) AS count
         FROM leads
         $baseWhere
         GROUP BY project
         ORDER BY count DESC
         LIMIT 20"
    );
    $projStmt->execute($baseBindings);
    $projectRows = $projStmt->fetchAll();
} catch (\PDOException $e) {
    $projectRows = [];
}

// ── NRI breakdown ─────────────────────────────────────────────────────────────
try {
    $nriStmt = $pdo->prepare(
        "SELECT CASE WHEN is_nri = 1 THEN 'NRI' ELSE 'Non-NRI' END AS label,
                COUNT(*) AS count
         FROM leads
         $baseWhere
         GROUP BY is_nri
         ORDER BY count DESC"
    );
    $nriStmt->execute($baseBindings);
    $nriRows = $nriStmt->fetchAll();
} catch (\PDOException $e) {
    $nriRows = [];
}

// ── City breakdown ────────────────────────────────────────────────────────────
try {
    $cityStmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(TRIM(city), ''), 'Unknown') AS city,
                COUNT(*) AS count
         FROM leads
         $baseWhere
         GROUP BY city
         ORDER BY count DESC
         LIMIT 20"
    );
    $cityStmt->execute($baseBindings);
    $cityRows = $cityStmt->fetchAll();
} catch (\PDOException $e) {
    $cityRows = [];
}

// ── Country breakdown ─────────────────────────────────────────────────────────
try {
    $countryStmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(TRIM(country), ''), 'Unknown') AS country,
                COUNT(*) AS count
         FROM leads
         $baseWhere
         GROUP BY country
         ORDER BY count DESC
         LIMIT 20"
    );
    $countryStmt->execute($baseBindings);
    $countryRows = $countryStmt->fetchAll();
} catch (\PDOException $e) {
    $countryRows = [];
}

// ── Device breakdown ──────────────────────────────────────────────────────────
try {
    $deviceStmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(TRIM(device), ''), 'Unknown') AS device,
                COUNT(*) AS count
         FROM leads
         $baseWhere
         GROUP BY device
         ORDER BY count DESC
         LIMIT 15"
    );
    $deviceStmt->execute($baseBindings);
    $deviceRows = $deviceStmt->fetchAll();
} catch (\PDOException $e) {
    $deviceRows = [];
}

// ── Per-user stats ────────────────────────────────────────────────────────────
$userDateCond    = '';
$userDateBind    = [];
if (!empty($dateFrom)) { $userDateCond .= ' AND DATE(l.created_at) >= ?'; $userDateBind[] = $dateFrom; }
if (!empty($dateTo))   { $userDateCond .= ' AND DATE(l.created_at) <= ?'; $userDateBind[] = $dateTo;   }

$userJoinCond = "l.assigned_to = u.id AND l.deleted_at IS NULL" . $userDateCond;
$userBindings = $userDateBind;
if (!empty($locationFilter)) {
    $userJoinCond .= " AND (l.project IN (SELECT project_name FROM project_locations WHERE TRIM(location) = ?) OR TRIM(l.city) = ?)";
    $userBindings  = array_merge($userDateBind, [trim($locationFilter), trim($locationFilter)]);
}

try {
    $userStmt = $pdo->prepare(
        "SELECT u.id, u.name, u.role,
                SUM(CASE WHEN l.id IS NOT NULL THEN 1 ELSE 0 END) AS total_leads,
                SUM(CASE WHEN l.status = 'Interested' THEN 1 ELSE 0 END) AS interested,
                SUM(CASE WHEN l.status = 'Booked' THEN 1 ELSE 0 END) AS booked
         FROM users u
         LEFT JOIN leads l ON $userJoinCond
         WHERE u.is_active = 1
         GROUP BY u.id, u.name, u.role
         ORDER BY total_leads DESC"
    );
    $userStmt->execute($userBindings);
    $userStats = $userStmt->fetchAll();
} catch (\PDOException $e) {
    $userStats = [];
}

// ── Recent batches ────────────────────────────────────────────────────────────
try {
    $batchStmt = $pdo->prepare(
        "SELECT first_batch_id AS batch_id, first_source AS source,
                COUNT(*) AS total,
                SUM(is_duplicate) AS duplicates,
                MIN(created_at) AS uploaded_at
         FROM leads
         $baseWhere
           AND first_batch_id IS NOT NULL
         GROUP BY first_batch_id, first_source
         ORDER BY uploaded_at DESC
         LIMIT 10"
    );
    $batchStmt->execute($baseBindings);
    $batches = $batchStmt->fetchAll();
} catch (\PDOException $e) {
    $batches = [];
}

// ── Location breakdown (always global — pie chart context) ────────────────────
try {
    $locationRows = $pdo->query(
        "SELECT pl.location, COUNT(l.id) AS count
         FROM project_locations pl
         INNER JOIN leads l ON l.project = pl.project_name AND l.deleted_at IS NULL
         GROUP BY pl.location
         ORDER BY count DESC
         LIMIT 15"
    )->fetchAll();
} catch (\PDOException $e) {
    $locationRows = [];
}

// ── Response ──────────────────────────────────────────────────────────────────
Response::success('OK', [
    'overview' => [
        'total_leads'      => $totalLeads,
        'duplicate_leads'  => $duplicateLeads,
        'assigned_leads'   => $assignedLeads,
        'unassigned_leads' => $totalLeads - $assignedLeads,
        'total_users'      => $totalUsers,
    ],
    'status_breakdown'   => $statusRows,
    'project_breakdown'  => $projectRows,
    'nri_breakdown'      => $nriRows,
    'city_breakdown'     => $cityRows,
    'country_breakdown'  => $countryRows,
    'device_breakdown'   => $deviceRows,
    'user_stats'         => $userStats,
    'recent_batches'     => $batches,
    'location_breakdown' => $locationRows,
]);
