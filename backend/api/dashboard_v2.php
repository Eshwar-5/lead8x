<?php
// backend/api/dashboard_v2.php
// Highly optimized endpoints for the V2 Command Center

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

    $locationFilter = Validator::sanitizeString($_GET['location'] ?? null, 150);
    $dateRange      = Validator::sanitizeString($_GET['dateRange'] ?? 'all');

    $active = "deleted_at IS NULL";
    $locCond = '';
    $locBind = [];

    if ($locationFilter) {
        $locCond = " AND (project IN (SELECT project_name FROM project_locations WHERE TRIM(location) = ?) OR TRIM(city) = ?)";
        $locBind = [$locationFilter, $locationFilter];
    }

    $dateCond = '1=1';
    $dateBind = [];
    if ($dateRange === 'today') {
        $dateCond = 'created_at >= CURDATE()';
    } elseif ($dateRange === 'yesterday') {
        $dateCond = 'created_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND created_at < CURDATE()';
    } elseif ($dateRange === 'month') {
        $dateCond = "created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
    } elseif ($dateRange === 'year') {
        $dateCond = "created_at >= DATE_FORMAT(NOW(), '%Y-01-01')";
    }

    $whereStr = "WHERE $active $locCond AND $dateCond";

    // Basic Counts
    $stmtCounts = $pdo->prepare("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN assigned_to IS NOT NULL THEN 1 ELSE 0 END) as assigned,
        SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) as unassigned,
        SUM(CASE WHEN is_duplicate = 1 THEN 1 ELSE 0 END) as duplicates,
        SUM(CASE WHEN status = 'New' THEN 1 ELSE 0 END) as fresh
    FROM leads $whereStr");
    $stmtCounts->execute(array_merge($locBind, $dateBind));
    $counts = $stmtCounts->fetch(PDO::FETCH_ASSOC);

    // Overall Total
    $stmtOverall = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE $active $locCond");
    $stmtOverall->execute($locBind);
    $totalOverall = (int)$stmtOverall->fetchColumn();

    // Active Users (Online in last 15 mins via Activity Log)
    // We use a separate try-catch because if this table is missing, we don't want to kill the whole dashboard
    $activeUsers = 0;
    try {
        $activeUsers = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND user_id IS NOT NULL")->fetchColumn();
    } catch (Exception $e) {}

    // Alerts
    $alerts = [];
    if ((int)($counts['unassigned'] ?? 0) > 50) {
        $alerts[] = ['type' => 'warning', 'message' => "High volume of unassigned leads: {$counts['unassigned']} pending."];
    }
    
    // Check webhook logs (singular table name)
    try {
        $errStmt = $pdo->prepare("SELECT COUNT(*) FROM webhook_log WHERE status != 'success' AND DATE(created_at) = CURDATE()");
        $errStmt->execute();
        if ((int)$errStmt->fetchColumn() > 10) {
            $alerts[] = ['type' => 'danger', 'message' => "Webhook failures detected! Check Webhook Logs immediately."];
        }
    } catch (Exception $e) {}

    // Live Activity Stream
    $activities = [];
    try {
        $actStmt = $pdo->prepare("SELECT actor_name as actor, event_type as action, description as info, created_at as time FROM lead_timeline ORDER BY created_at DESC LIMIT 15");
        $actStmt->execute();
        $rawActivities = $actStmt->fetchAll(PDO::FETCH_ASSOC);
        $activities = array_map(function($a) {
            return [
                'actor'  => $a['actor'] ?: 'System',
                'action' => $a['action'],
                'time'   => (new DateTime($a['time']))->format('h:i A'),
                'source' => mb_substr($a['info'] ?? '', 0, 40)
            ];
        }, $rawActivities);
    } catch (Exception $e) {}

    // Charts
    $chartsSource = [];
    try {
        $srcStmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(first_source),''), 'Unknown') as source, COUNT(*) as count FROM leads $whereStr GROUP BY source ORDER BY count DESC LIMIT 8");
        $srcStmt->execute(array_merge($locBind, $dateBind));
        $chartsSource = $srcStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $chartsDevice = [];
    // Device chart often fails if column is missing
    try {
        $devStmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(device),''), 'Unknown') as device, COUNT(*) as count FROM leads $whereStr GROUP BY device ORDER BY count DESC LIMIT 5");
        $devStmt->execute(array_merge($locBind, $dateBind));
        $chartsDevice = $devStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $chartsLocation = [];
    if (!$locationFilter) {
        try {
            $locStmt = $pdo->query("SELECT pl.location, COUNT(l.id) AS count FROM project_locations pl INNER JOIN leads l ON l.project = pl.project_name AND l.deleted_at IS NULL GROUP BY pl.location ORDER BY count DESC LIMIT 8");
            $chartsLocation = $locStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }

    Response::success('OK', [
        'kpis' => [
            'total_leads'      => (int)($counts['total'] ?? 0),
            'assigned_leads'   => (int)($counts['assigned'] ?? 0),
            'unassigned_leads' => (int)($counts['unassigned'] ?? 0),
            'duplicates'       => (int)($counts['duplicates'] ?? 0),
            'fresh_leads'      => (int)($counts['fresh'] ?? 0),
            'total_overall'    => $totalOverall
        ],
        'active_users' => $activeUsers,
        'alerts' => $alerts,
        'activities' => $activities,
        'charts' => [
            'source' => $chartsSource,
            'device' => $chartsDevice,
            'location' => $chartsLocation
        ]
    ]);

} catch (Exception $e) {
    // Log sensitive error details to server logs for diagnostics
    error_log("Dashboard Engine Fault: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    Response::error("Dashboard Engine halted", 500);
}

