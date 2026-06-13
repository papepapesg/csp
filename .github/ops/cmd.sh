#!/usr/bin/env bash
set -uxo pipefail
BASE=207.180.209.83.sslip.io
echo "=== /login asset tags (host + path):"
curl -sL "https://app.$BASE/login" | grep -oE '(src|href)="[^"]*build[^"]*"' | head -20
echo "=== inertia root present?"
curl -sL "https://app.$BASE/login" | grep -oE 'id="app" data-page="[^"]{0,60}' | head -1
echo "=== asset reachability (same host):"
for u in $(curl -sL "https://app.$BASE/login" | grep -oE '/build/assets/[A-Za-z0-9_.-]+\.(js|css)' | sort -u | head -6); do
  echo -n "$u -> "; curl -s -o /dev/null -w '%{http_code} %{content_type}\n' "https://app.$BASE$u"
done
echo "=== APP_URL / ASSET in container env:"
docker compose exec -T app sh -lc 'php -r "echo \"APP_URL=\".config(\"app.url\").\" ASSET_URL=\".(config(\"app.asset_url\")?:\"-\").PHP_EOL;"'
echo "=== app logs tail:"
docker compose logs app --tail 15 2>&1 | tail -15
echo OPS_DONE
