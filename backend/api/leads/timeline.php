<?php
// backend/api/leads/timeline.php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/utils/Response.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Response::setCorsHeaders();

$user = Auth::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$leadId = (int)($_GET['lead_id'] ?? 0);
if (!$leadId) Response::error('lead_id is required.');

$pdo = Database::getConnection();

// Verify lead exists
$lead = $pdo->prepare("SELECT id, phone, name, assigned_to FROM leads WHERE id = ? LIMIT 1");
$lead->execute([$leadId]);
$leadData = $lead->fetch();
if (!$leadData) Response::notFound('Lead not found.');

if (in_array($user['role'], ['Caller', 'Relationship Manager'], true)) {
    if ($leadData['assigned_to'] != $user['id']) {
        Response::error('Access denied to this lead.', 403);
    }
}

// Get timeline events
$stmt = $pdo->prepare(
    "SELECT id, event_type, description, actor_name, old_value, new_value, created_at
     FROM lead_timeline
     WHERE lead_id = ?
     ORDER BY created_at ASC"
);
$stmt->execute([$leadId]);
$timeline = $stmt->fetchAll();

// Get all sources
$srcStmt = $pdo->prepare(
    "SELECT ls.source_name, ls.campaign, ls.batch_id, ls.uploaded_at, u.name AS uploaded_by
     FROM lead_sources ls
     LEFT JOIN users u ON ls.uploaded_by = u.id
     WHERE ls.lead_id = ?
     ORDER BY ls.uploaded_at ASC"
);
$srcStmt->execute([$leadId]);
$sources = $srcStmt->fetchAll();

Response::success('OK', [
    'lead'     => $leadData,
    'timeline' => $timeline,
    'sources'  => $sources,
]);
