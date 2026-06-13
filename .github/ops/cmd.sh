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

cat >/tmp/comp.py <<'PY'
import sys,json,html,re
m=re.search(r'data-page="([^"]+)"', sys.stdin.read())
print(json.loads(html.unescape(m.group(1)))["component"] if m else "NONE")
PY

echo "=== Operations app — every console renders on ops.$BASE:"
for path in dashboard fulfillment work-orders tickets billing equipment warehouse workforce noc; do
  echo -n "  ops/$path -> "; curl -sL -b "$JAR" "https://ops.$BASE/$path" | python3 /tmp/comp.py
done

echo "=== Launcher tiles (app grid) on app.$BASE:"
curl -s -b "$JAR" -H "Origin: https://app.$BASE" -H "Referer: https://app.$BASE/" -H 'Accept: application/json' "https://app.$BASE/api/auth/me" >/dev/null
curl -sL -b "$JAR" "https://app.$BASE/" | python3 -c '
import sys,json,html,re
m=re.search(r"data-page=\"([^\"]+)\"", sys.stdin.read())
d=json.loads(html.unescape(m.group(1))) if m else {}
apps=(d.get("props",{}).get("portal",{}) or {}).get("apps",[])
print("  component:", d.get("component"))
print("  app tiles:", len(apps), "->", ", ".join(a.get("slug","?") for a in apps))
'
rm -f "$JAR" /tmp/comp.py
echo OPS_DONE
