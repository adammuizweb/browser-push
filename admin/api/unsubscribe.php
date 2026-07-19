<?php
declare(strict_types=1);
/**
 * API: Unsubscribe from push notifications
 * POST /admin/tools/push-notifications/api/unsubscribe
 * Body: JSON { endpoint }
 */
require_once __DIR__ . '/../../plugin.php';

header('Content-Type: application/json');

global $pdo;
if (!isset($pdo)) { http_response_code(500); echo json_encode(['error' => 'DB not available']); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['endpoint'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing endpoint']);
    exit;
}

$stmt = $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = ?");
$stmt->execute([$input['endpoint']]);

echo json_encode(['ok' => true, 'message' => 'Unsubscribed']);
