#!/usr/bin/env bash
set -uxo pipefail
BASE=207.180.209.83.sslip.io

echo "=== stack state ==="
docker compose ps --format '{{.Service}} {{.State}}' 2>/dev/null | head -12

# Bring up only the edge + web tier from the EXISTING image (no rebuild, no app/db touch).
docker compose up -d --no-deps web caddy 2>&1 | tail -4
sleep 3

# Drop the static prototype into nginx's docroot. (Ephemeral: lives until the web container is
# recreated/redeployed — fine for a visual test.)
docker compose exec -T web sh -lc 'mkdir -p /var/www/html/public/prototype'
docker compose cp design/prototype/wo-dispatcher.html web:/var/www/html/public/prototype/wo-dispatcher.html
docker compose exec -T web sh -lc 'ls -la /var/www/html/public/prototype/'

echo "=== reachability (via Caddy edge, https) ==="
code=$(curl -s -o /dev/null -w '%{http_code}' "https://app.$BASE/prototype/wo-dispatcher.html")
echo "  https://app.$BASE/prototype/wo-dispatcher.html -> ${code:-ERR}"
echo
echo "OPEN >>> https://app.$BASE/prototype/wo-dispatcher.html"
echo OPS_DONE
