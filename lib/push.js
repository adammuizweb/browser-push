#!/usr/bin/env node
const webpush = require('web-push');

// Read input from stdin
let input = '';
process.stdin.on('data', chunk => input += chunk);
process.stdin.on('end', () => {
  try {
    const { endpoint, p256dh, auth, payload, vapidPublicKey, vapidPrivateKey, vapidSubject } = JSON.parse(input);
    
    webpush.setVapidDetails(vapidSubject, vapidPublicKey, vapidPrivateKey);
    
    const subscription = {
      endpoint,
      keys: { p256dh, auth }
    };

    webpush.sendNotification(subscription, JSON.stringify(payload))
      .then(response => {
        process.stdout.write(JSON.stringify({ ok: true, status: response.statusCode }));
      })
      .catch(err => {
        process.stdout.write(JSON.stringify({ ok: false, error: err.message, status: err.statusCode }));
      });
  } catch (e) {
    process.stdout.write(JSON.stringify({ ok: false, error: e.message }));
  }
});
