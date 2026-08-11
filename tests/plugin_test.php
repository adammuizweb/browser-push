<?php
declare(strict_types=1);

define('BACKEND_PATH', __DIR__);
function add_filter(string $name, callable $callback, int $priority = 10, int $args = 1): void {}
function add_action(string $name, callable $callback, int $priority = 10): void {
    $GLOBALS['test_actions'][$name][$priority][] = $callback;
}
function settings_get(PDO $pdo, string $key, ?string $default = null): ?string {
    return $key === 'site_url' ? ($GLOBALS['test_site_url'] ?? $default) : $default;
}
final class BrowserPushTestPdo extends PDO {
    public function __construct() {}
}
require_once __DIR__ . '/../plugin.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$public = rtrim(strtr(base64_encode("\x04" . str_repeat("\x01", 64)), '+/', '-_'), '=');
$private = rtrim(strtr(base64_encode(str_repeat("\x02", 32)), '+/', '-_'), '=');
$auth = rtrim(strtr(base64_encode(str_repeat("\x03", 16)), '+/', '-_'), '=');
$pdo = new BrowserPushTestPdo();

check(isset($GLOBALS['test_actions']['init']), 'Core init worker registration hook was not registered');
foreach ($GLOBALS['test_actions']['init'] as $callbacks) foreach ($callbacks as $callback) $callback();
ob_start();
foreach ($GLOBALS['test_actions']['jy_head'] as $callbacks) foreach ($callbacks as $callback) $callback();
$headScript = (string)ob_get_clean();
check(str_contains($headScript, 'navigator.serviceWorker.register("/sw.js"'), 'Worker was not registered from the public head');

check(jyavani_push_valid_public_key($public), 'Valid VAPID public key rejected');
check(jyavani_push_valid_private_key($private), 'Valid VAPID private key rejected');
check(jyavani_push_valid_subscription_key($public, 65, true), 'Valid p256dh key rejected');
check(jyavani_push_valid_subscription_key($auth, 16), 'Valid auth key rejected');
check(jyavani_push_valid_endpoint('https://fcm.googleapis.com/fcm/send/example'), 'FCM endpoint rejected');
check(jyavani_push_valid_endpoint('https://updates.push.services.mozilla.com/wpush/v2/example'), 'Mozilla endpoint rejected');
check(jyavani_push_valid_endpoint('https://web.push.apple.com/example'), 'Apple endpoint rejected');
check(jyavani_push_valid_endpoint('https://wns2-bl2p.notify.windows.com/w/?token=x'), 'WNS endpoint rejected');
check(!jyavani_push_valid_endpoint('https://127.0.0.1/push'), 'Private endpoint accepted');
check(!jyavani_push_valid_endpoint('https://example.com/push'), 'Unknown endpoint provider accepted');
$validNavigationUrls = ['', '/', '/article/', '/search/?q=push', '/hello%20world/', '/caf%C3%A9/'];
foreach ($validNavigationUrls as $navigationUrl) {
    check(jyavani_push_valid_navigation_url($navigationUrl), 'Valid navigation URL rejected: ' . $navigationUrl);
}
$invalidNavigationUrls = [
    'https://example.com/article/', '//example.com/article/', '/article/#part', '/article\\edit/',
    '/article%5Cedit/', '/article%255cedit/', '/../admin/', '/%2E%2E/admin/', '/%252e%252e/admin/',
    "/article/\nnext", '/article/%0A', '/article/%2500', '/article//edit/', '/article/./edit/',
    '/article%2Fedit/', '/article/%ZZ', '/search/?next=%2e%2e', '/literal space/',
];
foreach ($invalidNavigationUrls as $navigationUrl) {
    check(!jyavani_push_valid_navigation_url($navigationUrl), 'Unsafe navigation URL accepted: ' . $navigationUrl);
}
$GLOBALS['test_site_url'] = 'https://Example.com:443/blog/';
check(jyavani_push_canonical_origin($pdo) === 'https://example.com', 'Canonical CMS origin was not normalized');
check(strlen(jyavani_push_endpoint_hash('https://fcm.googleapis.com/fcm/send/example')) === 64, 'Endpoint identity is not a full SHA-256 hash');

$legacyPlan = jyavani_push_endpoint_index_plan([
    ['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'id'],
    ['Key_name' => 'unique_endpoint', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'endpoint', 'Sub_part' => 200],
]);
check($legacyPlan['drop'] === ['unique_endpoint'] && !$legacyPlan['has_hash_unique'], 'Legacy prefix index migration was not planned');
$compositePlan = jyavani_push_endpoint_index_plan([
    ['Key_name' => 'custom_identity', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'endpoint'],
    ['Key_name' => 'custom_identity', 'Non_unique' => 0, 'Seq_in_index' => 2, 'Column_name' => 'user_agent'],
]);
check($compositePlan['drop'] === [], 'Migration would drop a non-legacy composite index');
$migratedPlan = jyavani_push_endpoint_index_plan([
    ['Key_name' => 'unique_endpoint_hash', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'endpoint_hash'],
]);
check($migratedPlan['drop'] === [] && $migratedPlan['has_hash_unique'], 'Migrated endpoint hash index was not recognized');

$settings = ['pwa_icon_512_url' => '/static/pwa/icon-512.png'];
check(jyavani_push_notification_icon($settings) === '/static/pwa/icon-512.png', 'PWA URL icon setting was not consumed');

$nodeResult = jyavani_push_run_node('{}', 2);
check(($nodeResult['ok'] ?? true) === false, 'Node helper did not report malformed input as failure');

try {
    jyavani_push_payload(['title' => ['invalid'], 'body' => 'body']);
    throw new RuntimeException('Array payload was accepted');
} catch (InvalidArgumentException) {
}

fwrite(STDOUT, "plugin tests passed\n");
