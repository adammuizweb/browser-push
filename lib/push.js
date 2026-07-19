#!/usr/bin/env node
const webpush = require('web-push');

let input = '';
process.stdin.on('data', chunk => input += chunk);
process.stdin.on('end', () => {
  try {
    const { endpoint, p256dh, auth, payload, vapidPublicKey, vapidPrivateKey, vapidSubject } = JSON.parse(input);
    webpush.setVapidDetails(vapidSubject, vapidPublicKey, vapidPrivateKey);
    webpush.sendNotification(
      { endpoint, keys: { p256dh, auth } },
      JSON.stringify(payload),
      { TTL: 86400 }
    )
      .then(r => process.stdout.write(JSON.stringify({ ok: true, status: r.statusCode })))
      .catch(e => process.stdout.write(JSON.stringify({ ok: false, status: e.statusCode, error: (e.message || '').substring(0, 100) })));
  } catch (e) {
    process.stdout.write(JSON.stringify({ ok: false, error: e.message }));
  }
});
