<?php
declare(strict_types=1);
/**
 * API: Subscribe to push notifications
 * POST /admin/tools/push-notifications/api/subscribe
 * Body: JSON { endpoint, keys: { p256dh, auth } }
 */
require_once __DIR__ . '/../../plugin.php';

header('Content-Type: application/json');

global $pdo;
if (!isset($pdo)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database not available']);
    exit;
}

jyavani_push_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['endpoint']) || empty($input['keys']['p256dh']) || empty($input['keys']['auth'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid subscription data']);
    exit;
}

$endpoint = $input['endpoint'];
$p256dh = $input['keys']['p256dh'];
$auth = $input['keys']['auth'];
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

// Upsert subscription
$stmt = $pdo->prepare("INSERT INTO push_subscriptions (endpoint, p256dh_key, auth_key, user_agent, ip_address, is_active, created_at)
    VALUES (?, ?, ?, ?, ?, 1, NOW())
    ON DUPLICATE KEY UPDATE is_active = 1, p256dh_key = VALUES(p256dh_key), auth_key = VALUES(auth_key), user_agent = VALUES(user_agent), updated_at = NOW()");
$stmt->execute([$endpoint, $p256dh, $auth, $userAgent, $ipAddress]);

echo json_encode(['ok' => true, 'message' => 'Subscribed successfully']);
