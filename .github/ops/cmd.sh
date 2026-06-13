#!/usr/bin/env bash
set -uxo pipefail
BASE=207.180.209.83.sslip.io

# PHP app code is baked into the runtime image: rebuild so the TrustProxies + forceScheme
# fix ships, then recreate the app-tier containers (datastores keep running, data preserved).
docker compose build app web
docker compose up -d --no-deps app web queue scheduler
# Refresh the cached config so APP_URL / proxy settings are picked up.
docker compose exec -T app php artisan config:cache || true
sleep 5

echo "=== /login asset tags (should now be https://):"
curl -sL "https://app.$BASE/login" | grep -oE '(src|href)="[^"]*build[^"]*"' | head -20
echo "=== inertia root present?"
curl -sL "https://app.$BASE/login" | grep -oE 'id="app" data-page="[^"]{0,40}' | head -1
echo "=== APP_URL in container:"
docker compose exec -T app sh -lc 'php -r "echo config(\"app.url\").PHP_EOL;"'
echo OPS_DONE
