<?php
declare(strict_types=1);
/**
 * Browser Push Notifications Plugin v1.2.1.
 */

if (!defined('BACKEND_PATH')) return;

const JYAVANI_PUSH_FALLBACK_ICON = '/static/plugins/browser-push/notification.png';
const JYAVANI_PUSH_MAX_TITLE = 180;
const JYAVANI_PUSH_MAX_BODY = 2000;
const JYAVANI_PUSH_MAX_URL = 500;
const JYAVANI_PUSH_MAX_ICON = 255;

function jyavani_push_ensure_schema(PDO $pdo): void {
    static $ready = [];
    $connectionId = spl_object_id($pdo);
    if (isset($ready[$connectionId])) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        endpoint VARCHAR(500) NOT NULL,
        endpoint_hash CHAR(64) NOT NULL,
        p256dh_key VARCHAR(255) NOT NULL,
        auth_key VARCHAR(255) NOT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_endpoint_hash (endpoint_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS push_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        url VARCHAR(500) DEFAULT NULL,
        icon VARCHAR(255) DEFAULT NULL,
        sent_count INT DEFAULT 0,
        fail_count INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS push_rate_limits (
        rate_key CHAR(64) PRIMARY KEY,
        window_start DATETIME NOT NULL,
        request_count INT UNSIGNED NOT NULL DEFAULT 1,
        INDEX rate_window (window_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (jyavani_push_setting($pdo, 'push_schema_version') !== '120') {
        jyavani_push_migrate_endpoint_identity($pdo);
        jyavani_push_cleanup_legacy_endpoints($pdo);
        jyavani_push_save_setting($pdo, 'push_schema_version', '120');
    }
    $ready[$connectionId] = true;
}

function jyavani_push_endpoint_hash(string $endpoint): string {
    return hash('sha256', $endpoint);
}

function jyavani_push_endpoint_index_plan(array $rows): array {
    $indexes = [];
    foreach ($rows as $row) {
        $name = is_array($row) ? (string)($row['Key_name'] ?? '') : '';
        if ($name === '' || $name === 'PRIMARY') continue;
        $indexes[$name][] = $row;
    }
    $drop = [];
    $hasHashUnique = false;
    foreach ($indexes as $name => $parts) {
        usort($parts, static fn(array $a, array $b): int => (int)($a['Seq_in_index'] ?? 0) <=> (int)($b['Seq_in_index'] ?? 0));
        $columns = array_map(static fn(array $row): string => (string)($row['Column_name'] ?? ''), $parts);
        $unique = (int)($parts[0]['Non_unique'] ?? 1) === 0;
        if ($unique && $columns === ['endpoint_hash']) $hasHashUnique = true;
        if ($unique && $columns === ['endpoint']) $drop[] = $name;
    }
    return ['drop' => $drop, 'has_hash_unique' => $hasHashUnique];
}

function jyavani_push_migrate_endpoint_identity(PDO $pdo): void {
    $columns = $pdo->query('SHOW COLUMNS FROM push_subscriptions')->fetchAll(PDO::FETCH_ASSOC);
    $hasHashColumn = false;
    $hashNeedsNotNull = false;
    foreach ($columns as $column) {
        if (($column['Field'] ?? '') === 'endpoint_hash') {
            $hasHashColumn = true;
            $hashNeedsNotNull = strtoupper((string)($column['Null'] ?? 'YES')) !== 'NO';
        }
    }
    if (!$hasHashColumn) {
        $pdo->exec('ALTER TABLE push_subscriptions ADD COLUMN endpoint_hash CHAR(64) NULL AFTER endpoint');
        $hashNeedsNotNull = true;
    }

    if ($hashNeedsNotNull) {
        $pdo->exec("UPDATE push_subscriptions SET endpoint_hash = SHA2(endpoint, 256) WHERE endpoint_hash IS NULL OR endpoint_hash = ''");
        $pdo->exec('UPDATE push_subscriptions older INNER JOIN push_subscriptions newer ON older.endpoint_hash = newer.endpoint_hash AND older.id < newer.id SET newer.is_active = GREATEST(newer.is_active, older.is_active)');
        $pdo->exec('DELETE older FROM push_subscriptions older INNER JOIN push_subscriptions newer ON older.endpoint_hash = newer.endpoint_hash AND older.id < newer.id');
    }

    $plan = jyavani_push_endpoint_index_plan($pdo->query('SHOW INDEX FROM push_subscriptions')->fetchAll(PDO::FETCH_ASSOC));
    foreach ($plan['drop'] as $name) {
        $pdo->exec('ALTER TABLE push_subscriptions DROP INDEX `' . str_replace('`', '``', $name) . '`');
    }
    if (!$plan['has_hash_unique']) {
        $pdo->exec('ALTER TABLE push_subscriptions ADD UNIQUE INDEX unique_endpoint_hash (endpoint_hash)');
    }
    if ($hashNeedsNotNull) $pdo->exec('ALTER TABLE push_subscriptions MODIFY endpoint_hash CHAR(64) NOT NULL');
}

function jyavani_push_setting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return is_string($value) ? $value : $default;
}

function jyavani_push_cleanup_legacy_endpoints(PDO $pdo): void {
    $rows = $pdo->query('SELECT id, endpoint FROM push_subscriptions WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
    $deactivate = $pdo->prepare('UPDATE push_subscriptions SET is_active = 0 WHERE id = ?');
    foreach ($rows as $row) {
        if (!jyavani_push_valid_endpoint((string)($row['endpoint'] ?? ''))) $deactivate->execute([(int)$row['id']]);
    }
}

function jyavani_push_settings(PDO $pdo): array {
    $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'push_%' OR `key` LIKE 'pwa_%'");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[(string)$row['key']] = (string)$row['value'];
    }
    return $settings;
}

function jyavani_push_save_setting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $stmt->execute([$key, $value]);
}

function jyavani_push_env(string $name): string {
    $value = getenv($name);
    return is_string($value) ? trim($value) : '';
}

function jyavani_push_vapid_settings(PDO $pdo): array {
    $settings = jyavani_push_settings($pdo);
    return [
        'public' => jyavani_push_env('BROWSER_PUSH_VAPID_PUBLIC_KEY') ?: ($settings['push_vapid_public_key'] ?? ''),
        'private' => jyavani_push_env('BROWSER_PUSH_VAPID_PRIVATE_KEY') ?: ($settings['push_vapid_private_key'] ?? ''),
        'subject' => jyavani_push_env('BROWSER_PUSH_VAPID_SUBJECT') ?: ($settings['push_vapid_subject'] ?? 'mailto:admin@example.com'),
        'settings' => $settings,
    ];
}

function jyavani_push_base64url_decode(string $value): string|false {
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) return false;
    $padding = (4 - strlen($value) % 4) % 4;
    return base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
}

function jyavani_push_valid_public_key(string $value): bool {
    $decoded = jyavani_push_base64url_decode($value);
    return is_string($decoded) && strlen($decoded) === 65 && $decoded[0] === "\x04";
}

function jyavani_push_valid_private_key(string $value): bool {
    $decoded = jyavani_push_base64url_decode($value);
    return is_string($decoded) && strlen($decoded) === 32;
}

function jyavani_push_normalize_private_key(string $value): string {
    if (jyavani_push_valid_private_key($value)) return $value;

    // v1.1 stored a base64-encoded PEM. Keep upgraded installations usable.
    $pem = base64_decode($value, true);
    if (!is_string($pem) || !str_contains($pem, 'BEGIN PRIVATE KEY')) return '';
    $key = openssl_pkey_get_private($pem);
    if ($key === false) return '';
    $details = openssl_pkey_get_details($key);
    $raw = $details['ec']['d'] ?? null;
    if (!is_string($raw) || strlen($raw) !== 32) return '';
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function jyavani_push_valid_subscription_key(string $value, int $bytes, bool $uncompressedPoint = false): bool {
    $decoded = jyavani_push_base64url_decode($value);
    return is_string($decoded)
        && strlen($decoded) === $bytes
        && (!$uncompressedPoint || $decoded[0] === "\x04");
}

function jyavani_push_valid_endpoint(string $endpoint): bool {
    if ($endpoint === '' || strlen($endpoint) > JYAVANI_PUSH_MAX_URL || filter_var($endpoint, FILTER_VALIDATE_URL) === false) return false;
    $parts = parse_url($endpoint);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass'])) return false;
    if (isset($parts['port']) && (int)$parts['port'] !== 443) return false;
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) return false;

    $providers = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'push.services.mozilla.com',
        'web.push.apple.com',
    ];
    if (in_array($host, $providers, true)) return true;
    return str_ends_with($host, '.push.apple.com') || str_ends_with($host, '.notify.windows.com');
}

function jyavani_push_canonical_origin(PDO $pdo): string {
    $siteUrl = function_exists('settings_get') ? (string)(settings_get($pdo, 'site_url', '') ?? '') : '';
    if ($siteUrl === '') $siteUrl = jyavani_push_setting($pdo, 'site_url');
    $parts = $siteUrl !== '' ? parse_url($siteUrl) : false;
    if (is_array($parts) && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        && (string)($parts['host'] ?? '') !== '') {
        $scheme = strtolower((string)$parts['scheme']);
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        $portNumber = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        $port = ($scheme === 'https' && $portNumber === 443) || ($scheme === 'http' && $portNumber === 80) ? '' : ':' . $portNumber;
        return $scheme . '://' . $host . $port;
    }

    $forceHttps = function_exists('env')
        ? in_array(strtolower((string)env('FORCE_HTTPS', '')), ['1', 'true', 'yes'], true)
        : in_array(strtolower(jyavani_push_env('FORCE_HTTPS')), ['1', 'true', 'yes'], true);
    $scheme = $forceHttps || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = strtolower(rtrim((string)($_SERVER['SERVER_NAME'] ?? ''), '.'));
    if ($host === '' || (!filter_var($host, FILTER_VALIDATE_IP) && preg_match('/^[a-z0-9.-]+$/', $host) !== 1)) return '';
    $port = (int)($_SERVER['SERVER_PORT'] ?? ($scheme === 'https' ? 443 : 80));
    return $scheme . '://' . $host . (in_array($port, [80, 443], true) ? '' : ':' . $port);
}

function jyavani_push_valid_asset_url(string $url): bool {
    if ($url === '' || strlen($url) > JYAVANI_PUSH_MAX_URL || str_contains($url, "\0")) return false;
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) return true;
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return false;
    $parts = parse_url($url);
    return is_array($parts) && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true);
}

function jyavani_push_valid_navigation_url(string $url): bool {
    if ($url === '') return true;
    if (strlen($url) > JYAVANI_PUSH_MAX_URL || !str_starts_with($url, '/') || str_starts_with($url, '//')
        || str_contains($url, '\\') || str_contains($url, '#') || preg_match('/[\x00-\x20\x7F]/', $url) === 1) return false;

    $probe = $url;
    for ($i = 0; $i < 4; $i++) {
        if (preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|2e|5c|7f)/i', $probe) === 1) return false;
        $decoded = rawurldecode($probe);
        if ($decoded === $probe) break;
        if (str_contains($decoded, '\\') || str_contains($decoded, '#') || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) return false;
        $probe = $decoded;
    }

    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'], $parts['fragment'])) return false;
    $path = (string)($parts['path'] ?? '');
    if (!str_starts_with($path, '/') || str_contains($path, '//') || preg_match('/%(?![0-9A-F]{2})/', $path) === 1) return false;
    $decodedPath = rawurldecode($path);
    if (!str_starts_with($decodedPath, '/') || str_contains($decodedPath, '//') || str_contains($decodedPath, '\\')) return false;

    $trailingSlash = $decodedPath !== '/' && str_ends_with($decodedPath, '/');
    $segments = $decodedPath === '/' ? [] : explode('/', trim($decodedPath, '/'));
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') return false;
    }
    $normalizedPath = '/' . implode('/', array_map('rawurlencode', $segments));
    if ($trailingSlash) $normalizedPath .= '/';
    if (!hash_equals($normalizedPath, $path)) return false;

    $query = $parts['query'] ?? null;
    return $query === null || (preg_match('/[\x00-\x20\x7F\\\\#]/', (string)$query) !== 1
        && preg_match('/%(?![0-9A-F]{2})/', (string)$query) !== 1);
}

function jyavani_push_notification_icon(array $settings): string {
    foreach (['pwa_icon_192_url', 'pwa_icon_512_url'] as $key) {
        $value = trim((string)($settings[$key] ?? ''));
        if (jyavani_push_valid_asset_url($value)) return $value;
    }
    return JYAVANI_PUSH_FALLBACK_ICON;
}

function jyavani_push_scalar(array $input, string $key, int $maxLength, bool $required = false): string {
    $value = $input[$key] ?? '';
    if (!is_scalar($value) && $value !== null) throw new InvalidArgumentException($key . ' must be a scalar value');
    $value = trim((string)$value);
    if ($required && $value === '') throw new InvalidArgumentException($key . ' is required');
    if (strlen($value) > $maxLength) throw new InvalidArgumentException($key . ' is too long');
    return $value;
}

function jyavani_push_payload(array $input): array {
    $title = jyavani_push_scalar($input, 'title', JYAVANI_PUSH_MAX_TITLE, true);
    $body = jyavani_push_scalar($input, 'body', JYAVANI_PUSH_MAX_BODY, true);
    $url = jyavani_push_scalar($input, 'url', JYAVANI_PUSH_MAX_URL);
    $icon = jyavani_push_scalar($input, 'icon', JYAVANI_PUSH_MAX_ICON);
    if (!jyavani_push_valid_navigation_url($url)) throw new InvalidArgumentException('url must be a same-origin absolute path');
    if ($icon !== '' && !jyavani_push_valid_asset_url($icon)) throw new InvalidArgumentException('icon is invalid');
    return compact('title', 'body', 'url', 'icon');
}

function jyavani_push_csrf_valid(mixed $token): bool {
    return is_string($token) && function_exists('csrf_check') && csrf_check($token);
}

function jyavani_push_json(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function jyavani_push_read_json(int $maxBytes = 8192): array {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > $maxBytes) throw new LengthException('Request payload is too large');
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if (!is_string($raw) || strlen($raw) > $maxBytes) throw new LengthException('Request payload is too large');
    try {
        $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new InvalidArgumentException('Invalid JSON payload');
    }
    if (!is_array($input)) throw new InvalidArgumentException('JSON object required');
    return $input;
}

function jyavani_push_run_node(string $input, int $timeoutSeconds = 15): array {
    if (!is_file(__DIR__ . '/node_modules/web-push/package.json')) {
        return ['ok' => false, 'status' => 0, 'error' => 'Missing web-push dependency'];
    }
    $node = jyavani_push_env('BROWSER_PUSH_NODE_BINARY') ?: 'node';
    if (strlen($node) > 255 || str_contains($node, "\0")) {
        return ['ok' => false, 'status' => 0, 'error' => 'Invalid Node.js binary'];
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = getenv();
    $env = is_array($env) ? $env : [];
    $process = proc_open([$node, __DIR__ . '/lib/push.js'], $descriptors, $pipes, __DIR__, $env);
    if (!is_resource($process)) return ['ok' => false, 'status' => 0, 'error' => 'Failed to start Node.js helper'];

    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutSeconds;
    $timedOut = false;
    $exitCode = -1;
    do {
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = (int)$status['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process);
            usleep(100000);
            $status = proc_get_status($process);
            if ($status['running']) proc_terminate($process, 9);
            break;
        }
        usleep(20000);
    } while (true);
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedCode = proc_close($process);
    if ($exitCode < 0) $exitCode = $closedCode;

    if ($timedOut) return ['ok' => false, 'status' => 0, 'error' => 'Push delivery timed out'];
    $result = json_decode($stdout, true);
    if (!is_array($result)) {
        $error = trim($stderr) ?: 'Invalid response from Node.js helper';
        return ['ok' => false, 'status' => 0, 'error' => substr($error, 0, 300)];
    }
    if ($exitCode !== 0 && ($result['ok'] ?? false) === true) {
        return ['ok' => false, 'status' => 0, 'error' => 'Node.js helper exited unexpectedly'];
    }
    return $result;
}

function jyavani_push_delivery_context(PDO $pdo, string $title, string $body, string $url = '', string $icon = ''): array {
    if (!jyavani_push_valid_navigation_url($url)) throw new InvalidArgumentException('url must be a normalized same-origin absolute path');
    $vapid = jyavani_push_vapid_settings($pdo);
    $privateKey = jyavani_push_normalize_private_key($vapid['private']);
    if (!jyavani_push_valid_public_key($vapid['public']) || $privateKey === '') return ['configured' => false];
    $notificationIcon = $icon ?: jyavani_push_notification_icon($vapid['settings']);
    return [
        'configured' => true,
        'payload' => [
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'icon' => $notificationIcon,
            'badge' => jyavani_push_notification_icon($vapid['settings']),
            'timestamp' => time() * 1000,
        ],
        'vapid_public' => $vapid['public'],
        'vapid_private' => $privateKey,
        'vapid_subject' => $vapid['subject'],
    ];
}

function jyavani_push_deactivate_subscription(PDO $pdo, array $subscription): void {
    if (isset($subscription['id']) && is_numeric($subscription['id'])) {
        $stmt = $pdo->prepare('UPDATE push_subscriptions SET is_active = 0 WHERE id = ?');
        $stmt->execute([(int)$subscription['id']]);
        return;
    }
    $endpoint = (string)($subscription['endpoint'] ?? '');
    $stmt = $pdo->prepare('UPDATE push_subscriptions SET is_active = 0 WHERE endpoint_hash = ? AND endpoint = ?');
    $stmt->execute([jyavani_push_endpoint_hash($endpoint), $endpoint]);
}

function jyavani_push_send(array $subscription, string $title, string $body, string $url = '', string $icon = '', ?PDO $pdo = null, ?array $delivery = null): bool {
    if (!$pdo) return false;
    jyavani_push_ensure_schema($pdo);
    $endpoint = (string)($subscription['endpoint'] ?? '');
    $storedHash = (string)($subscription['endpoint_hash'] ?? '');
    $p256dh = (string)($subscription['p256dh_key'] ?? '');
    $auth = (string)($subscription['auth_key'] ?? '');
    if (!jyavani_push_valid_endpoint($endpoint)
        || ($storedHash !== '' && !hash_equals(jyavani_push_endpoint_hash($endpoint), $storedHash))
        || !jyavani_push_valid_subscription_key($p256dh, 65, true)
        || !jyavani_push_valid_subscription_key($auth, 16)) {
        jyavani_push_deactivate_subscription($pdo, $subscription);
        error_log('[browser-push] Invalid stored subscription deactivated before delivery');
        return false;
    }
    $delivery ??= jyavani_push_delivery_context($pdo, $title, $body, $url, $icon);
    if (($delivery['configured'] ?? false) !== true) {
        error_log('[browser-push] Valid VAPID keys are not configured');
        return false;
    }

    $input = json_encode([
        'endpoint' => $endpoint,
        'p256dh' => $p256dh,
        'auth' => $auth,
        'payload' => $delivery['payload'],
        'vapidPublicKey' => $delivery['vapid_public'],
        'vapidPrivateKey' => $delivery['vapid_private'],
        'vapidSubject' => $delivery['vapid_subject'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $result = jyavani_push_run_node($input);
    if (($result['ok'] ?? false) !== true) {
        $status = (int)($result['status'] ?? 0);
        if (in_array($status, [404, 410], true)) {
            jyavani_push_deactivate_subscription($pdo, $subscription);
        }
        $error = trim((string)($result['error'] ?? 'Unknown push service error'));
        error_log('[browser-push] Send failed: HTTP ' . $status . ' (' . $error . ') for endpoint: ' . substr((string)$subscription['endpoint'], 0, 80));
        return false;
    }
    return true;
}

function jyavani_push_broadcast(PDO $pdo, string $title, string $body, string $url = '', string $icon = ''): array {
    jyavani_push_ensure_schema($pdo);
    $subscriptions = $pdo->query('SELECT * FROM push_subscriptions WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
    $delivery = jyavani_push_delivery_context($pdo, $title, $body, $url, $icon);
    $sent = 0;
    $failed = 0;
    foreach ($subscriptions as $subscription) {
        if (jyavani_push_send($subscription, $title, $body, $url, $icon, $pdo, $delivery)) $sent++;
        else $failed++;
    }
    $stmt = $pdo->prepare('INSERT INTO push_notifications (title, body, url, icon, sent_count, fail_count, sent_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$title, $body, $url, $icon, $sent, $failed]);
    return ['sent' => $sent, 'failed' => $failed, 'total' => count($subscriptions)];
}

if (php_sapi_name() !== 'cli') {
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) jyavani_push_ensure_schema($pdo);
}

if (function_exists('register_frontend_route')) {
    register_frontend_route('push-api', __DIR__ . '/public/api.php');
}

if (function_exists('add_action')) {
    add_action('init', function (): void {
        add_action('jy_head', function (): void {
            echo '<script>(function(){if(!("serviceWorker" in navigator))return;'
                . 'var p=window.JYAVANI_PUSH_WORKER||navigator.serviceWorker.register("/sw.js",{scope:"/"}).then(function(){return navigator.serviceWorker.ready});'
                . 'window.JYAVANI_PUSH_WORKER=p;p.catch(function(e){console.error("[browser-push] Service worker registration failed",e)});'
                . '})();</script>' . PHP_EOL;
        }, 1);
    });
}

if (function_exists('add_filter')) {
    add_filter('service_worker_script', function (string $script): string {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO)) return $script;
        $vapid = jyavani_push_vapid_settings($pdo);
        $config = json_encode([
            'vapidKey' => $vapid['public'],
            'subscribeUrl' => '/push-api/subscribe/',
            'unsubscribeUrl' => '/push-api/unsubscribe/',
            'fallbackIcon' => JYAVANI_PUSH_FALLBACK_ICON,
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        $worker = __DIR__ . '/public/sw.js';
        return is_file($worker) ? $script . "\nself.JYAVANI_PUSH_CONFIG = {$config};\n" . file_get_contents($worker) : $script;
    });

    add_filter('sidebar_widget_types', function (array $types): array {
        $types['push_subscribe'] = [
            'label' => 'Push Notifications',
            'desc' => 'Subscribe button for browser push notifications.',
            'default_config' => ['title' => 'Notifikasi'],
        ];
        return $types;
    });

    add_filter('render_sidebar_widget', function ($html, string $type, array $config, PDO $pdo): string {
        return $type === 'push_subscribe' ? jyavani_push_render_subscribe_widget($pdo, $config) : $html;
    }, 10, 4);
}

function jyavani_push_render_subscribe_widget(PDO $pdo, array $config): string {
    static $clientLoaded = false;
    $vapid = jyavani_push_vapid_settings($pdo);
    if (!jyavani_push_valid_public_key($vapid['public'])) return '';
    $title = function_exists('h') ? h((string)($config['title'] ?? 'Notifikasi')) : htmlspecialchars((string)($config['title'] ?? 'Notifikasi'), ENT_QUOTES, 'UTF-8');
    $attrs = ' data-vapid-key="' . htmlspecialchars($vapid['public'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-subscribe-url="/push-api/subscribe/" data-unsubscribe-url="/push-api/unsubscribe/"';
    $html = '<div class="w-box w-push-subscribe js-jyavani-push"' . $attrs . '>';
    $html .= '<h3 class="w-title">' . $title . '</h3>';
    $html .= '<p style="font-size:.85rem;color:var(--muted);margin:0 0 .75rem">Dapatkan notifikasi saat artikel baru terbit.</p>';
    $html .= '<div class="js-push-status" style="font-size:.8rem;color:var(--muted);margin-bottom:.5rem"></div>';
    $html .= '<button type="button" class="js-push-toggle" style="display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .9rem;border-radius:6px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;font-size:.85rem;font-family:inherit">';
    $html .= '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>';
    $html .= '<span class="js-push-button-text">Subscribe</span></button></div>';
    if (!$clientLoaded) {
        $html .= '<script src="/static/plugins/browser-push/push.js?v=1.2.1" defer></script>';
        $clientLoaded = true;
    }
    return $html;
}
