/* Jyavani Browser Push frontend client v1.2.1 */
(function () {
  'use strict';

  const initialized = new WeakSet();

  function base64urlToUint8Array(value) {
    const padding = '='.repeat((4 - value.length % 4) % 4);
    const data = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(data, char => char.charCodeAt(0));
  }

  function supported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  }

  function workerRegistration() {
    if (!supported()) return Promise.reject(new Error('Push notifications are not available'));
    if (!window.JYAVANI_PUSH_WORKER) {
      window.JYAVANI_PUSH_WORKER = navigator.serviceWorker.register('/sw.js', { scope: '/' })
        .then(() => navigator.serviceWorker.ready);
    }
    return window.JYAVANI_PUSH_WORKER;
  }

  function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }

  function isStandalone() {
    return navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
  }

  function configFor(widget) {
    return {
      vapidKey: widget?.dataset.vapidKey || window.JYAVANI_PUSH_VAPID_KEY || '',
      subscribeUrl: widget?.dataset.subscribeUrl || window.JYAVANI_PUSH_SUBSCRIBE_URL || '/push-api/subscribe/',
      unsubscribeUrl: widget?.dataset.unsubscribeUrl || window.JYAVANI_PUSH_UNSUBSCRIBE_URL || '/push-api/unsubscribe/',
    };
  }

  async function fetchJson(url, payload) {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(payload),
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || data?.ok !== true) {
      throw new Error(data?.error || `Request failed (HTTP ${response.status})`);
    }
    return data;
  }

  function serialize(subscription) {
    const value = subscription.toJSON();
    return { endpoint: value.endpoint, keys: value.keys };
  }

  function subscriptionUsesKey(subscription, key) {
    const current = subscription.options?.applicationServerKey;
    if (!current || !key) return false;
    const expected = base64urlToUint8Array(key);
    const actual = new Uint8Array(current);
    return actual.length === expected.length && actual.every((value, index) => value === expected[index]);
  }

  async function persist(subscription, config, oldEndpoint) {
    const payload = serialize(subscription);
    if (oldEndpoint) payload.oldEndpoint = oldEndpoint;
    await fetchJson(config.subscribeUrl, payload);
    return subscription;
  }

  async function createSubscription(registration, config) {
    const subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: base64urlToUint8Array(config.vapidKey),
    });
    try {
      return await persist(subscription, config);
    } catch (error) {
      await subscription.unsubscribe().catch(() => false);
      throw error;
    }
  }

  async function deactivateThenUnsubscribe(subscription, config) {
    const payload = serialize(subscription);
    await fetchJson(config.unsubscribeUrl, { endpoint: payload.endpoint });
    const removed = await subscription.unsubscribe();
    if (!removed) {
      await fetchJson(config.subscribeUrl, payload).catch(() => null);
      throw new Error('Browser did not remove the subscription');
    }
  }

  async function resubscribe(registration, subscription, config) {
    await deactivateThenUnsubscribe(subscription, config);
    return createSubscription(registration, config);
  }

  function setUI(widget, state, message) {
    const button = widget.querySelector('.js-push-toggle');
    const label = widget.querySelector('.js-push-button-text');
    const status = widget.querySelector('.js-push-status');
    widget.dataset.pushState = state;
    if (label) label.textContent = state === 'subscribed' ? 'Unsubscribe' : (state === 'resubscribe' ? 'Resubscribe' : 'Subscribe');
    if (status) {
      status.textContent = message || (state === 'subscribed'
        ? 'Anda sudah berlangganan.'
        : (state === 'resubscribe' ? 'Kunci notifikasi berubah. Klik Resubscribe untuk memperbarui langganan.' : ''));
    }
    if (button) button.disabled = false;
  }

  async function currentState(registration, config) {
    const subscription = await registration.pushManager.getSubscription();
    if (!subscription) return { state: 'unsubscribed', subscription: null };
    if (!subscriptionUsesKey(subscription, config.vapidKey)) {
      return { state: 'resubscribe', subscription };
    }
    await persist(subscription, config);
    return {
      state: 'subscribed',
      subscription,
    };
  }

  async function setErrorUI(widget, error) {
    let state = 'unsubscribed';
    try {
      const registration = await workerRegistration();
      const subscription = await registration.pushManager.getSubscription();
      if (subscription) state = subscriptionUsesKey(subscription, configFor(widget).vapidKey) ? 'subscribed' : 'resubscribe';
    } catch (ignored) {
    }
    setUI(widget, state, `Gagal: ${error.message}`);
  }

  async function refreshWidget(widget) {
    const button = widget.querySelector('.js-push-toggle');
    const status = widget.querySelector('.js-push-status');
    if (!supported()) {
      if (status) status.textContent = isIOS()
        ? 'iOS membutuhkan iOS 16.4+ dan situs harus ditambahkan ke Home Screen.'
        : 'Browser tidak mendukung push notification.';
      if (button) button.hidden = true;
      return;
    }
    if (isIOS() && !isStandalone()) {
      if (status) status.textContent = 'Di iOS, tambahkan situs ke Home Screen sebelum mengaktifkan notifikasi.';
      if (button) button.hidden = true;
      return;
    }
    try {
      const registration = await workerRegistration();
      const result = await currentState(registration, configFor(widget));
      setUI(widget, result.state);
    } catch (error) {
      await setErrorUI(widget, error);
    }
  }

  async function subscribeFromAction(config, permissionPromise) {
    const [permission, registration] = await Promise.all([permissionPromise, workerRegistration()]);
    if (permission !== 'granted') throw new Error('Notification permission was not granted');
    const existing = await registration.pushManager.getSubscription();
    if (!existing) return createSubscription(registration, config);
    if (subscriptionUsesKey(existing, config.vapidKey)) return persist(existing, config);
    return resubscribe(registration, existing, config);
  }

  async function toggleFromAction(config, permissionPromise) {
    const [permission, registration] = await Promise.all([permissionPromise, workerRegistration()]);
    const subscription = await registration.pushManager.getSubscription();
    if (subscription && subscriptionUsesKey(subscription, config.vapidKey)) {
      return deactivateThenUnsubscribe(subscription, config);
    }
    if (permission !== 'granted') throw new Error('Notification permission was not granted');
    if (subscription) return resubscribe(registration, subscription, config);
    return createSubscription(registration, config);
  }

  function permissionFromClick() {
    return Notification.permission === 'default'
      ? Notification.requestPermission()
      : Promise.resolve(Notification.permission);
  }

  function initialize(widget) {
    if (initialized.has(widget)) return;
    initialized.add(widget);
    const button = widget.querySelector('.js-push-toggle');
    if (!button) return;
    button.addEventListener('click', function () {
      if (!supported()) {
        setUI(widget, 'unsubscribed', 'Browser tidak mendukung push notification.');
        return;
      }
      // This call must remain directly in the click activation.
      const permissionPromise = Notification.permission === 'default'
        ? Notification.requestPermission()
        : Promise.resolve(Notification.permission);
      button.disabled = true;
      const status = widget.querySelector('.js-push-status');
      if (status) status.textContent = 'Memproses...';
      toggleFromAction(configFor(widget), permissionPromise)
        .then(() => refreshWidget(widget))
        .catch(error => setErrorUI(widget, error));
    });
    refreshWidget(widget);
  }

  function initializeAll() {
    document.querySelectorAll('.js-jyavani-push').forEach(initialize);
  }

  window.JyavaniPush = {
    subscribe: () => {
      if (!supported()) return Promise.reject(new Error('Push notifications are not available'));
      const permissionPromise = permissionFromClick();
      return subscribeFromAction(configFor(document.querySelector('.js-jyavani-push')), permissionPromise);
    },
    unsubscribe: async () => {
      const config = configFor(document.querySelector('.js-jyavani-push'));
      const registration = await workerRegistration();
      const subscription = await registration.pushManager.getSubscription();
      return subscription ? deactivateThenUnsubscribe(subscription, config) : true;
    },
    isSubscribed: async () => {
      if (!supported()) return false;
      const registration = await workerRegistration();
      return (await registration.pushManager.getSubscription()) !== null;
    },
    isSupported: supported,
    initialize: initializeAll,
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeAll);
  else initializeAll();
})();
