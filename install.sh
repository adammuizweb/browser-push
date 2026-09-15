#!/bin/sh
set -eu

cd "$(dirname "$0")"
command -v node >/dev/null 2>&1 || { printf '%s\n' 'browser-push requires Node.js 16 or newer.' >&2; exit 1; }

node -e 'const major = Number(process.versions.node.split(".")[0]); process.exit(major >= 16 ? 0 : 1)' \
  || { printf '%s\n' 'browser-push requires Node.js 16 or newer.' >&2; exit 1; }
test -f node_modules/web-push/package.json \
  || { printf '%s\n' 'browser-push runtime dependencies are missing from the release package.' >&2; exit 1; }
