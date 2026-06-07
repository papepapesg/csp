#!/usr/bin/env bash
# Local PostgreSQL for running the test suite in a sandbox (NOT for production —
# production uses the Docker `postgres` service). Idempotent: inits the cluster
# on first run, then (re)starts it and ensures the sophix + sophix_test databases.
set -euo pipefail
PGBIN="$(ls -d /usr/lib/postgresql/*/bin | head -1)"
PGDATA=/tmp/pgdata
PORT="${DB_PORT:-5433}"

id pgrunner >/dev/null 2>&1 || useradd -m pgrunner
mkdir -p "$PGDATA" /tmp/pgsock && chown -R pgrunner:pgrunner "$PGDATA" /tmp/pgsock

if [ ! -f "$PGDATA/PG_VERSION" ]; then
    runuser -u pgrunner -- "$PGBIN/initdb" -U sophix -A trust -D "$PGDATA" >/dev/null
fi
runuser -u pgrunner -- "$PGBIN/pg_ctl" -D "$PGDATA" \
    -o "-p $PORT -k /tmp/pgsock -c listen_addresses=127.0.0.1" -l /tmp/pglog.log start || true
sleep 2
"$PGBIN/createdb" -U sophix -p "$PORT" -h 127.0.0.1 sophix 2>/dev/null || true
"$PGBIN/createdb" -U sophix -p "$PORT" -h 127.0.0.1 sophix_test 2>/dev/null || true
psql -U sophix -p "$PORT" -h 127.0.0.1 -d postgres -c "ALTER USER sophix WITH PASSWORD 'sophix';" >/dev/null 2>&1 || true
"$PGBIN/pg_isready" -p "$PORT" -h 127.0.0.1
