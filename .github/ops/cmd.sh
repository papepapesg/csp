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

H=(-b "$JAR" -H "Origin: https://settings.$BASE" -H "Referer: https://settings.$BASE/" -H 'Accept: application/json')
echo "=== rbac data:"
for ep in rbac/roles rbac/permissions rbac/users rbac/audit; do
  n=$(curl -s "${H[@]}" "https://settings.$BASE/api/$ep" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(len(d.get("items") or d.get("content") or []))' 2>/dev/null)
  echo "  $ep -> ${n:-ERR}"
done

echo "=== settings pages render:"
cat >/tmp/comp.py <<'PY'
import sys,json,html,re
m=re.search(r'data-page="([^"]+)"', sys.stdin.read())
print("component:", json.loads(html.unescape(m.group(1)))["component"] if m else "NONE")
PY
for path in admin/rbac itops admin; do
  echo -n "  settings.$BASE/$path -> "; curl -sL -b "$JAR" "https://settings.$BASE/$path" | python3 /tmp/comp.py
done
rm -f "$JAR" /tmp/comp.py
echo OPS_DONE
