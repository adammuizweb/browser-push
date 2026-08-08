<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database not available']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$endpoint = (string)($input['endpoint'] ?? '');
if (!preg_match('#^https://#', $endpoint) || strlen($endpoint) > 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid subscription data']);
    exit;
}

$path = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
$action = explode('/', $path)[1] ?? '';
if ($action === 'subscribe') {
    $p256dh = (string)($input['keys']['p256dh'] ?? '');
    $auth = (string)($input['keys']['auth'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{1,255}$/', $p256dh) || !preg_match('/^[A-Za-z0-9_-]{1,255}$/', $auth)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid subscription data']);
        exit;
    }
    jyavani_push_ensure_schema($pdo);
    $stmt = $pdo->prepare('INSERT INTO push_subscriptions (endpoint, p256dh_key, auth_key, user_agent, ip_address, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE is_active = 1, p256dh_key = VALUES(p256dh_key), auth_key = VALUES(auth_key), user_agent = VALUES(user_agent), updated_at = NOW()');
    $stmt->execute([$endpoint, $p256dh, $auth, $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '']);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'unsubscribe') {
    $stmt = $pdo->prepare('UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = ?');
    $stmt->execute([$endpoint]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']);
