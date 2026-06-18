<?php
// backend/api/filters/devices.php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Response::setCorsHeaders();

try {
    // Will automatically extract Bearer token, validate it, and reject 401 if invalid
    $user = Auth::requireAuth();

    $pdo = Database::getConnection();
    // Normalize during fetch and treat empty as "Unknown Device"
    $stmt = $pdo->query("
        SELECT DISTINCT 
            COALESCE(NULLIF(TRIM(device), ''), 'Unknown Device') as name
        FROM leads 
        WHERE deleted_at IS NULL
        ORDER BY name ASC
    ");
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Response::success('Devices loaded', ['devices' => $devices]);

} catch (PDOException $e) {
    Response::error('Database error', 500);
}
