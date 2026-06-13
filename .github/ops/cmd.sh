#!/usr/bin/env bash
set -uxo pipefail
BASE=207.180.209.83.sslip.io
JAR=$(mktemp)

echo "=== 1. fetch login page, capture XSRF + session cookie:"
curl -sL -c "$JAR" "https://app.$BASE/login" -o /dev/null -w 'login GET %{http_code}\n'
XSRF=$(grep -i 'XSRF-TOKEN' "$JAR" | awk '{print $7}' | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
echo "xsrf len: ${#XSRF}"

echo "=== 2. POST /login (form) with cookie jar:"
curl -s -b "$JAR" -c "$JAR" -X POST "https://app.$BASE/login" \
  -H "X-XSRF-TOKEN: $XSRF" -H 'Accept: text/html,application/xhtml+xml' \
  --data-urlencode 'email=admin@sophix.local' --data-urlencode 'password=password' \
  -o /dev/null -w 'login POST %{http_code} -> redirect %{redirect_url}\n'

echo "=== 3. cookies after login (session domain should be .$BASE):"
grep -E 'sophix|laravel|XSRF' "$JAR" | awk '{print $1, $6}'

echo "=== 4. SSO check: same jar against crm. and ops. subdomains:"
for h in app crm ops catalog; do
  code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "https://$h.$BASE/api/dashboard/summary")
  echo "$h.$BASE /api/dashboard/summary -> $code"
done

echo "=== 5. authenticated /api/me (who am I):"
curl -s -b "$JAR" "https://app.$BASE/api/auth/me" -w '\n(me %{http_code})\n' | head -c 400
rm -f "$JAR"
echo
echo OPS_DONE
