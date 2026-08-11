'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const webpush = require('web-push');

function decode(value) {
  const padding = '='.repeat((4 - value.length % 4) % 4);
  return Buffer.from((value + padding).replace(/-/g, '+').replace(/_/g, '/'), 'base64');
}

const keys = webpush.generateVAPIDKeys();
assert.equal(decode(keys.publicKey).length, 65, 'VAPID public key must be an uncompressed P-256 point');
assert.equal(decode(keys.privateKey).length, 32, 'VAPID private key must be 32 bytes');

const root = path.join(__dirname, '..');
const client = fs.readFileSync(path.join(root, 'public', 'push.js'), 'utf8');
const worker = fs.readFileSync(path.join(root, 'public', 'sw.js'), 'utf8');
const plugin = fs.readFileSync(path.join(root, 'plugin.php'), 'utf8');
const api = fs.readFileSync(path.join(root, 'public', 'api.php'), 'utf8');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'plugin.json'), 'utf8'));
const pkg = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
assert.match(client, /\/push-api\/subscribe\//);
assert.match(client, /\/push-api\/unsubscribe\//);
assert.doesNotMatch(worker, /addEventListener\(['"](?:install|activate)['"]/);
assert.match(worker, /pushsubscriptionchange/);
assert.match(plugin, /add_action\('init'/, 'worker registration must be scheduled during Core init');
assert.match(plugin, /JYAVANI_PUSH_WORKER/, 'worker registration must be preloaded in the public head');
assert.match(client, /state === 'resubscribe'/, 'VAPID mismatch must expose an actionable state');
assert.doesNotMatch(client.match(/async function currentState[\s\S]*?\n  }/)[0], /deactivateThenUnsubscribe|resubscribe\(/, 'status reconciliation must not rotate a subscription');
assert.match(worker, /subscriptionUsesKey\(subscription, config\.vapidKey\)/, 'subscription refresh must retain the configured VAPID identity');
assert.equal(pkg.dependencies['web-push'], '3.4.5');
assert.equal(pkg.scripts['test:apns'], 'node tests/apns-connectivity.js');
assert.equal(manifest.setup.checks.length, 1);
assert.equal(manifest.setup.checks[0].type, 'file_exists');
assert.doesNotMatch(plugin, /\$env\[['"]NODE_OPTIONS['"]\]\s*=/, 'PHP must preserve operator NODE_OPTIONS');
assert.match(fs.readFileSync(path.join(root, 'lib', 'push.js'), 'utf8'), /typeof dns\.setDefaultResultOrder === 'function'/);
assert.match(fs.readFileSync(path.join(root, 'lib', 'push.js'), 'utf8'), /typeof net\.setDefaultAutoSelectFamily === 'function'/);
assert.match(worker, /normalizedPath !== path/, 'worker navigation must reject non-normalized paths');
assert.match(plugin, /endpoint_hash CHAR\(64\) NOT NULL/);
assert.match(plugin, /DROP INDEX/);
assert.match(api, /INSERT INTO push_subscriptions \(endpoint, endpoint_hash,/);
assert.match(api, /jyavani_push_endpoint_hash\(\$endpoint\)/);
assert.match(api, /endpoint:' \+|endpoint:' \./, 'rate limiting must include endpoint identity');
assert.match(plugin, /settings_get\(\$pdo, 'site_url'/, 'origin must use the trusted CMS canonical URL');
assert.doesNotMatch(api, /HTTP_X_FORWARDED|HTTP_HOST/, 'origin checks must not trust forwarded or Host headers');

console.log('runtime tests passed');
