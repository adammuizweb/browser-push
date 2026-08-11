'use strict';

try {
  const dns = require('node:dns');
  if (typeof dns.setDefaultResultOrder === 'function') {
    dns.setDefaultResultOrder('ipv4first');
  }
} catch (error) {
  // Older supported Node releases may not expose this API.
}

try {
  const net = require('node:net');
  if (typeof net.setDefaultAutoSelectFamily === 'function') {
    net.setDefaultAutoSelectFamily(false);
  }
} catch (error) {
  // Network family autoselection is not available on every Node >=16 release.
}

const webpush = require('web-push');

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => {
  input += chunk;
  if (input.length > 16384) {
    process.stdout.write(JSON.stringify({ ok: false, status: 0, error: 'Input is too large' }));
    process.exit(1);
  }
});
process.stdin.on('end', async () => {
  try {
    const data = JSON.parse(input);
    webpush.setVapidDetails(data.vapidSubject, data.vapidPublicKey, data.vapidPrivateKey);
    const response = await webpush.sendNotification(
      { endpoint: data.endpoint, keys: { p256dh: data.p256dh, auth: data.auth } },
      JSON.stringify(data.payload),
      { TTL: 86400, timeout: 12000 }
    );
    process.stdout.write(JSON.stringify({ ok: true, status: response.statusCode }));
  } catch (error) {
    process.stdout.write(JSON.stringify({
      ok: false,
      status: Number(error.statusCode) || 0,
      error: String(error.message || 'Push delivery failed').substring(0, 300),
    }));
    process.exitCode = 1;
  }
});
