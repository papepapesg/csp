#!/usr/bin/env bash
# Deploy the security hardening (down→migrate/seed→up) and verify the cross-tenant block live:
# a WIK-scoped admin token must NOT see another operator's data via a spoofed X-Operator-Code.
set -uxo pipefail
bash deploy/vps-deploy.sh 2>&1 | tail -8
TOKEN=$(curl -s -X POST http://localhost/api/auth/token -H 'Content-Type: application/json' \
  -d '{"email":"admin@sophix.local","password":"password"}' | grep -oE '"token":"[^"]*"' | cut -d'"' -f4)
echo "--- customers WITH spoofed X-Operator-Code: ZZZ (must still be WIK-only, not error/leak):"
curl -s -H "Authorization: Bearer $TOKEN" -H 'X-Operator-Code: ZZZ' http://localhost/api/customers | head -c 300; echo
echo "--- operators present in that response (expect only WIK):"
curl -s -H "Authorization: Bearer $TOKEN" -H 'X-Operator-Code: ZZZ' http://localhost/api/customers | grep -oE '"operatorCode":"[A-Z]+"' | sort -u
echo OPS_DONE
