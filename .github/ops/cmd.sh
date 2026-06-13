#!/usr/bin/env bash
# Diagnose "no data in sections": authenticate, hit the key list endpoints, dump
# DB row counts and recent app logs so we can see empty-DB vs API-error vs shape.
set -uxo pipefail

TOKEN=$(curl -s -X POST http://localhost/api/auth/token -H 'Content-Type: application/json' \
  -d '{"email":"admin@sophix.local","password":"password"}' | grep -oE '"token":"[^"]*"' | cut -d'"' -f4)
echo "token_len=${#TOKEN}"

for ep in packages services service-classes wallet-catalog tax/rules commercial-bundles campaigns \
          customers subscriptions invoices payments work-orders tickets stock-balances contractors \
          reports/dashboards/operations-overview dashboard/summary; do
  echo "===== GET /api/$ep"
  curl -s -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' "http://localhost/api/$ep" | head -c 400
  echo
done

echo "===== DB row counts"
docker compose exec -T postgres psql -U sophix -d sophix -tc "
  select 'package', count(*) from package union all
  select 'service', count(*) from service union all
  select 'wallet_catalog', count(*) from wallet_catalog union all
  select 'tax_rule', count(*) from tax_rule union all
  select 'commercial_bundle', count(*) from commercial_bundle union all
  select 'customer', count(*) from customer union all
  select 'subscription', count(*) from subscription union all
  select 'invoice', count(*) from invoice union all
  select 'work_order', count(*) from work_order union all
  select 'ticket', count(*) from ticket union all
  select 'stock_balance', count(*) from stock_balance;"

echo "===== recent app logs"
docker compose logs app --tail 25 2>&1 | tail -25
echo OPS_DONE
