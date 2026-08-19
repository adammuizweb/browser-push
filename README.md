# Browser Push

Browser Push 1.2.2 provides VAPID Web Push notifications for Jyavani CMS. The PWA plugin owns the root service worker and its lifecycle; Browser Push eagerly invokes that worker's idempotent registration and appends only push, notification-click, and subscription-refresh handlers.

## Requirements

- Jyavani 2.3.74 or newer
- PWA plugin 1.0.0 or newer, active and configured
- PHP 8.1+ with PDO, JSON, and OpenSSL
- Node.js 16+ and npm
- HTTPS in production

The plugin manifest declares Jyavani and PWA under `requires`. The plugin detail screen checks that the installed `web-push` runtime file exists; Node.js itself remains a documented host requirement because Core setup checks intentionally support only filesystem and PHP-extension checks.

## Install

Jyavani 2.3.60 runs the executable `install.sh` after ZIP upload, Plugin Store installation, and Plugin Store updates, so packaged installs run `npm ci --omit=dev` automatically. For a source checkout or manual installation, run this in the plugin directory:

```bash
npm ci --omit=dev
```

The runtime dependency is pinned to `web-push` 3.4.5 in both `package.json` and `package-lock.json`. This intentionally preserves the repository's documented Apple APNs compatibility; later releases previously regressed Apple delivery here. Push delivery fails closed and the dashboard displays a warning if the dependency is absent. Set `BROWSER_PUSH_NODE_BINARY` when `node` is not on the PHP process's `PATH`.

Jyavani publishes only the files declared by `static.copy` into `/static/plugins/browser-push/`: the frontend client, admin CSS, and fallback PNG. PHP, Node helpers, dependencies, and VAPID secrets remain outside the public web root.

## VAPID Keys

Install dependencies, then generate standards-compliant P-256 keys with `web-push`:

```bash
php generate-vapid.php
```

The command prints a new key pair but does not write it to disk. Add it in **Tools > Push Notifications > Settings**, or configure the PHP runtime environment:

```dotenv
BROWSER_PUSH_VAPID_PUBLIC_KEY=...
BROWSER_PUSH_VAPID_PRIVATE_KEY=...
BROWSER_PUSH_VAPID_SUBJECT=mailto:admin@example.com
```

Environment values take precedence over database settings. The settings form never renders a stored private key back into HTML. Keep the same VAPID pair for the lifetime of existing browser subscriptions. After a key change, clients show **Resubscribe** when they next load the widget and rotate only when the user clicks it.

Deployment note for this upgrade: the currently configured public VAPID key is invalid and the subscription table is empty, so regenerate the VAPID pair after installation. No live VAPID database value is changed by this package or its installer.

Notification icons use the PWA settings `pwa_icon_192_url`, then `pwa_icon_512_url`. If neither is usable, Browser Push uses `/static/plugins/browser-push/notification.png`.

## Frontend

Add the **Push Notifications** sidebar widget. It uses the PWA-owned root service worker and the canonical static client. The browser calls these same-origin JSON endpoints, including their required trailing slashes:

- `POST /push-api/subscribe/`
- `POST /push-api/unsubscribe/`

Browser Push starts the PWA-owned root worker registration from Core's public `init`/`jy_head` lifecycle so the registration is normally ready before interaction. The client requests notification permission directly in the button's click activation, subscribes with minimal additional promise delay, reconciles an existing browser subscription with the server, checks both HTTP and JSON success, and rolls back failed creates/deletes where possible. A VAPID-key mismatch is never rotated automatically: the widget shows an actionable **Resubscribe** state and changes the browser subscription only after that click. The worker refreshes subscriptions automatically only when the existing subscription already uses the current VAPID key.

Public mutations require same-origin browser request metadata matched against the trusted CMS `site_url` (with Core's `FORCE_HTTPS`/server configuration as fallback), never client-supplied forwarding headers. Requests accept only bounded JSON, rate-limit each endpoint hash with a generous direct-IP abuse ceiling, validate standard subscription key lengths, and restrict endpoints to the major Chrome/Chromium, Firefox, Safari, and legacy Microsoft push providers.

Upgrades replace the old 200-character prefix uniqueness index with a full SHA-256 endpoint identity, merge exact duplicate rows, and run a one-time quarantine of invalid legacy endpoints. Every delivery validates the stored endpoint and keys again and deactivates invalid rows before Node.js receives them.

Notification click targets must be normalized same-origin paths such as `/article/` or absolute URLs such as `https://example.com/search/?q=push`. Fragments, backslashes, control bytes, dot traversal, encoded backslashes/traversal, malformed percent encoding, and cross-origin URLs are rejected; invalid targets open the site root.

## Admin JSON API

Admin JSON routes must be requested through Jyavani's query dispatcher with a non-empty `action`, otherwise the normal dashboard layout owns the request. They require an authenticated admin session and a valid `csrf_token` in the JSON body.

```text
POST {ADMIN_BASE_PATH}/?page=admin/tools/push-notifications/api/send&action=send
POST {ADMIN_BASE_PATH}/?page=admin/tools/push-notifications/api/test&action=test
```

Send payload:

```json
{
  "csrf_token": "session token",
  "title": "New article",
  "body": "Read it now",
  "url": "/article/",
  "icon": ""
}
```

Titles, bodies, and URLs have server-side scalar and size limits. Responses use pure JSON. `ok` is false when there are no subscribers or any delivery fails, and HTTP 404/410 push responses deactivate the stale subscription.

Broadcast delivery is synchronous and starts one bounded Node.js process per active subscription. VAPID/settings and notification defaults are loaded once per broadcast, but large subscriber sets still scale linearly and can exceed a web request budget. Use a queue/worker integration before operating at high volume.

## Development Checks

```bash
npm ci
npm test
npm run test:apns
php tests/plugin_test.php
php -l plugin.php
node --check public/push.js
node --check public/sw.js
```
