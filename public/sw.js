/* Jyavani Browser Push service-worker handlers v1.2.1 */
(function () {
  'use strict';

  const config = self.JYAVANI_PUSH_CONFIG || {};

  function decodeKey(value) {
    const padding = '='.repeat((4 - value.length % 4) % 4);
    const data = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(data, char => char.charCodeAt(0));
  }

  function subscriptionUsesKey(subscription, value) {
    const current = subscription?.options?.applicationServerKey;
    if (!current || !value) return false;
    const expected = decodeKey(value);
    const actual = new Uint8Array(current);
    return actual.length === expected.length && actual.every((byte, index) => byte === expected[index]);
  }

  function safeLocalUrl(value) {
    if (typeof value !== 'string' || value.includes('\\') || value.includes('#')
      || /[\u0000-\u0020\u007f]/.test(value)) {
      return self.location.origin + '/';
    }
    if (!value.startsWith('/')) {
      try {
        const absolute = new URL(value);
        if (absolute.origin !== self.location.origin || !['http:', 'https:'].includes(absolute.protocol)) {
          return self.location.origin + '/';
        }
        value = `${absolute.pathname}${absolute.search}`;
      } catch (error) {
        return self.location.origin + '/';
      }
    }
    if (value.startsWith('//')) return self.location.origin + '/';
    let probe = value;
    for (let index = 0; index < 4; index++) {
      if (/%(?:0[0-9a-f]|1[0-9a-f]|2e|5c|7f)/i.test(probe)) return self.location.origin + '/';
      let decoded;
      try {
        decoded = decodeURIComponent(probe);
      } catch (error) {
        return self.location.origin + '/';
      }
      if (decoded === probe) break;
      if (decoded.includes('\\') || decoded.includes('#') || /[\u0000-\u001f\u007f]/.test(decoded)) {
        return self.location.origin + '/';
      }
      probe = decoded;
    }
    try {
      const path = value.split('?', 1)[0];
      const decodedPath = decodeURIComponent(path);
      const segments = decodedPath === '/' ? [] : decodedPath.replace(/^\/+|\/+$/g, '').split('/');
      if (path.includes('//') || decodedPath.includes('//') || segments.some(segment => !segment || segment === '.' || segment === '..')) {
        return self.location.origin + '/';
      }
      const trailingSlash = decodedPath !== '/' && decodedPath.endsWith('/');
      const encodeSegment = segment => encodeURIComponent(segment).replace(/[!'()*]/g, char => `%${char.charCodeAt(0).toString(16).toUpperCase()}`);
      const normalizedPath = `/${segments.map(encodeSegment).join('/')}${trailingSlash ? '/' : ''}`;
      if (normalizedPath !== path) return self.location.origin + '/';
      const url = new URL(value, self.location.origin);
      return url.origin === self.location.origin && ['http:', 'https:'].includes(url.protocol) ? url.href : self.location.origin + '/';
    } catch (error) {
      return self.location.origin + '/';
    }
  }

  async function postJson(url, payload) {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || data?.ok !== true) throw new Error(data?.error || `HTTP ${response.status}`);
    return data;
  }

  self.addEventListener('push', event => {
    if (!event.data) return;
    let data;
    try {
      data = event.data.json();
    } catch (error) {
      data = { title: 'Notification', body: event.data.text() };
    }
    const target = safeLocalUrl(data.url);
    const fallbackIcon = config.fallbackIcon || '/static/plugins/browser-push/notification.png';
    event.waitUntil(self.registration.showNotification(data.title || 'Jyavani', {
      body: data.body || '',
      icon: data.icon || fallbackIcon,
      badge: data.badge || fallbackIcon,
      vibrate: [100, 50, 100],
      data: { url: target, timestamp: data.timestamp || Date.now() },
      actions: data.url ? [{ action: 'open', title: 'Buka' }] : [],
      tag: data.tag || 'jyavani-push',
      renotify: true,
    }));
  });

  self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = safeLocalUrl(event.notification.data?.url);
    event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async windowClients => {
      for (const client of windowClients) {
        if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
          if ('navigate' in client) await client.navigate(target);
          return client.focus();
        }
      }
      return clients.openWindow(target);
    }));
  });

  self.addEventListener('pushsubscriptionchange', event => {
    event.waitUntil((async () => {
      const oldEndpoint = event.oldSubscription?.endpoint || '';
      let subscription = event.newSubscription;
      const currentKey = subscriptionUsesKey(subscription || event.oldSubscription, config.vapidKey);
      if (!subscription && currentKey) {
        subscription = await self.registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: decodeKey(config.vapidKey),
        });
      }
      if (subscription && subscriptionUsesKey(subscription, config.vapidKey)) {
        const value = subscription.toJSON();
        await postJson(config.subscribeUrl || '/push-api/subscribe/', {
          endpoint: value.endpoint,
          keys: value.keys,
          oldEndpoint,
        });
      }
    })());
  });
})();
