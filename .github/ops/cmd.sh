#!/usr/bin/env bash
# Properly wait for Caddy's Let's Encrypt cert, then diagnose + verify HTTPS per subdomain.
set -uxo pipefail
BASE=207.180.209.83.sslip.io
docker compose up -d caddy
echo "=== polling for a working cert on app.$BASE (up to ~4 min) ..."
for i in $(seq 1 48); do
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 "https://app.$BASE/")
  code=${code:-000}
  echo "  attempt $i: $code"
  [ "$code" != "000" ] && break
  sleep 5
done
echo "=== caddy ACME logs:"
docker compose logs caddy 2>&1 | grep -iE 'acme|challenge|certificate|obtain|error|http-01|tls' | tail -30
echo "=== per-subdomain HTTPS (verify=0 = trusted LE cert):"
for h in app crm ops catalog settings; do
  echo -n "https://$h.$BASE -> "; curl -s -o /dev/null -w '%{http_code} verify=%{ssl_verify_result}\n' --max-time 12 "https://$h.$BASE/"
done
echo OPS_DONE
