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

FROM=$(date -u -d '-30 days' +%F); TO=$(date -u +%F)
H=(-b "$JAR" -H "Origin: https://reporting.$BASE" -H "Referer: https://reporting.$BASE/" -H 'Accept: application/json')
echo "=== ops dashboard metrics ($FROM..$TO):"
curl -s "${H[@]}" "https://reporting.$BASE/api/reports/dashboards/operations-overview?from=$FROM&to=$TO" | python3 -c '
import sys,json
d=json.load(sys.stdin); m=d.get("metrics",{})
print("  metric keys:", len(m), "->", ", ".join(list(m.keys())[:6]))
print("  sample:", {k:m[k] for k in list(m)[:4]})
'
echo "=== reconcile:"
curl -s "${H[@]}" "https://reporting.$BASE/api/reports/reconcile?from=$FROM&to=$TO" | python3 -c 'import sys,json;d=json.load(sys.stdin);print("  checked:",d.get("checked"),"inSync:",d.get("inSync"))'

echo "=== page renders:"
cat >/tmp/comp.py <<'PY'
import sys,json,html,re
m=re.search(r'data-page="([^"]+)"', sys.stdin.read())
print("component:", json.loads(html.unescape(m.group(1)))["component"] if m else "NONE")
PY
echo -n "  reporting.$BASE/reports -> "; curl -sL -b "$JAR" "https://reporting.$BASE/reports" | python3 /tmp/comp.py
rm -f "$JAR" /tmp/comp.py
echo OPS_DONE
