#!/usr/bin/env bash
set -uxo pipefail
BASE=207.180.209.83.sslip.io

# Rebuild app+web (new pocIndex + POC route are baked into the image), bring the stack up.
docker compose build app web
docker compose up -d
sleep 6
docker compose exec -T app php artisan optimize:clear || true

# Seed the POC work-order data (idempotent updateOrInsert).
docker compose exec -T app php artisan db:seed --class="Modules\\WorkOrder\\Database\\Seeders\\WoPocSeeder" --force 2>&1 | tail -4

# Serve the prototype static page from nginx docroot.
docker compose exec -T web sh -lc 'mkdir -p /var/www/html/public/prototype'
docker compose cp design/prototype/wo-dispatcher.html web:/var/www/html/public/prototype/wo-dispatcher.html

echo "=== POC API (no auth) — seeded WO list ==="
curl -s "https://app.$BASE/api/poc/work-orders" | python3 -c '
import sys,json
d=json.load(sys.stdin); items=d.get("items") or (d.get("data") or {}).get("items") or []
print("  items:", len(items))
if items: print("  sample:", {k:items[0][k] for k in ("woNumber","customer","jobType","assignee","status","sla")})
'
echo "=== prototype page ==="
echo -n "  /prototype/wo-dispatcher.html -> "; curl -s -o /dev/null -w '%{http_code}\n' "https://app.$BASE/prototype/wo-dispatcher.html"
echo
echo "OPEN >>> https://app.$BASE/prototype/wo-dispatcher.html"
echo OPS_DONE
