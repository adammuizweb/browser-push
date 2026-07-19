<?php
declare(strict_types=1);
/**
 * API: Send test push notification
 * POST /admin/tools/push-notifications/api/test
 */
require_once __DIR__ . '/../../plugin.php';

header('Content-Type: application/json');

global $pdo;
if (!isset($pdo)) { http_response_code(500); echo json_encode(['error' => 'DB not available']); exit; }
jyavani_push_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$result = jyavani_push_broadcast($pdo, 'Test Notification', 'Push notifications are working! This is a test from Jyavani CMS.', '', '');

echo json_encode(['ok' => true, 'result' => $result]);
