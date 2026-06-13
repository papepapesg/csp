#!/usr/bin/env bash
# Verify-only (no redeploy): wait for the app to be healthy, then prove the cross-tenant block.
set -uxo pipefail
for i in $(seq 1 30); do
  code=$(curl -s -o /dev/null -w '%{http_code}' http://localhost/api/health || true)
  [ "$code" = "200" ] && break
  sleep 2
done
echo "--- health: $(curl -s http://localhost/api/health)"
TOKEN=$(curl -s -X POST http://localhost/api/auth/token -H 'Content-Type: application/json' \
  -d '{"email":"admin@sophix.local","password":"password"}')
echo "--- token resp: $(echo "$TOKEN" | head -c 120)"
TOK=$(echo "$TOKEN" | grep -oE '"(token|access_token|plainTextToken)":"[^"]*"' | head -1 | cut -d'"' -f4)
echo "--- token len: ${#TOK}"
echo "--- ATTACK: WIK admin + spoofed X-Operator-Code: ZZZ (expect only WIK, no leak):"
curl -s -H "Authorization: Bearer $TOK" -H 'X-Operator-Code: ZZZ' http://localhost/api/customers \
  | grep -oE '"operatorCode":"[A-Z]+"' | sort -u
echo "--- total returned:"; curl -s -H "Authorization: Bearer $TOK" -H 'X-Operator-Code: ZZZ' http://localhost/api/customers | grep -oE '"totalElements":[0-9]+'
echo OPS_DONE
