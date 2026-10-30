<?php
declare(strict_types=1);
require_once __DIR__ . '/../plugin.php';
if (!defined('DASHBOARD_CONTEXT')) { http_response_code(403); exit('Forbidden'); }

$pdo = $GLOBALS['pdo'] ?? null;
if (!($pdo instanceof PDO)) { http_response_code(500); exit('Database not available'); }
adiwira_require_permission($pdo, 'plugin.browser-push.settings.manage', false);
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
        $autoNotifyArticles = isset($_POST['auto_notify_articles']) ? '1' : '0';
        $wasAutoNotifyEnabled = ($settings['push_auto_notify_articles'] ?? '0') === '1';
        $pdo->beginTransaction();
        jyavani_push_save_setting($pdo, 'push_vapid_public_key', $publicKey);
        if ($privateKey !== '') jyavani_push_save_setting($pdo, 'push_vapid_private_key', $privateKey);
        jyavani_push_save_setting($pdo, 'push_vapid_subject', $subject);
        if (!$wasAutoNotifyEnabled && $autoNotifyArticles === '1') {
            jyavani_push_baseline_published_articles($pdo);
        }
        jyavani_push_save_setting($pdo, 'push_auto_notify_articles', $autoNotifyArticles);
        $pdo->commit();
        $success = 'Settings saved.';
        $settings['push_vapid_public_key'] = $publicKey;
        if ($privateKey !== '') $settings['push_vapid_private_key'] = $privateKey;
        $settings['push_vapid_subject'] = $subject;
        $settings['push_auto_notify_articles'] = $autoNotifyArticles;
    } catch (InvalidArgumentException|RuntimeException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception->getMessage();
    }
}

$privateConfigured = jyavani_push_env('BROWSER_PUSH_VAPID_PRIVATE_KEY') !== '' || ($settings['push_vapid_private_key'] ?? '') !== '';
$autoNotifyArticles = ($settings['push_auto_notify_articles'] ?? '0') === '1';
$pwaIcon = jyavani_push_notification_icon($settings);
?>

<div class="push-admin push-admin--narrow">
  <header class="push-page-head">
    <a href="<?= e($base) ?>/?page=admin/tools/push-notifications" class="push-back"><?= svg_ico('arrow-left') ?> <span>Push Notifications</span></a>
    <span class="push-eyebrow">Delivery identity</span>
    <h2>Push Notification Settings</h2>
    <p>Configure the VAPID identity browsers use to trust your notifications.</p>
  </header>

  <?php if ($success): ?>
    <div class="push-notice push-notice--success" role="status"><span class="push-notice__icon" aria-hidden="true"><?= svg_ico('lock') ?></span><div><strong>Settings updated</strong><span><?= e($success) ?></span></div></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="push-notice push-notice--danger" role="alert"><span class="push-notice__icon" aria-hidden="true"><?= svg_ico('zap') ?></span><div><strong>Settings not saved</strong><span><?= e($error) ?></span></div></div>
  <?php endif; ?>

  <div class="push-settings-grid">
    <form method="POST" class="push-panel push-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <div class="push-form__head">
        <span class="push-form__step">Credentials</span>
        <h3>VAPID configuration</h3>
        <p>Keep one key pair for the lifetime of existing subscriptions.</p>
      </div>
      <div class="push-field">
        <label for="vapid-public">VAPID Public Key</label>
        <input id="vapid-public" class="push-input push-input--code" type="text" name="vapid_public_key" maxlength="128" placeholder="Base64url public key from web-push" value="<?= e($settings['push_vapid_public_key'] ?? '') ?>">
        <small>Shared with browsers when readers subscribe.</small>
      </div>
      <div class="push-field">
        <div class="push-field__label"><label for="vapid-private">VAPID Private Key</label><?php if ($privateConfigured): ?><span class="push-badge push-badge--success">Configured</span><?php endif; ?></div>
        <input id="vapid-private" class="push-input push-input--code" type="password" name="vapid_private_key" maxlength="128" autocomplete="new-password" placeholder="<?= $privateConfigured ? 'Configured; leave blank to keep it' : 'Base64url private key from web-push' ?>" value="">
        <small>The saved private key is never rendered back into this page.</small>
      </div>
      <div class="push-field">
        <label for="vapid-subject">VAPID Subject</label>
        <input id="vapid-subject" class="push-input" type="text" name="vapid_subject" maxlength="255" placeholder="mailto:admin@example.com" value="<?= e($settings['push_vapid_subject'] ?? 'mailto:admin@example.com') ?>">
        <small>Use a monitored <code>mailto:</code> address or an HTTPS URL.</small>
      </div>
      <div class="push-automation">
        <div class="push-automation__copy">
          <span class="push-form__step"><?= e(jyavani_push_translate('Article automation')) ?></span>
          <h3><?= e(jyavani_push_translate('Notify once per Article')) ?></h3>
          <p><?= e(jyavani_push_translate('Send the Article title and canonical link when an Article reaches Published. Each Article gets at most one automatic attempt.')) ?></p>
        </div>
        <label class="push-switch" for="auto-notify-articles">
          <input id="auto-notify-articles" type="checkbox" name="auto_notify_articles" value="1" <?= $autoNotifyArticles ? 'checked' : '' ?>>
          <span class="push-switch__track" aria-hidden="true"><span></span></span>
          <span class="push-switch__label"><span class="push-switch__off"><?= e(jyavani_push_translate('Off')) ?></span><span class="push-switch__on"><?= e(jyavani_push_translate('On')) ?></span></span>
        </label>
        <small><?= e(jyavani_push_translate('Existing published Articles are marked as already seen when this is enabled. Delivery is synchronous and is not retried automatically.')) ?></small>
      </div>
      <button type="submit" class="push-btn push-btn--primary push-btn--submit"><?= svg_ico('save') ?> Save Settings</button>
    </form>

    <aside class="push-settings-aside">
      <section class="push-info-card">
        <span class="push-info-card__icon" aria-hidden="true"><?= svg_ico('lock') ?></span>
        <div><span class="push-eyebrow">Key status</span><h3><?= $privateConfigured ? 'Private key configured' : 'Private key needed' ?></h3><p><?= $privateConfigured ? 'A private key value is available from the environment or saved settings.' : 'Add a valid private key before sending notifications.' ?></p></div>
      </section>
      <section class="push-info-card push-info-card--plain">
        <span class="push-info-card__icon" aria-hidden="true"><?= svg_ico('bell') ?></span>
        <div><span class="push-eyebrow">Notification icon</span><code class="push-code-path"><?= e($pwaIcon) ?></code><p>Browser Push uses the PWA icon and falls back to its bundled PNG.</p></div>
      </section>
      <section class="push-command-card">
        <span class="push-eyebrow">Generate a key pair</span>
        <code>php plugins/browser-push/generate-vapid.php</code>
        <p>Environment values take precedence over saved settings.</p>
      </section>
    </aside>
  </div>
</div>
