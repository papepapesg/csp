#!/usr/bin/env bash
set -uxo pipefail
BASE=207.180.209.83.sslip.io
JAR=$(mktemp)

echo "=== 1. GET login (seed XSRF + session cookie), browser-faithful headers:"
curl -sL -c "$JAR" "https://app.$BASE/login" \
  -H "Origin: https://app.$BASE" -H "Referer: https://app.$BASE/" -o /dev/null -w 'login GET %{http_code}\n'
XSRF=$(grep -i 'XSRF-TOKEN' "$JAR" | awk '{print $7}' | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')

echo "=== 2. POST /login with X-XSRF-TOKEN + Origin/Referer:"
curl -s -b "$JAR" -c "$JAR" -X POST "https://app.$BASE/login" \
  -H "X-XSRF-TOKEN: $XSRF" -H "Origin: https://app.$BASE" -H "Referer: https://app.$BASE/login" \
  -H 'Accept: text/html,application/xhtml+xml' \
  --data-urlencode 'email=admin@sophix.local' --data-urlencode 'password=password' \
  -o /dev/null -w 'login POST %{http_code} -> %{redirect_url}\n'

echo "=== 3. SSO: same cookie jar, each subdomain sends its OWN Origin/Referer (as the SPA would):"
for h in app crm ops catalog reporting; do
  code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' \
    -H "Origin: https://$h.$BASE" -H "Referer: https://$h.$BASE/" -H 'Accept: application/json' \
    "https://$h.$BASE/api/dashboard/summary")
  echo "$h.$BASE /api/dashboard/summary -> $code"
done

echo "=== 4. who am I (app host):"
curl -s -b "$JAR" -H "Origin: https://app.$BASE" -H "Referer: https://app.$BASE/" -H 'Accept: application/json' \
  "https://app.$BASE/api/auth/me" -w '\n(me %{http_code})\n' | head -c 500
rm -f "$JAR"
echo
echo OPS_DONE
