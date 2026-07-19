<?php
declare(strict_types=1);
require_once __DIR__ . '/../plugin.php';
if (!defined('DASHBOARD_CONTEXT')) { http_response_code(403); exit('Forbidden'); }

global $pdo;
jyavani_push_ensure_schema($pdo);

// Stats
$totalSubs = (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE is_active = 1")->fetchColumn();
$totalNotifications = (int)$pdo->query("SELECT COUNT(*) FROM push_notifications")->fetchColumn();
$recentNotifications = $pdo->query("SELECT * FROM push_notifications ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$settings = jyavani_push_settings($pdo);
$vapidConfigured = !empty($settings['push_vapid_public_key']) && !empty($settings['push_vapid_private_key']);
$base = ADMIN_BASE_PATH;
?>
<style>
.push-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
.push-stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-md);padding:1.25rem;text-align:center}
.push-stat .number{font-size:2rem;font-weight:700;color:var(--accent)}
.push-stat .label{color:var(--muted);font-size:.85rem;margin-top:.25rem}
.push-actions{display:flex;gap:.75rem;margin-bottom:1.5rem;flex-wrap:wrap}
.push-btn{padding:.5rem 1rem;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;font-size:.875rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem}
.push-btn:hover{background:var(--bg);border-color:var(--accent)}
.push-btn.primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.push-btn.primary:hover{opacity:.9}
.push-table{width:100%;border-collapse:collapse;font-size:.875rem}
.push-table th,.push-table td{padding:.5rem .75rem;text-align:left;border-bottom:1px solid var(--border)}
.push-table th{color:var(--muted);font-weight:600;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em}
.badge{display:inline-block;padding:.15rem .5rem;border-radius:9999px;font-size:.75rem;font-weight:600}
.badge-green{background:#16a34a20;color:#16a34a}
.badge-red{background:#dc262620;color:#dc2626}
.badge-yellow{background:#ca8a0420;color:#ca8a04}
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:.5rem}
.status-dot.green{background:#16a34a}
.status-dot.red{background:#dc2626}
</style>

<div style="padding:1.5rem">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
    <div>
      <h2 style="margin:0;font-size:1.25rem">Push Notifications</h2>
      <p style="margin:.25rem 0 0;color:var(--muted);font-size:.875rem">Browser push notifications via Web Push API</p>
    </div>
    <div style="display:flex;gap:.5rem">
      <?php if (!$vapidConfigured): ?>
        <a href="<?= $base ?>/admin/tools/push-notifications/settings" class="push-btn primary">Configure VAPID Keys</a>
      <?php endif; ?>
      <a href="<?= $base ?>/admin/tools/push-notifications/send" class="push-btn primary"><?= svg_ico('send') ?> Send Notification</a>
      <a href="<?= $base ?>/admin/tools/push-notifications/settings" class="push-btn"><?= svg_ico('cog') ?> Settings</a>
    </div>
  </div>

  <?php if (!$vapidConfigured): ?>
    <div style="background:#ca8a0420;border:1px solid #ca8a0440;border-radius:var(--radius-md);padding:1rem;margin-bottom:1.5rem">
      <strong>VAPID keys not configured.</strong> Go to <a href="<?= $base ?>/admin/tools/push-notifications/settings">Settings</a> to add your VAPID public and private keys.
    </div>
  <?php endif; ?>

  <div class="push-stats">
    <div class="push-stat">
      <div class="number"><?= $totalSubs ?></div>
      <div class="label">Active Subscribers</div>
    </div>
    <div class="push-stat">
      <div class="number"><?= $totalNotifications ?></div>
      <div class="label">Notifications Sent</div>
    </div>
    <div class="push-stat">
      <div class="number"><?= $vapidConfigured ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-red">Inactive</span>' ?></div>
      <div class="label">VAPID Status</div>
    </div>
  </div>

  <div class="push-actions">
    <button onclick="testPush()" class="push-btn" id="testBtn"><?= svg_ico('zap') ?> Send Test</button>
  </div>

  <h3 style="margin-bottom:.75rem">Recent Notifications</h3>
  <?php if (empty($recentNotifications)): ?>
    <p style="color:var(--muted)">No notifications sent yet.</p>
  <?php else: ?>
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
            <td><?= e($n['title']) ?></td>
            <td><?= e(mb_strimwidth($n['body'], 0, 60, '...')) ?></td>
            <td><span class="badge badge-green"><?= $n['sent_count'] ?></span></td>
            <td><?= $n['fail_count'] > 0 ? '<span class="badge badge-red">' . $n['fail_count'] . '</span>' : '0' ?></td>
            <td><?= e($n['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
async function testPush() {
  const btn = document.getElementById('testBtn');
  btn.disabled = true;
  btn.textContent = 'Sending...';
  try {
    const resp = await fetch('<?= $base ?>/admin/tools/push-notifications/api/test', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
    });
    const data = await resp.json();
    if (data.ok) {
      alert('Test notification sent! Sent: ' + data.result.sent + ', Failed: ' + data.result.failed);
    } else {
      alert('Error: ' + (data.error || 'Unknown error'));
    }
  } catch (e) {
    alert('Network error: ' + e.message);
  }
  btn.disabled = false;
  btn.textContent = 'Send Test';
}
</script>
