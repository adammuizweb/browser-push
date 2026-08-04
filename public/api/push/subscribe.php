<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/app/bootstrap_core.php';
require_once $root . '/plugins/index.php';
plugin_load_active();

header('Content-Type: application/json; charset=utf-8');
if (!plugin_is_active('browser-push') || !function_exists('jyavani_push_ensure_schema')) {
    http_response_code(404);
    echo json_encode(['error' => 'Push notifications are not available']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$endpoint = (string)($input['endpoint'] ?? '');
$p256dh = (string)($input['keys']['p256dh'] ?? '');
$auth = (string)($input['keys']['auth'] ?? '');
if (!preg_match('#^https://#', $endpoint) || strlen($endpoint) > 500 || !preg_match('/^[A-Za-z0-9_-]{1,255}$/', $p256dh) || !preg_match('/^[A-Za-z0-9_-]{1,255}$/', $auth)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid subscription data']);
    exit;
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database not available']);
    exit;
}
jyavani_push_ensure_schema($pdo);
$stmt = $pdo->prepare('INSERT INTO push_subscriptions (endpoint, p256dh_key, auth_key, user_agent, ip_address, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE is_active = 1, p256dh_key = VALUES(p256dh_key), auth_key = VALUES(auth_key), user_agent = VALUES(user_agent), updated_at = NOW()');
$stmt->execute([$endpoint, $p256dh, $auth, $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '']);
echo json_encode(['ok' => true]);
