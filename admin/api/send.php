<?php
declare(strict_types=1);
/**
 * API: Send push notification to all subscribers
 * POST /admin/tools/push-notifications/api/send
 * Body: JSON { title, body, url?, icon? }
 * 
 * This endpoint is used by the article scheduler to send notifications
 * after a new article is published.
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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['title']) || empty($input['body'])) {
    http_response_code(400);
    echo json_encode(['error' => 'title and body are required']);
    exit;
}

$title = $input['title'];
$body = $input['body'];
$url = $input['url'] ?? '';
$icon = $input['icon'] ?? '';

$result = jyavani_push_broadcast($pdo, $title, $body, $url, $icon);

echo json_encode([
    'ok' => true,
    'result' => $result,
]);
