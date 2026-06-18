<?php
// backend/api/webhooks/settings.php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Response::setCorsHeaders();
$user = Auth::requireAuth(['Admin']);
$pdo  = Database::getConnection();

require_once dirname(__DIR__, 2) . '/utils/Encryption.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Mask secrets on retrieval
    $stmt = $pdo->query("SELECT id, platform, source_name, is_active, verify_token, created_at FROM webhook_sources ORDER BY platform");
    $sources = $stmt->fetchAll();
    
    foreach ($sources as &$s) {
        $s['app_secret']  = '••••••••';
        $s['graph_token'] = '••••••••';
    }

    Response::success('Webhook sources retrieved.', ['sources' => $sources]);
} 
elseif ($method === 'POST') {
    $rawBody = file_get_contents('php://input');
    $body = json_decode($rawBody, true);
    
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
        Response::error('Invalid request body.', 400);
        exit;
    }

    $id           = $body['id'] ?? null;
    $platform     = $body['platform'] ?? '';
    $source_name  = $body['source_name'] ?? '';
    $is_active    = (int)($body['is_active'] ?? 1);
    $verify_token = $body['verify_token'] ?? '';
    
    try {
        // Encrypt sensitive values if they are NOT the masking placeholder
        $app_secret = !empty($body['app_secret']) && $body['app_secret'] !== '••••••••' 
            ? Encryption::encrypt((string)$body['app_secret']) 
            : null;
        $graph_token = !empty($body['graph_token']) && $body['graph_token'] !== '••••••••' 
            ? Encryption::encrypt((string)$body['graph_token']) 
            : null;

        if (empty($platform) || empty($source_name)) {
            Response::error('Platform and Source Name are required.');
            exit;
        }

        if ($id) {
            // UPDATE: Handle conditional secret update
            $sql = "UPDATE webhook_sources SET platform=?, source_name=?, verify_token=?, is_active=?";
            $params = [$platform, $source_name, $verify_token, $is_active];
            
            if ($app_secret !== null) {
                $sql .= ", app_secret=?";
                $params[] = $app_secret;
            }
            if ($graph_token !== null) {
                $sql .= ", graph_token=?";
                $params[] = $graph_token;
            }
            
            $sql .= " WHERE id=?";
            $params[] = $id;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            Response::success('Webhook source updated.');
        } else {
            // INSERT
            $stmt = $pdo->prepare(
                "INSERT INTO webhook_sources (platform, source_name, verify_token, app_secret, graph_token, is_active) VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$platform, $source_name, $verify_token, $app_secret, $graph_token, $is_active]);
            Response::success('Webhook source created.', ['id' => $pdo->lastInsertId()]);
        }
    } catch (Throwable $e) {
        error_log("Settings Error: " . $e->getMessage());
        Response::error($e->getMessage(), 500);
    }
} 
elseif ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        Response::error('ID is required for deletion.', 400);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM webhook_sources WHERE id = ?");
    $stmt->execute([$id]);
    
    if ($stmt->rowCount() > 0) {
        Response::success('Webhook source deleted.');
    } else {
        Response::error('Webhook source not found.', 404);
    }
} 
else {
    header('Allow: GET, POST, DELETE');
    Response::error('Method not allowed.', 405);
}
exit;
