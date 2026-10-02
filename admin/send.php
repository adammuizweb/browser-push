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

<div class="push-admin push-admin--narrow">
  <header class="push-page-head">
    <a href="<?= e($base) ?>/?page=admin/tools/push-notifications" class="push-back"><?= svg_ico('arrow-left') ?> <span>Push Notifications</span></a>
    <span class="push-eyebrow">New broadcast</span>
    <h2>Send Notification</h2>
    <p>Write one concise message for every active browser subscriber.</p>
  </header>

  <?php if ($success): ?>
    <div class="push-notice push-notice--success" role="status"><span class="push-notice__icon" aria-hidden="true"><?= svg_ico('lock') ?></span><div><strong>Delivery complete</strong><span><?= e($success) ?></span></div></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="push-notice push-notice--danger" role="alert"><span class="push-notice__icon" aria-hidden="true"><?= svg_ico('zap') ?></span><div><strong>Delivery not completed</strong><span><?= e($error) ?></span></div></div>
  <?php endif; ?>

  <div class="push-compose-grid">
    <form method="POST" class="push-panel push-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <div class="push-form__head">
        <span class="push-form__step">Message</span>
        <h3>Notification content</h3>
        <p>Keep the title recognizable and the body useful at a glance.</p>
      </div>
      <div class="push-field">
        <div class="push-field__label"><label for="push-title">Title <span aria-hidden="true">*</span></label><span>Max <?= JYAVANI_PUSH_MAX_TITLE ?></span></div>
        <input id="push-title" class="push-input" type="text" name="title" required maxlength="<?= JYAVANI_PUSH_MAX_TITLE ?>" placeholder="Artikel baru terbit!" value="<?= e($values['title']) ?>">
      </div>
      <div class="push-field">
        <div class="push-field__label"><label for="push-body">Body <span aria-hidden="true">*</span></label><span>Max <?= JYAVANI_PUSH_MAX_BODY ?></span></div>
        <textarea id="push-body" class="push-input push-textarea" name="body" required maxlength="<?= JYAVANI_PUSH_MAX_BODY ?>" rows="4" placeholder="Cek artikel terbaru di website kamu..."><?= e($values['body']) ?></textarea>
      </div>
      <div class="push-field">
        <label for="push-url">Open URL <span class="push-optional">Optional</span></label>
        <input id="push-url" class="push-input" type="text" name="url" maxlength="<?= JYAVANI_PUSH_MAX_URL ?>" placeholder="/artikel-baru/" value="<?= e($values['url']) ?>">
        <small>Use a same-site path beginning with <code>/</code>, such as <code>/article/</code>.</small>
      </div>
      <div class="push-field">
        <label for="push-icon">Icon URL <span class="push-optional">Optional</span></label>
        <input id="push-icon" class="push-input" type="text" name="icon" maxlength="<?= JYAVANI_PUSH_MAX_ICON ?>" placeholder="Uses the PWA icon by default" value="<?= e($values['icon']) ?>">
        <small>Leave blank to use the configured PWA icon.</small>
      </div>
      <button type="submit" class="push-btn push-btn--primary push-btn--submit"><?= svg_ico('bell') ?> Send to All Subscribers</button>
    </form>

    <aside class="push-compose-aside" aria-label="Notification preview">
      <div class="push-preview-label"><span class="push-eyebrow">Example preview</span></div>
      <div class="push-preview">
        <span class="push-preview__icon" aria-hidden="true"><?= svg_ico('bell') ?></span>
        <div>
          <strong><?= e($values['title'] !== '' ? $values['title'] : 'Your notification title') ?></strong>
          <p><?= e($values['body'] !== '' ? $values['body'] : 'Your message will appear here on supported devices.') ?></p>
          <span><?= e($values['url'] !== '' ? $values['url'] : 'Opens your site') ?></span>
        </div>
      </div>
      <p class="push-compose-note">The exact appearance depends on the reader's browser and operating system.</p>
    </aside>
  </div>
</div>
