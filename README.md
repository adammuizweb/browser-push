# Browser Push

Browser Push notifications for Jyavani CMS using VAPID and the Web Push API.

## Runtime Setup

The send helper requires Node.js and the pinned `web-push` dependency:

```bash
npm ci --omit=dev
```

Run this command in the plugin directory after installing or updating the plugin. The public subscription routes and dashboard remain available without the Node dependency, but notification delivery will fail closed until it is installed.
