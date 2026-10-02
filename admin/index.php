<?php
declare(strict_types=1);
require_once __DIR__ . '/../plugin.php';
if (!defined('DASHBOARD_CONTEXT')) { http_response_code(403); exit('Forbidden'); }

global $pdo;
[$uid] = adiwira_require_permission($pdo, 'plugin.browser-push.notifications.read', false);
$canSend = user_can($pdo, $uid, 'plugin.browser-push.notifications.send');
$canManage = user_can($pdo, $uid, 'plugin.browser-push.settings.manage');
jyavani_push_ensure_schema($pdo);

// Stats
$totalSubs = (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE is_active = 1")->fetchColumn();
$totalNotifications = (int)$pdo->query("SELECT COUNT(*) FROM push_notifications")->fetchColumn();
$recentNotifications = $pdo->query("SELECT * FROM push_notifications ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$vapid = jyavani_push_vapid_settings($pdo);
$vapidConfigured = jyavani_push_valid_public_key($vapid['public']) && jyavani_push_normalize_private_key($vapid['private']) !== '';
$runtimeConfigured = is_file(__DIR__ . '/../node_modules/web-push/package.json');
$csrf = function_exists('csrf_token') ? csrf_token() : '';
$base = ADMIN_BASE_PATH;
?>
<div class="push-admin">
  <header class="push-hero">
    <div class="push-hero__intro">
      <span class="push-hero__mark" aria-hidden="true"><?= svg_ico('bell') ?></span>
      <div>
        <span class="push-eyebrow">Audience delivery</span>
        <h2>Push Notifications</h2>
        <p>Reach subscribed readers directly from your Jyavani dashboard.</p>
      </div>
    </div>
    <div class="push-hero__actions">
      <?php if (!$vapidConfigured && $canManage): ?>
        <a href="<?= e($base) ?>/?page=admin/tools/push-notifications/settings" class="push-btn push-btn--primary"><?= svg_ico('lock') ?> Configure VAPID Keys</a>
      <?php endif; ?>
      <?php if ($canSend): ?><a href="<?= e($base) ?>/?page=admin/tools/push-notifications/send" class="push-btn push-btn--primary"><?= svg_ico('bell') ?> Send Notification</a><?php endif; ?>
      <?php if ($canManage): ?><a href="<?= e($base) ?>/?page=admin/tools/push-notifications/settings" class="push-btn"><?= svg_ico('cog') ?> Settings</a><?php endif; ?>
    </div>
  </header>

  <?php if (!$vapidConfigured): ?>
    <div class="push-notice push-notice--warning" role="status">
      <span class="push-notice__icon" aria-hidden="true"><?= svg_ico('lock') ?></span>
      <div><strong>VAPID keys are not configured.</strong>
      <?php if ($canManage): ?><span>Open <a href="<?= e($base) ?>/?page=admin/tools/push-notifications/settings">Settings</a> to connect secure browser delivery.</span><?php endif; ?></div>
    </div>
  <?php endif; ?>

  <?php if (!$runtimeConfigured): ?>
    <div class="push-notice push-notice--danger" role="alert">
      <span class="push-notice__icon" aria-hidden="true"><?= svg_ico('zap') ?></span>
      <div><strong>Push delivery runtime is missing.</strong><span>Run <code>npm ci --omit=dev</code> in the browser-push plugin directory.</span></div>
    </div>
  <?php endif; ?>

  <section class="push-stats" aria-label="Push notification overview">
    <article class="push-stat">
      <span class="push-stat__icon" aria-hidden="true"><?= svg_ico('users') ?></span>
      <div><strong class="push-stat__value"><?= $totalSubs ?></strong><span class="push-stat__label">Active subscribers</span></div>
    </article>
    <article class="push-stat">
      <span class="push-stat__icon push-stat__icon--violet" aria-hidden="true"><?= svg_ico('bell') ?></span>
      <div><strong class="push-stat__value"><?= $totalNotifications ?></strong><span class="push-stat__label">Notifications sent</span></div>
    </article>
    <article class="push-stat">
      <span class="push-stat__icon <?= $vapidConfigured ? 'push-stat__icon--success' : 'push-stat__icon--warning' ?>" aria-hidden="true"><?= svg_ico('lock') ?></span>
      <div><strong class="push-stat__value push-stat__value--status"><?= $vapidConfigured ? 'Ready' : 'Setup needed' ?></strong><span class="push-stat__label">Secure delivery</span></div>
    </article>
  </section>

  <section class="push-panel">
    <div class="push-panel__header">
      <div>
        <span class="push-eyebrow">Delivery history</span>
        <h3>Recent Notifications</h3>
        <p>Your latest broadcasts and delivery results.</p>
      </div>
      <?php if ($canSend): ?><button onclick="testPush()" class="push-btn push-btn--quiet" id="testBtn"><?= svg_ico('zap') ?><span id="testBtnLabel">Send Test</span></button><?php endif; ?>
    </div>
    <?php if (empty($recentNotifications)): ?>
      <div class="push-empty">
        <span class="push-empty__icon" aria-hidden="true"><?= svg_ico('bell') ?></span>
        <h4>No notifications yet</h4>
        <p>Your delivery history will appear here after the first broadcast.</p>
      </div>
    <?php else: ?>
      <div class="push-table-wrap">
        <table class="push-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Body</th>
              <th>Sent</th>
              <th>Failed</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentNotifications as $n): ?>
              <tr>
                <td data-label="Title"><strong class="push-table__title"><?= e($n['title']) ?></strong></td>
                <td data-label="Body" class="push-table__body"><?= e(mb_strimwidth($n['body'], 0, 60, '...')) ?></td>
                <td data-label="Sent"><span class="push-badge push-badge--success"><?= (int)$n['sent_count'] ?></span></td>
                <td data-label="Failed"><?= (int)$n['fail_count'] > 0 ? '<span class="push-badge push-badge--danger">' . (int)$n['fail_count'] . '</span>' : '<span class="push-table__zero">0</span>' ?></td>
                <td data-label="Date"><span class="push-table__date"><?= e($n['created_at']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php if ($canSend): ?><script>
function testPush() {
  var btn = document.getElementById('testBtn');
  var label = document.getElementById('testBtnLabel');
  btn.disabled = true;
  label.textContent = 'Sending...';
  fetch('<?= e($base) ?>/?page=admin/tools/push-notifications/api/test&action=test', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({csrf_token: <?= json_encode($csrf) ?>})
  }).then(function(r){return r.json().then(function(d){if(!r.ok||!d.ok)throw new Error(d.error||('HTTP '+r.status));return d})}).then(function(d){
    if (d.ok) {
      if (window.NewNotifToast) window.NewNotifToast.success('Test sent! Sent: ' + d.result.sent + ', Failed: ' + d.result.failed);
    }
  }).catch(function(e){
    if (window.NewNotifToast) window.NewNotifToast.error('Network error: ' + e.message);
    else console.error('[browser-push] Test failed:', e);
  }).finally(function(){
    btn.disabled = false;
    label.textContent = 'Send Test';
  });
}
</script><?php endif; ?>
