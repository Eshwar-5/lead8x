<?php
// backend/api/projects/save.php
// Create or update project (name + optional location)

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
$name        = Validator::sanitizeString($body['name'] ?? null, 100);
$description = Validator::sanitizeString($body['description'] ?? null, 500);
$id          = (int)($body['id'] ?? 0);

if (empty($name)) {
    Response::error('Project name is required.');
}

$pdo = Database::getConnection();

$stmt = $pdo->prepare("INSERT INTO projects (name, location) VALUES (?, ?) ON DUPLICATE KEY UPDATE location = VALUES(location)");
$stmt->execute([$name, $description ?: null]);
$newId = (int)$pdo->lastInsertId();

Response::success('Project saved.', ['id' => $newId, 'name' => $name, 'location' => $description]);
