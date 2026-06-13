#!/usr/bin/env bash
# Phase-0 viability probe for subdomain apps: confirm sslip.io resolves to this box
# and that 80/443 are available for a Caddy front.
set -uxo pipefail
echo "--- sslip.io resolves to the VPS?"
getent hosts crm.207.180.209.83.sslip.io || nslookup crm.207.180.209.83.sslip.io || true
echo "--- who listens on 80/443 right now?"
(command -v ss >/dev/null && ss -ltnp '( sport = :80 or sport = :443 )') || (command -v netstat >/dev/null && netstat -ltnp | grep -E ':80|:443') || true
echo "--- current compose port bindings"
docker compose ps --format '{{.Service}} {{.Ports}}'
echo OPS_DONE
