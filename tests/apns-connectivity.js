'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const webpush = require('web-push');

const vapid = webpush.generateVAPIDKeys();
const subscriptionKey = crypto.createECDH('prime256v1');
subscriptionKey.generateKeys();
const encode = value => Buffer.from(value).toString('base64url');
const input = JSON.stringify({
  endpoint: 'https://web.push.apple.com/QnJvd3Nlci1wdXNoLXN5bnRoZXRpYw',
  p256dh: encode(subscriptionKey.getPublicKey()),
  auth: encode(crypto.randomBytes(16)),
  payload: { title: 'Synthetic connectivity check' },
  vapidPublicKey: vapid.publicKey,
  vapidPrivateKey: vapid.privateKey,
  vapidSubject: 'mailto:synthetic@example.com',
});

const result = spawnSync(process.execPath, [path.join(__dirname, '..', 'lib', 'push.js')], {
  input,
  encoding: 'utf8',
  timeout: 20000,
});
if (result.error) throw result.error;
const response = JSON.parse(result.stdout);
assert.ok(response.status >= 100 && response.status <= 599, `Expected an APNs HTTP response, received: ${result.stdout || result.stderr}`);
assert.doesNotMatch(String(response.error || ''), /ETIMEDOUT/i);
console.log(`APNs connectivity passed with provider HTTP ${response.status}`);
