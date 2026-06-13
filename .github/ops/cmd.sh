#!/usr/bin/env bash
# Reseed the demo with the enriched catalog/data (down→seed-one-off→up), then verify row counts.
set -uxo pipefail
bash deploy/vps-deploy.sh 2>&1 | tail -15
echo "--- row counts after reseed"
docker compose exec -T postgres psql -U sophix -d sophix -tc "
  select 'package', count(*) from package union all
  select 'service', count(*) from service union all
  select 'commercial_bundle', count(*) from commercial_bundle union all
  select 'ticket', count(*) from ticket union all
  select 'invoice', count(*) from invoice union all
  select 'stock_balance', count(*) from stock_balance order by 1;"
echo OPS_DONE
