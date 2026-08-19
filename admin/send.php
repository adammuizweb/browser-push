<?php
declare(strict_types=1);
require_once __DIR__ . '/../plugin.php';
if (!defined('DASHBOARD_CONTEXT')) { http_response_code(403); exit('Forbidden'); }

$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) { http_response_code(500); exit('Database not available'); }
adiwira_require_permission($pdo, 'plugin.browser-push.notifications.send', false);
$base = ADMIN_BASE_PATH;
$csrf = function_exists('csrf_token') ? csrf_token() : '';
$success = '';
$error = '';
$values = ['title' => '', 'body' => '', 'url' => '', 'icon' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!jyavani_push_csrf_valid($_POST['csrf_token'] ?? null)) throw new RuntimeException('Invalid CSRF token. Refresh the page and try again.');
        $values = jyavani_push_payload($_POST);
        $result = jyavani_push_broadcast($pdo, $values['title'], $values['body'], $values['url'], $values['icon']);
        if ($result['total'] === 0) {
            $error = 'No notification was sent because there are no active subscribers.';
        } elseif ($result['failed'] > 0) {
            $error = "Delivery was incomplete. Delivered: {$result['sent']}, failed: {$result['failed']}, total: {$result['total']}.";
        } else {
            $success = "Notification delivered to all {$result['sent']} subscribers.";
        }
    } catch (InvalidArgumentException|RuntimeException $exception) {
        $error = $exception->getMessage();
        foreach ($values as $key => $_value) {
            $posted = $_POST[$key] ?? '';
            $values[$key] = is_scalar($posted) ? (string)$posted : '';
        }
    }
}
?>

<div style="padding:1.5rem;max-width:600px">
  <div style="margin-bottom:1.5rem">
    <a href="<?= e($base) ?>/?page=admin/tools/push-notifications" style="color:var(--muted);text-decoration:none;font-size:.875rem">&larr; Back to Push Notifications</a>
    <h2 style="margin:.5rem 0 0">Send Notification</h2>
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
      <label style="display:block;font-weight:600;margin-bottom:.25rem">Title *</label>
      <input type="text" name="title" required maxlength="<?= JYAVANI_PUSH_MAX_TITLE ?>" placeholder="Artikel baru terbit!"
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text)"
        value="<?= e($values['title']) ?>">
    </div>
    <div>
      <label style="display:block;font-weight:600;margin-bottom:.25rem">Body *</label>
      <textarea name="body" required maxlength="<?= JYAVANI_PUSH_MAX_BODY ?>" rows="3" placeholder="Cek artikel terbaru di website kamu..."
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text)"><?= e($values['body']) ?></textarea>
    </div>
    <div>
      <label style="display:block;font-weight:600;margin-bottom:.25rem">URL (optional)</label>
      <input type="text" name="url" maxlength="<?= JYAVANI_PUSH_MAX_URL ?>" placeholder="/artikel-baru/"
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text)"
        value="<?= e($values['url']) ?>">
    </div>
    <div>
      <label style="display:block;font-weight:600;margin-bottom:.25rem">Icon URL (optional)</label>
      <input type="text" name="icon" maxlength="<?= JYAVANI_PUSH_MAX_ICON ?>" placeholder="Uses the PWA icon by default"
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text)"
        value="<?= e($values['icon']) ?>">
    </div>
    <button type="submit" class="push-btn primary" style="width:100%;justify-content:center;padding:.75rem">
      <?= svg_ico('send') ?> Send to All Subscribers
    </button>
  </form>
</div>
