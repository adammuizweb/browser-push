/* Jyavani Push — Frontend Subscription Manager v1.0.0 */
/* Include this script on pages where you want push notification subscribe button */

(function() {
  'use strict';

  const VAPID_PUBLIC_KEY = window.JYAVANI_PUSH_VAPID_KEY || '';
  const SUBSCRIBE_URL = window.JYAVANI_PUSH_SUBSCRIBE_URL || '/push-api/subscribe';
  const UNSUBSCRIBE_URL = window.JYAVANI_PUSH_UNSUBSCRIBE_URL || '/push-api/unsubscribe';

  function base64urlToUint8Array(base64urlString) {
    const padding = '='.repeat((4 - base64urlString.length % 4) % 4);
    const base64 = (base64urlString + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; i++) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }

  function isPushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  }

  async function getSubscription() {
    if (!isPushSupported()) return null;
    const registration = await navigator.serviceWorker.ready;
    return await registration.pushManager.getSubscription();
  }

  async function subscribe() {
    if (!isPushSupported()) {
      console.warn('Push notifications not supported');
      return false;
    }

    if (!VAPID_PUBLIC_KEY) {
      console.warn('VAPID public key not configured');
      return false;
    }

    // Request permission
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return false;

    const registration = await navigator.serviceWorker.ready;
    let subscription = await registration.pushManager.getSubscription();

    if (!subscription) {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: base64urlToUint8Array(VAPID_PUBLIC_KEY),
      });
    }

    // Send to server
    const subJson = subscription.toJSON();
    const resp = await fetch(SUBSCRIBE_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        endpoint: subJson.endpoint,
        keys: subJson.keys,
      }),
    });

    const data = await resp.json();
    return data.ok === true;
  }

  async function unsubscribe() {
    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();
    if (!subscription) return true;

    await subscription.unsubscribe();

    const subJson = subscription.toJSON();
    await fetch(UNSUBSCRIBE_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ endpoint: subJson.endpoint }),
    });

    return true;
  }

  async function isSubscribed() {
    const sub = await getSubscription();
    return sub !== null;
  }

  // Expose globally
  window.JyavaniPush = {
    subscribe,
    unsubscribe,
    isSubscribed,
    isSupported: isPushSupported,
  };
})();
