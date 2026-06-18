<?php
// backend/api/analytics.php
// Live Analytics War Room logic

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/utils/Response.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/utils/Validator.php';

Response::setCorsHeaders();

try {
    $user = Auth::requireAuth(['Admin', 'Manager']);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') Response::error('Method not allowed', 405);

    $pdo = Database::getConnection();

    // --- FILTERS ---
    $dateRange = Validator::sanitizeString($_GET['dateRange'] ?? 'all');
    $location  = Validator::sanitizeString($_GET['location'] ?? null);

    // Default condition
    $dateCond = '1=1';
    $dateBind = [];
    if ($dateRange === '7d') {
        $dateCond = 'l.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
    } elseif ($dateRange === '30d') {
        $dateCond = 'l.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
    } elseif ($dateRange === '90d') {
        $dateCond = 'l.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)';
    } elseif ($dateRange === 'today') {
        $dateCond = 'l.created_at >= CURDATE()';
    } elseif ($dateRange === 'yesterday') {
        $dateCond = 'l.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND l.created_at < CURDATE()';
    }

    $locCond = "";
    $locBind = [];
    if ($location) {
        $locCond = " AND (l.project IN (SELECT project_name FROM project_locations WHERE TRIM(location) = ?) OR TRIM(l.city) = ?)";
        $locBind = [$location, $location];
    }

    // SECTION A: PERFORMANCE
    $perfStmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Booked' THEN 1 ELSE 0 END) as converted FROM leads l WHERE $dateCond $locCond AND assigned_to IS NOT NULL AND deleted_at IS NULL");
    $perfStmt->execute(array_merge($dateBind, $locBind));
    $perf = $perfStmt->fetch(PDO::FETCH_ASSOC);
    $totalLeads = (int)$perf['total'];
    $convertedLeads = (int)$perf['converted'];
    $conversionRate = $totalLeads > 0 ? round(($convertedLeads / $totalLeads) * 100, 1) : 0;

    // SECTION B: SOURCE ROI
    $srcStmt = $pdo->prepare("SELECT first_source as source, COUNT(*) as leads, SUM(CASE WHEN status = 'Booked' THEN 1 ELSE 0 END) as converted FROM leads l WHERE $dateCond $locCond AND assigned_to IS NOT NULL AND deleted_at IS NULL GROUP BY first_source ORDER BY leads DESC LIMIT 10");
    $srcStmt->execute(array_merge($dateBind, $locBind));
    $sourceROI = $srcStmt->fetchAll(PDO::FETCH_ASSOC);

    // SECTION C: AGENT INTELLIGENCE
    $agtStmt = $pdo->prepare("SELECT u.name, COUNT(l.id) as assigned, SUM(CASE WHEN l.status = 'Booked' THEN 1 ELSE 0 END) as converted 
                               FROM users u 
                               LEFT JOIN leads l ON l.assigned_to = u.id AND $dateCond AND l.deleted_at IS NULL $locCond
                               WHERE u.role IN ('Manager', 'Agent') 
                               GROUP BY u.id 
                               ORDER BY converted DESC");
    $agtStmt->execute(array_merge($dateBind, $locBind));
    $agents = $agtStmt->fetchAll(PDO::FETCH_ASSOC);

    // SECTION D: FUNNEL
    $fLocCond = $location ? " AND (l.project IN (SELECT project_name FROM project_locations WHERE TRIM(location) = ?) OR TRIM(l.city) = ?) " : "";
    $fLocBind = $location ? [$location, $location] : [];
    $funnelStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status IN ('Called', 'Interested', 'Follow Up', 'Site Visit', 'Booked') THEN 1 ELSE 0 END) as contacted,
            SUM(CASE WHEN status IN ('Interested', 'Follow Up', 'Site Visit', 'Booked') THEN 1 ELSE 0 END) as qualified,
            SUM(CASE WHEN status IN ('Site Visit', 'Booked') THEN 1 ELSE 0 END) as visit,
            SUM(CASE WHEN status = 'Booked' THEN 1 ELSE 0 END) as closed
        FROM leads l WHERE $dateCond $fLocCond AND assigned_to IS NOT NULL AND deleted_at IS NULL
    ");
    $funnelStmt->execute(array_merge($dateBind, $fLocBind));
    $fRow = $funnelStmt->fetch(PDO::FETCH_ASSOC);

    $t = max(1, (int)$fRow['total']);
    $funnel = [
        ['name' => 'Assigned',  'count' => (int)$fRow['total'],     'dropPercentage' => round(100 - ((int)$fRow['contacted'] / $t) * 100)],
        ['name' => 'Contacted', 'count' => (int)$fRow['contacted'], 'dropPercentage' => $fRow['contacted'] > 0 ? round(100 - ((int)$fRow['qualified'] / $fRow['contacted']) * 100) : 0],
        ['name' => 'Qualified', 'count' => (int)$fRow['qualified'], 'dropPercentage' => $fRow['qualified'] > 0 ? round(100 - ((int)$fRow['visit'] / $fRow['qualified']) * 100) : 0],
        ['name' => 'Visit',     'count' => (int)$fRow['visit'],     'dropPercentage' => $fRow['visit'] > 0 ? round(100 - ((int)$fRow['closed'] / $fRow['visit']) * 100) : 0],
        ['name' => 'Closed',    'count' => (int)$fRow['closed'],    'dropPercentage' => 0]
    ];

    // SECTION E: DATA QUALITY
    $dqStmt = $pdo->prepare("SELECT COUNT(*) as tot, SUM(is_duplicate) as dup, SUM(CASE WHEN status IN ('Not Interested', 'Wrong Number') THEN 1 ELSE 0 END) as invalid FROM leads l WHERE $dateCond $fLocCond AND assigned_to IS NOT NULL AND deleted_at IS NULL");
    $dqStmt->execute(array_merge($dateBind, $fLocBind));
    $dqRow = $dqStmt->fetch(PDO::FETCH_ASSOC);
    $totLeads = max(1, (int)$dqRow['tot']);
    $dq = [
        'duplicatePercentage' => round(((int)$dqRow['dup'] / $totLeads) * 100, 1),
        'invalidPercentage'   => round(((int)$dqRow['invalid'] / $totLeads) * 100, 1)
    ];

    Response::success('OK', [
        'performance' => [
            'totalLeads' => $totalLeads,
            'convertedLeads' => $convertedLeads,
            'conversionRate' => $conversionRate
        ],
        'funnel' => $funnel,
        'agents' => $agents,
        'sourceROI' => $sourceROI,
        'dataQuality' => $dq
    ]);

} catch (Exception $e) {
    // Log sensitive error details to server logs for diagnostics
    error_log("Analytics Engine Fault: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    Response::error("Analytics Engine halted", 500);
}

