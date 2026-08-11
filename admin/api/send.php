<?php
declare(strict_types=1);
require_once __DIR__ . '/../../plugin.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) jyavani_push_json(['ok' => false, 'error' => 'Database not available'], 500);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jyavani_push_json(['ok' => false, 'error' => 'Method not allowed'], 405);

try {
    $input = jyavani_push_read_json();
    if (!jyavani_push_csrf_valid($input['csrf_token'] ?? null)) jyavani_push_json(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
    $payload = jyavani_push_payload($input);
} catch (LengthException $error) {
    jyavani_push_json(['ok' => false, 'error' => $error->getMessage()], 413);
} catch (InvalidArgumentException $error) {
    jyavani_push_json(['ok' => false, 'error' => $error->getMessage()], 400);
}

jyavani_push_ensure_schema($pdo);
$result = jyavani_push_broadcast($pdo, $payload['title'], $payload['body'], $payload['url'], $payload['icon']);
$ok = $result['total'] > 0 && $result['failed'] === 0;
$error = $result['total'] === 0 ? 'There are no active subscribers' : ($result['failed'] > 0 ? 'One or more deliveries failed' : '');
$status = $ok ? 200 : ($result['total'] === 0 ? 409 : 502);
jyavani_push_json(['ok' => $ok, 'error' => $error, 'result' => $result], $status);
