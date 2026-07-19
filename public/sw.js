/* Jyavani Push — Service Worker v1.0.0 */
/* Handles push events and notification clicks */

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(clients.claim());
});

self.addEventListener('push', (event) => {
  if (!event.data) return;

  let data;
  try {
    data = event.data.json();
  } catch (e) {
    data = {
      title: 'Notification',
      body: event.data.text(),
    };
  }

  const title = data.title || 'Jyavani';
  const options = {
    body: data.body || '',
    icon: data.icon || '/static/icons/lucide/bell.svg',
    badge: data.badge || '/static/icons/lucide/bell.svg',
    vibrate: [100, 50, 100],
    data: {
      url: data.url || '/',
      timestamp: data.timestamp || Date.now(),
    },
    actions: data.url ? [
      { action: 'open', title: 'Buka', icon: '/static/icons/lucide/external-link.svg' },
    ] : [],
    tag: data.tag || 'jyavani-push',
    renotify: true,
  };

  event.waitUntil(
    self.registration.showNotification(title, options)
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const url = event.notification.data?.url || '/';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      // Check if there's already a window open
      for (const client of windowClients) {
        if (client.url.includes(self.location.origin) && 'focus' in client) {
          client.navigate(url);
          return client.focus();
        }
      }
      // Open new window
      return clients.openWindow(url);
    })
  );
});
