#!/usr/bin/env bash
set -uxo pipefail
BASE=207.180.209.83.sslip.io
JAR=$(mktemp)

# Rebuild (Vue assets + PHP are baked into the image) and recreate the app tier.
docker compose build app web
docker compose up -d --no-deps app web queue scheduler
docker compose exec -T app php artisan config:cache || true
sleep 5

echo "=== login (browser-faithful):"
curl -sL -c "$JAR" "https://app.$BASE/login" -H "Origin: https://app.$BASE" -H "Referer: https://app.$BASE/" -o /dev/null
XSRF=$(grep -i 'XSRF-TOKEN' "$JAR" | awk '{print $7}' | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
curl -s -b "$JAR" -c "$JAR" -X POST "https://app.$BASE/login" \
  -H "X-XSRF-TOKEN: $XSRF" -H "Origin: https://app.$BASE" -H "Referer: https://app.$BASE/login" \
  --data-urlencode 'email=admin@sophix.local' --data-urlencode 'password=password' \
  -o /dev/null -w 'login POST %{http_code}\n'

H=(-b "$JAR" -H "Origin: https://crm.$BASE" -H "Referer: https://crm.$BASE/" -H 'Accept: application/json')

echo "=== grab first customer id:"
CID=$(curl -s "${H[@]}" "https://crm.$BASE/api/customers" | python3 -c 'import sys,json;d=json.load(sys.stdin);print((d.get("items") or [{}])[0].get("customerId",""))')
echo "customerId=$CID"

echo "=== overview panels (new fields should appear: profile.email/createdAt, accounts[].subStatus):"
curl -s "${H[@]}" "https://crm.$BASE/api/customers/$CID/overview" | python3 -c '
import sys,json
d=json.load(sys.stdin); p=d.get("panels",{})
for k,v in p.items(): print(f"  {k}: available={v.get(\"available\")}")
prof=p.get("profile",{}).get("data",{}) or {}
print("  profile keys:", sorted(prof.keys()))
accs=p.get("accounts",{}).get("data",[]) or []
print("  account[0] keys:", sorted(accs[0].keys()) if accs else "none")
print("  billing.balanceDue:", (p.get("billing",{}).get("data",{}) or {}).get("balanceDue"))
print("  subscriptions:", len(p.get("subscriptions",{}).get("data",[]) or []))
'

echo "=== 360 HTML renders (component Ilm/Customers/Show, https assets):"
curl -sL -b "$JAR" "https://crm.$BASE/customers/$CID" | grep -oE 'Ilm.Customers.Show|src="https://[^"]*app-[^"]*\.js"' | head -3
rm -f "$JAR"
echo OPS_DONE
