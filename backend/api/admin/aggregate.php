<?php
// backend/api/admin/aggregate.php
// Cron job script to aggregate heavy analytics data into summaries

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

// Auth gate: only skip for CLI (cron job). Protect HTTP access.
if (php_sapi_name() !== 'cli') {
    Response::setCorsHeaders();
    Auth::requireAuth(['Admin']);
}

try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction();

    // 1. Fill lead_daily_stats — GROUP BY uses same normalized expressions as SELECT
    $pdo->exec("
      INSERT INTO lead_daily_stats (stat_date, project, location, source, total_leads, conversions, duplicates)
      SELECT 
        DATE(l.created_at) as stat_date,
        COALESCE(NULLIF(TRIM(l.project), ''), 'Unknown') as project,
        COALESCE(NULLIF(TRIM(pl.location), ''), 'Unknown') as location,
        COALESCE(NULLIF(TRIM(l.first_source), ''), 'Unknown') as source,
        COUNT(l.id) as total_leads,
        SUM(CASE WHEN l.status = 'Booked' THEN 1 ELSE 0 END) as conversions,
        SUM(l.is_duplicate) as duplicates
      FROM leads l
      LEFT JOIN project_locations pl ON l.project = pl.project_name
      WHERE l.deleted_at IS NULL
      GROUP BY
        DATE(l.created_at),
        COALESCE(NULLIF(TRIM(l.project), ''), 'Unknown'),
        COALESCE(NULLIF(TRIM(pl.location), ''), 'Unknown'),
        COALESCE(NULLIF(TRIM(l.first_source), ''), 'Unknown')
      ON DUPLICATE KEY UPDATE 
        total_leads = VALUES(total_leads),
        conversions = VALUES(conversions),
        duplicates  = VALUES(duplicates)
    ");

    // 2. Fill agent_performance
    // avg_resp_min = average minutes from lead creation to the agent's first recorded event
    $pdo->exec("
      INSERT INTO agent_performance (agent_id, stat_date, assigned, contacted, converted, avg_resp_min)
      SELECT
        l.assigned_to AS agent_id,
        DATE(l.created_at) AS stat_date,
        COUNT(l.id) AS assigned,
        SUM(CASE WHEN l.status NOT IN ('New', 'Assigned') THEN 1 ELSE 0 END) AS contacted,
        SUM(CASE WHEN l.status = 'Booked' THEN 1 ELSE 0 END) AS converted,
        COALESCE(
          AVG(
            TIMESTAMPDIFF(MINUTE, l.created_at, fa.first_action_at)
          ),
          0
        ) AS avg_resp_min
      FROM leads l
      -- Derive each lead's first agent-action timestamp from lead_events
      LEFT JOIN (
        SELECT lead_id, MIN(timestamp) AS first_action_at
        FROM lead_events
        WHERE event_type IN ('Assigned', 'Contacted', 'Qualified', 'Visit', 'Converted')
        GROUP BY lead_id
      ) fa ON fa.lead_id = l.id
      WHERE l.assigned_to IS NOT NULL AND l.deleted_at IS NULL
      GROUP BY l.assigned_to, DATE(l.created_at)
      ON DUPLICATE KEY UPDATE
        assigned     = VALUES(assigned),
        contacted    = VALUES(contacted),
        converted    = VALUES(converted),
        avg_resp_min = VALUES(avg_resp_min)
    ");

    $pdo->commit();

    // Success response
    if (php_sapi_name() === 'cli') {
        echo "Aggregation successfully completed.\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Aggregation successfully completed.']);
    }

} catch (\Exception $e) {
    // Roll back if transaction was started
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Log full details internally — never expose to client
    error_log('Aggregate error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());

    if (php_sapi_name() === 'cli') {
        echo "Aggregation failed. Check server error log for details.\n";
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
    }
}
