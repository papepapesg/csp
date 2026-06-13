#!/usr/bin/env bash
# Recreate Caddy with the Let's Encrypt pin + valid ACME email, wait for the cert, verify HTTPS.
set -uxo pipefail
BASE=207.180.209.83.sslip.io
grep -q '^ACME_EMAIL=' .env || echo 'ACME_EMAIL=papepapes@gmail.com' >> .env
docker compose up -d --force-recreate caddy
echo "=== waiting for a trusted cert on app.$BASE ..."
for i in $(seq 1 40); do
  code=$(curl -s -o /dev/null -w '%{http_code}' "https://app.$BASE/" --max-time 8 || echo 000)
  echo "  attempt $i: $code"; [ "$code" != "000" ] && break; sleep 5
done
echo "=== caddy logs tail:"; docker compose logs caddy --tail 30 2>&1 | tail -30
echo "=== per-subdomain HTTPS (verify=0 means a trusted Let's Encrypt cert):"
for h in app crm ops catalog settings reporting templates brand; do
  echo -n "https://$h.$BASE -> "; curl -s -o /dev/null -w '%{http_code} verify=%{ssl_verify_result}\n' "https://$h.$BASE/" --max-time 12 || echo fail
done
echo OPS_DONE
