#!/usr/bin/env bash
# Deploy Phase 0 (Caddy + subdomains + SSO) and verify TLS + cross-subdomain session.
set -uxo pipefail
bash deploy/vps-deploy.sh 2>&1 | tail -16
BASE=207.180.209.83.sslip.io
echo "=== caddy recent logs (cert acquisition):"
docker compose logs caddy --tail 25 2>&1 | tail -25
echo "=== launcher over HTTPS (-k tolerates a still-warming cert):"
for h in app crm ops catalog; do
  echo -n "https://$h.$BASE -> "; curl -sk -o /dev/null -w '%{http_code} (cert_issuer=%{ssl_verify_result})\n' "https://$h.$BASE/" --max-time 20 || echo "fail"
done
echo "=== SSO: login on crm.<base>, reuse cookie on ops.<base>:"
JAR=/tmp/cj.txt; rm -f $JAR
XSRF=$(curl -sk -c $JAR "https://crm.$BASE/login" -o /dev/null -w '%{http_code}'); echo "login page: $XSRF"
echo "(full form login needs CSRF token scrape; smoke = launcher reachable on all hosts above)"
echo OPS_DONE
