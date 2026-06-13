#!/usr/bin/env bash
set -uxo pipefail
BASE=207.180.209.83.sslip.io
JAR=$(mktemp)

curl -sL -c "$JAR" "https://app.$BASE/login" -H "Origin: https://app.$BASE" -H "Referer: https://app.$BASE/" -o /dev/null
XSRF=$(grep -i 'XSRF-TOKEN' "$JAR" | awk '{print $7}' | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
curl -s -b "$JAR" -c "$JAR" -X POST "https://app.$BASE/login" \
  -H "X-XSRF-TOKEN: $XSRF" -H "Origin: https://app.$BASE" -H "Referer: https://app.$BASE/login" \
  --data-urlencode 'email=admin@sophix.local' --data-urlencode 'password=password' -o /dev/null -w 'login %{http_code}\n'

H=(-b "$JAR" -H "Origin: https://crm.$BASE" -H "Referer: https://crm.$BASE/" -H 'Accept: application/json')
CID=$(curl -s "${H[@]}" "https://crm.$BASE/api/customers" | python3 -c 'import sys,json;print((json.load(sys.stdin).get("items") or [{}])[0].get("customerId",""))')
echo "customerId=$CID"

cat >/tmp/inspect.py <<'PY'
import sys,json
d=json.load(sys.stdin); p=d.get("panels",{})
for k,v in p.items():
    print("  panel", k, "available=", v.get("available"))
prof=(p.get("profile") or {}).get("data") or {}
print("  profile keys:", sorted(prof.keys()))
accs=(p.get("accounts") or {}).get("data") or []
print("  account[0] keys:", sorted(accs[0].keys()) if accs else "none")
print("  billing.balanceDue:", ((p.get("billing") or {}).get("data") or {}).get("balanceDue"))
print("  subscriptions:", len((p.get("subscriptions") or {}).get("data") or []))
print("  tickets:", len((p.get("tickets") or {}).get("data") or []))
PY
curl -s "${H[@]}" "https://crm.$BASE/api/customers/$CID/overview" | python3 /tmp/inspect.py
rm -f "$JAR" /tmp/inspect.py
echo OPS_DONE
