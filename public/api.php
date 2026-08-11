<?php
declare(strict_types=1);

function jyavani_push_public_error(string $message, int $status): never {
    jyavani_push_json(['ok' => false, 'error' => $message], $status);
}

function jyavani_push_request_is_same_origin(PDO $pdo): bool {
    $site = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($site !== '' && $site !== 'same-origin') return false;
    $source = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($source === '') $source = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($source === '' || $source === 'null') return false;
    $parts = parse_url($source);
    if (!is_array($parts)) return false;
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') return false;
    $portNumber = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    $port = ($scheme === 'https' && $portNumber === 443) || ($scheme === 'http' && $portNumber === 80) ? '' : ':' . $portNumber;
    $sourceOrigin = $scheme . '://' . $host . $port;
    $canonicalOrigin = jyavani_push_canonical_origin($pdo);
    return $canonicalOrigin !== '' && hash_equals($canonicalOrigin, $sourceOrigin);
}

function jyavani_push_rate_counter(PDO $pdo, string $identity, int $limit, int $windowSeconds = 300): bool {
    $window = intdiv(time(), $windowSeconds) * $windowSeconds;
    $key = hash('sha256', $identity . "\0" . $window);
    $stmt = $pdo->prepare('INSERT INTO push_rate_limits (rate_key, window_start, request_count) VALUES (?, FROM_UNIXTIME(?), 1) ON DUPLICATE KEY UPDATE request_count = request_count + 1');
    $stmt->execute([$key, $window]);
    $stmt = $pdo->prepare('SELECT request_count FROM push_rate_limits WHERE rate_key = ?');
    $stmt->execute([$key]);
    if (random_int(1, 100) === 1) {
        $pdo->exec('DELETE FROM push_rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY');
    }
    return (int)$stmt->fetchColumn() <= $limit;
}

function jyavani_push_rate_limit(PDO $pdo, string $action, string $endpoint): bool {
    $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $endpointAllowed = jyavani_push_rate_counter($pdo, 'endpoint:' . $action . ':' . jyavani_push_endpoint_hash($endpoint), 30);
    $ipAllowed = jyavani_push_rate_counter($pdo, 'ip:' . $action . ':' . $remoteAddress, 1000);
    return $endpointAllowed && $ipAllowed;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    jyavani_push_public_error('Method not allowed', 405);
}
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') jyavani_push_public_error('JSON request required', 415);

$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) jyavani_push_public_error('Database not available', 500);
if (!jyavani_push_request_is_same_origin($pdo)) jyavani_push_public_error('Same-origin request required', 403);
jyavani_push_ensure_schema($pdo);

$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
if (!str_ends_with($path, '/')) jyavani_push_public_error('Not found', 404);
$segments = explode('/', trim($path, '/'));
$action = count($segments) === 2 && $segments[0] === 'push-api' ? $segments[1] : '';
if (!in_array($action, ['subscribe', 'unsubscribe'], true)) jyavani_push_public_error('Not found', 404);
try {
    $input = jyavani_push_read_json(4096);
    $endpoint = jyavani_push_scalar($input, 'endpoint', JYAVANI_PUSH_MAX_URL, true);
} catch (LengthException $error) {
    jyavani_push_public_error($error->getMessage(), 413);
} catch (InvalidArgumentException $error) {
    jyavani_push_public_error($error->getMessage(), 400);
}
if (!jyavani_push_valid_endpoint($endpoint)) jyavani_push_public_error('Invalid push endpoint', 400);
if (!jyavani_push_rate_limit($pdo, $action, $endpoint)) jyavani_push_public_error('Too many requests', 429);

if ($action === 'unsubscribe') {
    $stmt = $pdo->prepare('UPDATE push_subscriptions SET is_active = 0 WHERE endpoint_hash = ? AND endpoint = ?');
    $stmt->execute([jyavani_push_endpoint_hash($endpoint), $endpoint]);
    jyavani_push_json(['ok' => true]);
}

$keys = $input['keys'] ?? null;
if (!is_array($keys)) jyavani_push_public_error('Invalid subscription keys', 400);
$p256dh = $keys['p256dh'] ?? '';
$auth = $keys['auth'] ?? '';
if (!is_string($p256dh) || !jyavani_push_valid_subscription_key($p256dh, 65, true)
    || !is_string($auth) || !jyavani_push_valid_subscription_key($auth, 16)) {
    jyavani_push_public_error('Invalid subscription keys', 400);
}
$oldEndpoint = $input['oldEndpoint'] ?? '';
if (!is_string($oldEndpoint) || strlen($oldEndpoint) > JYAVANI_PUSH_MAX_URL
    || ($oldEndpoint !== '' && !jyavani_push_valid_endpoint($oldEndpoint))) {
    jyavani_push_public_error('Invalid previous endpoint', 400);
}

$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
$remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ipAddress = filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false ? $remoteAddress : '';
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO push_subscriptions (endpoint, endpoint_hash, p256dh_key, auth_key, user_agent, ip_address, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE endpoint = VALUES(endpoint), is_active = 1, p256dh_key = VALUES(p256dh_key), auth_key = VALUES(auth_key), user_agent = VALUES(user_agent), ip_address = VALUES(ip_address), updated_at = NOW()');
    $stmt->execute([$endpoint, jyavani_push_endpoint_hash($endpoint), $p256dh, $auth, $userAgent, $ipAddress]);
    if ($oldEndpoint !== '' && !hash_equals($endpoint, $oldEndpoint)) {
        $stmt = $pdo->prepare('UPDATE push_subscriptions SET is_active = 0 WHERE endpoint_hash = ? AND endpoint = ?');
        $stmt->execute([jyavani_push_endpoint_hash($oldEndpoint), $oldEndpoint]);
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[browser-push] Subscription update failed: ' . $error->getMessage());
    jyavani_push_public_error('Subscription could not be saved', 500);
}
jyavani_push_json(['ok' => true]);
