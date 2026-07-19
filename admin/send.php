<?php
declare(strict_types=1);
require_once __DIR__ . '/../plugin.php';
if (!defined('DASHBOARD_CONTEXT')) { http_response_code(403); exit('Forbidden'); }

global $pdo;
$base = ADMIN_BASE_PATH;
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $icon = trim($_POST['icon'] ?? '');

    if (empty($title) || empty($body)) {
        $error = 'Title and body are required.';
    } else {
        $result = jyavani_push_broadcast($pdo, $title, $body, $url, $icon);
        $success = "Notification sent! Delivered: {$result['sent']}, Failed: {$result['failed']}, Total subscribers: {$result['total']}";
    }
}
?>

<div style="padding:1.5rem;max-width:600px">
  <div style="margin-bottom:1.5rem">
    <a href="<?= $base ?>/admin/tools/push-notifications" style="color:var(--muted);text-decoration:none;font-size:.875rem">&larr; Back to Push Notifications</a>
    <h2 style="margin:.5rem 0 0">Send Notification</h2>
  </div>

  <?php if ($success): ?>
    <div style="background:#16a34a20;border:1px solid #16a34a40;border-radius:var(--radius-md);padding:1rem;margin-bottom:1rem;color:#16a34a"><?= e($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div style="background:#dc262620;border:1px solid #dc262640;border-radius:var(--radius-md);padding:1rem;margin-bottom:1rem;color:#dc2626"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="POST" style="display:flex;flex-direction:column;gap:1rem">
    <div>
      <label style="display:block;font-weight:600;margin-bottom:.25rem">Title *</label>
      <input type="text" name="title" required placeholder="Artikel baru terbit!"
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text)"
        value="<?= e($_POST['title'] ?? '') ?>">
    </div>
    <div>
      <label style="display:block;font-weight:600;margin-bottom:.25rem">Body *</label>
      <textarea name="body" required rows="3" placeholder="Cek artikel terbaru di adammuiz.com..."
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text)"><?= e($_POST['body'] ?? '') ?></textarea>
    </div>
    <div>
      <label style="display:block;font-weight:600;margin-bottom:.25rem">URL (optional)</label>
      <input type="url" name="url" placeholder="https://adammuiz.com/slug/"
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text)"
        value="<?= e($_POST['url'] ?? '') ?>">
    </div>
    <div>
      <label style="display:block;font-weight:600;margin-bottom:.25rem">Icon URL (optional)</label>
      <input type="text" name="icon" placeholder="/static/icons/lucide/bell.svg"
        style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text)"
        value="<?= e($_POST['icon'] ?? '') ?>">
    </div>
    <div>
      <button type="submit" class="push-btn primary" style="width:100%;justify-content:center;padding:.75rem">
        <?= svg_ico('send') ?> Send to All Subscribers
      </button>
    </div>
  </form>
</div>
