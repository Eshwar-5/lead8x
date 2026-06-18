<?php
// backend/api/leads/list.php — v3

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/utils/Validator.php';

Response::setCorsHeaders();

$user = Auth::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$pdo = Database::getConnection();

// --- Query Params ---
$page        = max(1, Validator::asInt($_GET['page'] ?? 1, 1));
$limit       = Validator::asInt($_GET['limit'] ?? 50, 50);
$limit       = min(1000, max(10, $limit));
$offset      = ($page - 1) * $limit;
$search      = Validator::sanitizeString($_GET['search'] ?? null);
$status      = Validator::sanitizeString($_GET['status'] ?? null);
$batchId     = Validator::sanitizeString($_GET['batch_id'] ?? null);
$isDup       = isset($_GET['is_duplicate']) ? (int)$_GET['is_duplicate'] : null;
$isNri       = isset($_GET['is_nri'])       ? (int)$_GET['is_nri']       : null;
$assignee    = Validator::asInt($_GET['assigned_to'] ?? null, -1);
if ($assignee === -1) $assignee = null;
$project       = Validator::sanitizeString($_GET['project']       ?? null);
$location      = Validator::sanitizeString($_GET['location']      ?? null);
$device        = Validator::sanitizeString($_GET['device']        ?? null);
$country       = Validator::sanitizeString($_GET['country']       ?? null);
$dateFrom      = Validator::sanitizeString($_GET['date_from']     ?? null);
$dateTo        = Validator::sanitizeString($_GET['date_to']       ?? null);
$showDeleted   = ($_GET['show_deleted']    ?? '') === '1';
$autoOnly      = ($_GET['auto_imported']   ?? '') === '1';
$assignedOnly  = ($_GET['assigned_only']   ?? '') === '1';
$unassignedOnly= ($_GET['unassigned_only'] ?? '') === '1';

if ($assignedOnly && $unassignedOnly) {
    Response::error('assigned_only and unassigned_only cannot both be true', 400);
}

// Sort
$allowedSorts = ['name' => 'l.name', 'assigned' => 'u.name', 'date' => 'l.created_at', 'id' => 'l.id'];
$sortBy  = $allowedSorts[$_GET['sort_by'] ?? 'date'] ?? 'l.created_at';
$sortDir = strtoupper($_GET['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

// Role-based filtering
$roleFilter = '';
$bindings   = [];

$isCallerRole = in_array($user['role'], ['Caller', 'Relationship Manager'], true);
$isAssigneeFiltered = ($assignee !== null);

if ($isCallerRole && !$isAssigneeFiltered) {
    $roleFilter  = ' AND l.assigned_to = ?';
    $bindings[]  = $user['id'];
}

// Callers cannot see Not Interested leads
if ($isCallerRole) {
    $roleFilter .= " AND l.status != 'Not Interested'";
}

// WHERE
$where = "WHERE 1=1{$roleFilter}";

// Deleted / active
if (!$showDeleted) {
    $where .= ' AND l.deleted_at IS NULL';
} else {
    $where .= ' AND l.deleted_at IS NOT NULL';
}

if ($search !== '') {
    $where .= ' AND (l.phone LIKE ? OR l.name LIKE ? OR l.email LIKE ? OR l.id LIKE ?)';
    $bindings[] = "%{$search}%"; $bindings[] = "%{$search}%";
    $bindings[] = "%{$search}%"; $bindings[] = "%{$search}%";
}
if ($status !== '') { $where .= ' AND l.status = ?'; $bindings[] = $status; }
if ($batchId !== '') { $where .= ' AND l.first_batch_id = ?'; $bindings[] = $batchId; }
if ($isDup !== null) { $where .= ' AND l.is_duplicate = ?'; $bindings[] = $isDup; }
if ($isNri !== null) { $where .= ' AND l.is_nri = ?'; $bindings[] = $isNri; }
if ($assignee !== null) { $where .= ' AND l.assigned_to = ?'; $bindings[] = $assignee; }
if ($project  !== '') { $where .= ' AND l.project = ?'; $bindings[] = $project; }
if ($location !== '') {
    // Match leads by location using BOTH sources:
    // 1. Leads whose project is mapped to this location via project_locations
    // 2. Leads that have this value directly in the city column
    $where .= ' AND (
        l.project IN (SELECT project_name FROM project_locations WHERE TRIM(location) = ?)
        OR TRIM(l.city) = ?
    )';
    $bindings[] = trim($location);
    $bindings[] = trim($location);
}
if ($device        !== '') { $where .= ' AND l.device LIKE ?'; $bindings[] = "%{$device}%"; }
if ($country       !== '') { $where .= ' AND l.country = ?';  $bindings[] = $country; }
if ($dateFrom      !== '') { $where .= ' AND DATE(l.created_at) >= ?'; $bindings[] = $dateFrom; }
if ($dateTo        !== '') { $where .= ' AND DATE(l.created_at) <= ?'; $bindings[] = $dateTo; }
if ($autoOnly)             { $where .= ' AND l.auto_imported = 1'; }
if ($assignedOnly)         { $where .= ' AND l.assigned_to IS NOT NULL'; }

// Only apply unassigned filter if we haven't already restricted assignment
$assignmentRestricted = ($assignee !== null) || ($isCallerRole && !$isAssigneeFiltered);
if ($unassignedOnly && !$assignmentRestricted) { 
    $where .= ' AND l.assigned_to IS NULL'; 
}

// Count + Data — both wrapped so any SQL failure returns a clean JSON error
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM leads l LEFT JOIN users u ON l.assigned_to = u.id {$where}");
    $countStmt->execute($bindings);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT l.id, l.entry_id, l.name, l.phone, l.email, l.project, l.status,
               l.country, l.ip_address, l.device, l.refer_url, l.remark,
               l.is_nri, l.is_duplicate, l.first_batch_id,
               l.created_at, l.updated_at, l.deleted_at,
               l.assigned_to, u.name AS assigned_to_name,
               la.assigned_at
        FROM leads l
        LEFT JOIN users u ON l.assigned_to = u.id
        LEFT JOIN (
            SELECT lead_id, MAX(assigned_at) AS assigned_at
            FROM lead_assignments GROUP BY lead_id
        ) la ON la.lead_id = l.id
        {$where}
        ORDER BY {$sortBy} {$sortDir}
        LIMIT {$limit} OFFSET {$offset}
    ";
    $stmt  = $pdo->prepare($sql);
    $stmt->execute($bindings);
    $leads = $stmt->fetchAll();
} catch (\PDOException $e) {
    Response::error('Database error: ' . $e->getMessage(), 500);
}

// Callers: strip project name
if ($isCallerRole) {
    $leads = array_map(function($l) {
        unset($l['project']);
        return $l;
    }, (array)$leads);
}

Response::success('OK', [
    'leads'       => $leads ?: [],
    'total'       => $total,
    'page'        => $page,
    'limit'       => $limit,
    'total_pages' => (int)ceil($total / $limit),
]);
