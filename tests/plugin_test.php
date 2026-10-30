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

$manifest = json_decode((string)file_get_contents(__DIR__ . '/../plugin.json'), true, 32, JSON_THROW_ON_ERROR);
$package = json_decode((string)file_get_contents(__DIR__ . '/../package.json'), true, 32, JSON_THROW_ON_ERROR);
$lock = json_decode((string)file_get_contents(__DIR__ . '/../package-lock.json'), true, 64, JSON_THROW_ON_ERROR);
check(($manifest['version'] ?? null) === '1.3.0' && ($package['version'] ?? null) === '1.3.0'
    && ($lock['version'] ?? null) === '1.3.0' && ($lock['packages']['']['version'] ?? null) === '1.3.0',
    'Plugin and Node runtime versions are not aligned');
check(is_file(__DIR__ . '/../node_modules/web-push/package.json')
    && !str_contains((string)file_get_contents(__DIR__ . '/../install.sh'), 'npm ci'),
    'Release must vendor its immutable production Node runtime');

check(isset($GLOBALS['test_actions']['init']), 'Core init worker registration hook was not registered');
check(isset($GLOBALS['test_actions']['content_status_published']), 'Core publication observer was not registered');
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

check(jyavani_push_article_delivery_state(['sent' => 0, 'failed' => 0, 'total' => 0]) === 'no_subscribers', 'Empty delivery state was not terminal');
check(jyavani_push_article_delivery_state(['sent' => 3, 'failed' => 0, 'total' => 3]) === 'delivered', 'Successful delivery state was not recognized');
check(jyavani_push_article_delivery_state(['sent' => 2, 'failed' => 1, 'total' => 3]) === 'partial', 'Partial delivery state was not recognized');
check(jyavani_push_article_delivery_state(['sent' => 0, 'failed' => 3, 'total' => 3]) === 'failed', 'Failed delivery state was not recognized');

$migration = (string)file_get_contents(__DIR__ . '/../migrations/0001-automatic-article-publications.sql');
check(str_contains($migration, 'PRIMARY KEY (article_id)')
    && str_contains($migration, "VALUES ('push_auto_notify_articles', '0')"),
    'Automatic publication migration is not idempotent and opt-in');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sqlite->sqliteCreateFunction('UTC_TIMESTAMP', static fn(...$arguments): string => '2026-10-30 12:00:00.000000');
    $sqlite->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT NOT NULL)');
    $sqlite->exec("INSERT INTO settings VALUES ('push_auto_notify_articles', '0')");
    $sqlite->exec('CREATE TABLE push_article_publications (
        article_id INTEGER PRIMARY KEY, event_id TEXT UNIQUE, status_revision INTEGER NOT NULL,
        state TEXT NOT NULL, sent_count INTEGER NOT NULL, fail_count INTEGER NOT NULL,
        total_count INTEGER NOT NULL, error TEXT, claimed_at_utc TEXT NOT NULL, finished_at_utc TEXT
    )');
    $event = [
        'schema' => 1,
        'event_id' => str_repeat('a', 64),
        'occurred_at' => '2026-10-30T12:00:00Z',
        'content_type' => 'article',
        'content_id' => 41,
        'actor_id' => 7,
        'source' => 'admin.posts.edit',
        'previous_status' => 'draft',
        'status_revision' => 2,
    ];
    jyavani_push_handle_content_published($event, $sqlite);
    jyavani_push_handle_content_published($event, $sqlite);
    $ledger = $sqlite->query('SELECT article_id, state FROM push_article_publications')->fetchAll(PDO::FETCH_ASSOC);
    check($ledger === [['article_id' => 41, 'state' => 'disabled']], 'Disabled publication events were not retained idempotently');

    $invalid = array_replace($event, ['event_id' => str_repeat('b', 64), 'content_id' => 42, 'previous_status' => 'published']);
    jyavani_push_handle_content_published($invalid, $sqlite);
    check((int)$sqlite->query('SELECT COUNT(*) FROM push_article_publications')->fetchColumn() === 1, 'Malformed publication event consumed an Article claim');

    $claimed = array_replace($event, ['event_id' => str_repeat('c', 64), 'content_id' => 43, 'status_revision' => 4]);
    check(jyavani_push_claim_article_publication($sqlite, $claimed, true), 'First automatic Article claim was rejected');
    check(!jyavani_push_claim_article_publication($sqlite, $claimed, true), 'Duplicate automatic Article claim was accepted');
    jyavani_push_finish_article_publication($sqlite, 43, 'delivered', 3, 0, 3);
    $finished = $sqlite->query('SELECT state, sent_count, fail_count, total_count FROM push_article_publications WHERE article_id = 43')->fetch(PDO::FETCH_ASSOC);
    check($finished === ['state' => 'delivered', 'sent_count' => 3, 'fail_count' => 0, 'total_count' => 3], 'Automatic Article claim did not reach its terminal delivery result');
}

fwrite(STDOUT, "plugin tests passed\n");
