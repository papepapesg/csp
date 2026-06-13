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

H=(-b "$JAR" -H "Origin: https://catalog.$BASE" -H "Referer: https://catalog.$BASE/" -H 'Accept: application/json')
echo "=== catalog data counts:"
for ep in packages services tax/rules commercial-bundles campaigns discounts; do
  n=$(curl -s "${H[@]}" "https://catalog.$BASE/api/$ep" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(len(d.get("items") or d.get("data") or []))' 2>/dev/null)
  echo "  $ep -> ${n:-ERR}"
done

echo "=== catalog pages — actual Inertia component:"
cat >/tmp/comp.py <<'PY'
import sys,json,html,re
m=re.search(r'data-page="([^"]+)"', sys.stdin.read())
print("component:", json.loads(html.unescape(m.group(1)))["component"] if m else "NONE")
PY
for path in catalog/setup commercial/studio; do
  echo -n "  catalog.$BASE/$path -> "; curl -sL -b "$JAR" "https://catalog.$BASE/$path" | python3 /tmp/comp.py
done
rm -f "$JAR" /tmp/comp.py
echo OPS_DONE
