<?php
declare(strict_types=1);
require_once __DIR__ . '/../plugin.php';
if (!defined('DASHBOARD_CONTEXT')) { http_response_code(403); exit('Forbidden'); }

$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) { http_response_code(500); exit('Database not available'); }
jyavani_push_ensure_schema($pdo);
$base = ADMIN_BASE_PATH;
$csrf = function_exists('csrf_token') ? csrf_token() : '';
$success = '';
$error = '';
$settings = jyavani_push_settings($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!jyavani_push_csrf_valid($_POST['csrf_token'] ?? null)) throw new RuntimeException('Invalid CSRF token. Refresh the page and try again.');
        $publicKey = jyavani_push_scalar($_POST, 'vapid_public_key', 128);
        $privateKey = jyavani_push_scalar($_POST, 'vapid_private_key', 128);
        $subject = jyavani_push_scalar($_POST, 'vapid_subject', 255, true);
        if ($publicKey !== '' && !jyavani_push_valid_public_key($publicKey)) throw new InvalidArgumentException('VAPID public key must be a 65-byte P-256 public key from web-push.');
        if ($privateKey !== '' && !jyavani_push_valid_private_key($privateKey)) throw new InvalidArgumentException('VAPID private key must be a 32-byte key from web-push.');
        $validSubject = str_starts_with($subject, 'mailto:')
            ? filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL) !== false
            : filter_var($subject, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($subject), 'https://');
        if (!$validSubject) throw new InvalidArgumentException('VAPID subject must be a valid mailto: address or HTTPS URL.');
        jyavani_push_save_setting($pdo, 'push_vapid_public_key', $publicKey);
        if ($privateKey !== '') jyavani_push_save_setting($pdo, 'push_vapid_private_key', $privateKey);
        jyavani_push_save_setting($pdo, 'push_vapid_subject', $subject);
        $success = 'Settings saved.';
        $settings = jyavani_push_settings($pdo);
    } catch (InvalidArgumentException|RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

$privateConfigured = jyavani_push_env('BROWSER_PUSH_VAPID_PRIVATE_KEY') !== '' || ($settings['push_vapid_private_key'] ?? '') !== '';
$pwaIcon = jyavani_push_notification_icon($settings);
?>

<div style="padding:1.5rem;max-width:600px">
  <div style="margin-bottom:1.5rem">
    <a href="<?= e($base) ?>/?page=admin/tools/push-notifications" style="color:var(--muted);text-decoration:none;font-size:.875rem">&larr; Back to Push Notifications</a>
    <h2 style="margin:.5rem 0 0">Push Notification Settings</h2>
  </div>

  <?php if ($success): ?>
    <div style="background:#16a34a20;border:1px solid #16a34a40;border-radius:var(--radius-md);padding:1rem;margin-bottom:1rem;color:#16a34a"><?= e($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div style="background:#dc262620;border:1px solid #dc262640;border-radius:var(--radius-md);padding:1rem;margin-bottom:1rem;color:#dc2626"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="POST" style="display:flex;flex-direction:column;gap:1rem">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <div>
      <label style="display:block;font-weight:600;margin-bottom:.25rem">VAPID Public Key</label>
      <input type="text" name="vapid_public_key" maxlength="128" placeholder="Base64url public key from web-push"
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text);font-family:monospace"
        value="<?= e($settings['push_vapid_public_key'] ?? '') ?>">
    </div>
    <div>
      <label style="display:block;font-weight:600;margin-bottom:.25rem">VAPID Private Key</label>
      <input type="password" name="vapid_private_key" maxlength="128" autocomplete="new-password"
        placeholder="<?= $privateConfigured ? 'Configured; leave blank to keep it' : 'Base64url private key from web-push' ?>"
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text);font-family:monospace" value="">
    </div>
    <div>
      <label style="display:block;font-weight:600;margin-bottom:.25rem">VAPID Subject</label>
      <input type="text" name="vapid_subject" maxlength="255" placeholder="mailto:admin@example.com"
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text)"
        value="<?= e($settings['push_vapid_subject'] ?? 'mailto:admin@example.com') ?>">
    </div>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-md);padding:1rem">
      <p style="margin:0 0 .5rem;font-size:.875rem;color:var(--muted)"><strong>Notification icon:</strong> <code><?= e($pwaIcon) ?></code>. Browser Push uses the PWA plugin icon settings and falls back to its bundled PNG.</p>
      <p style="margin:0;font-size:.875rem;color:var(--muted)"><strong>Generate keys:</strong> Run <code>php plugins/browser-push/generate-vapid.php</code>. You may instead set <code>BROWSER_PUSH_VAPID_PUBLIC_KEY</code>, <code>BROWSER_PUSH_VAPID_PRIVATE_KEY</code>, and <code>BROWSER_PUSH_VAPID_SUBJECT</code> in the server environment; environment values take precedence.</p>
    </div>
    <button type="submit" class="push-btn primary" style="width:100%;justify-content:center;padding:.75rem">
      <?= svg_ico('save') ?> Save Settings
    </button>
  </form>
</div>
