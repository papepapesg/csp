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

H=(-b "$JAR" -H "Origin: https://studio-workflow.$BASE" -H "Referer: https://studio-workflow.$BASE/" -H 'Accept: application/json')

echo "=== workflow palette has descriptions?"
curl -s "${H[@]}" "https://studio-workflow.$BASE/api/workflow/palette" | python3 -c '
import sys,json
d=json.load(sys.stdin).get("data", {})
nt=d.get("nodeTypes",[]); st=d.get("steps",[])
print("  nodeTypes:", len(nt), "with description:", sum(1 for x in nt if x.get("description")))
print("  steps:", len(st), "with description:", sum(1 for x in st if x.get("description")))
if nt: print("  e.g. nodeType:", nt[0]["type"], "->", (nt[0].get("description","")[:70]))
if st: print("  e.g. step:", st[0]["topic"], "->", (st[0].get("description","")[:70]))
'

echo "=== studio pages render (component + https assets):"
for slug in studio-workflow:workflow/studio studio-rules:rules/studio studio-dunning:dunning/studio; do
  sub=${slug%%:*}; path=${slug##*:}
  echo -n "  $sub/$path -> "
  curl -sL -b "$JAR" "https://$sub.$BASE/$path" | grep -oE 'Workflow.Studio|Rules.Studio|DunningStudio' | head -1
done
rm -f "$JAR"
echo OPS_DONE
