#!/usr/bin/env bash
set -uxo pipefail
BASE=207.180.209.83.sslip.io
JAR=$(mktemp)

docker compose build app web
docker compose up -d --no-deps app web queue scheduler
docker compose exec -T app php artisan config:cache || true
sleep 5

curl -sL -c "$JAR" "https://app.$BASE/login" -H "Origin: https://app.$BASE" -H "Referer: https://app.$BASE/" -o /dev/null
XSRF=$(grep -i 'XSRF-TOKEN' "$JAR" | awk '{print $7}' | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
curl -s -b "$JAR" -c "$JAR" -X POST "https://app.$BASE/login" \
  -H "X-XSRF-TOKEN: $XSRF" -H "Origin: https://app.$BASE" -H "Referer: https://app.$BASE/login" \
  --data-urlencode 'email=admin@sophix.local' --data-urlencode 'password=password' -o /dev/null -w 'login %{http_code}\n'

H=(-b "$JAR" -H "Origin: https://brand.$BASE" -H "Referer: https://brand.$BASE/" -H 'Accept: application/json')
echo "=== operator-config (theme):"
curl -s "${H[@]}" "https://brand.$BASE/api/operator-config" | python3 -c 'import sys,json;d=json.load(sys.stdin);print("  operator:",d.get("operator_code"),"color:",d.get("theme_primary_color"),"locale:",d.get("default_locale"),"currency:",d.get("currency_code"))'
echo "=== i18n meta:"
curl -s "${H[@]}" "https://brand.$BASE/api/i18n/meta" | python3 -c 'import sys,json;d=json.load(sys.stdin);print("  locales:",d.get("locales"),"domains:",len(d.get("domains") or []))'

echo "=== page renders:"
cat >/tmp/comp.py <<'PY'
import sys,json,html,re
m=re.search(r'data-page="([^"]+)"', sys.stdin.read())
print("component:", json.loads(html.unescape(m.group(1)))["component"] if m else "NONE")
PY
echo -n "  brand.$BASE/i18n/studio -> "; curl -sL -b "$JAR" "https://brand.$BASE/i18n/studio" | python3 /tmp/comp.py
rm -f "$JAR" /tmp/comp.py
echo OPS_DONE
