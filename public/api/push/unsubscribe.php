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
if (!preg_match('#^https://#', $endpoint) || strlen($endpoint) > 500) {
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
$stmt = $pdo->prepare('UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = ?');
$stmt->execute([$endpoint]);
echo json_encode(['ok' => true]);
