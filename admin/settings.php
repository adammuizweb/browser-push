<?php
declare(strict_types=1);
require_once __DIR__ . '/../plugin.php';
if (!defined('DASHBOARD_CONTEXT')) { http_response_code(403); exit('Forbidden'); }

global $pdo;
jyavani_push_ensure_schema($pdo);
$base = ADMIN_BASE_PATH;

$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    jyavani_push_save_setting($pdo, 'push_vapid_public_key', trim($_POST['vapid_public_key'] ?? ''));
    jyavani_push_save_setting($pdo, 'push_vapid_private_key', trim($_POST['vapid_private_key'] ?? ''));
    jyavani_push_save_setting($pdo, 'push_vapid_subject', trim($_POST['vapid_subject'] ?? 'mailto:admin@example.com'));
    jyavani_push_save_setting($pdo, 'push_default_icon', trim($_POST['default_icon'] ?? '/static/icons/lucide/bell.svg'));
    $success = 'Settings saved.';
}

$settings = jyavani_push_settings($pdo);
?>

<div style="padding:1.5rem;max-width:600px">
  <div style="margin-bottom:1.5rem">
    <a href="<?= $base ?>/?page=admin/tools/push-notifications" style="color:var(--muted);text-decoration:none;font-size:.875rem">&larr; Back to Push Notifications</a>
    <h2 style="margin:.5rem 0 0">Push Notification Settings</h2>
  </div>

  <?php if ($success): ?>
    <div style="background:#16a34a20;border:1px solid #16a34a40;border-radius:var(--radius-md);padding:1rem;margin-bottom:1rem;color:#16a34a"><?= e($success) ?></div>
  <?php endif; ?>

  <form method="POST" style="display:flex;flex-direction:column;gap:1rem">
    <div>
      <label style="display:block;font-weight:600;margin-bottom:.25rem">VAPID Public Key</label>
      <input type="text" name="vapid_public_key" placeholder="Base64url-encoded public key"
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text);font-family:monospace"
        value="<?= e($settings['push_vapid_public_key'] ?? '') ?>">
    </div>
    <div>
      <label style="display:block;font-weight:600;margin-bottom:.25rem">VAPID Private Key</label>
      <input type="password" name="vapid_private_key" placeholder="Base64url-encoded private key"
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text);font-family:monospace"
        value="<?= e($settings['push_vapid_private_key'] ?? '') ?>">
    </div>
    <div>
      <label style="display:block;font-weight:600;margin-bottom:.25rem">VAPID Subject</label>
      <input type="text" name="vapid_subject" placeholder="mailto:admin@example.com"
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text)"
        value="<?= e($settings['push_vapid_subject'] ?? 'mailto:admin@example.com') ?>">
    </div>
    <div>
      <label style="display:block;font-weight:600;margin-bottom:.25rem">Default Notification Icon</label>
      <input type="text" name="default_icon" placeholder="/static/icons/lucide/bell.svg"
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text)"
        value="<?= e($settings['push_default_icon'] ?? '/static/icons/lucide/bell.svg') ?>">
    </div>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-md);padding:1rem">
      <p style="margin:0;font-size:.875rem;color:var(--muted)">
        <strong>Generate VAPID keys:</strong> Run <code>php plugins/browser-push/generate-vapid.php</code> from the CMS root directory.
        The keys identify your app to push services and must be kept secret.
      </p>
    </div>
    <div>
      <button type="submit" class="push-btn primary" style="width:100%;justify-content:center;padding:.75rem">
        <?= svg_ico('save') ?> Save Settings
      </button>
    </div>
  </form>
</div>
