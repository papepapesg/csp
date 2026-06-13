#!/usr/bin/env bash
#
# SOPHIX BSS — one-shot VPS deploy. Run as root from the repo root:
#
#   git clone -b claude/bss-docker-implementation-oj5JD https://github.com/papepapesg/csp.git sophix
#   cd sophix && bash deploy/vps-deploy.sh
#
# Brings up the full native stack (postgres, redis, app, nginx, queue, scheduler, mailpit),
# writes a production-ish .env, then seeds the `demo` profile so every surface opens populated.
# Idempotent: re-running rebuilds and re-seeds (migrate:fresh) — safe on a disposable box.
set -euo pipefail

# Always operate from the repo root, regardless of where the script is invoked from.
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PUBLIC_HOST="${PUBLIC_HOST:-207.180.209.83}"
# Base domain for the per-app subdomains. Defaults to the sslip.io wildcard for this IP (no DNS
# setup needed); override BASE_DOMAIN with a real domain you control for production.
BASE_DOMAIN="${BASE_DOMAIN:-${PUBLIC_HOST}.sslip.io}"

echo "==> SOPHIX deploy — apps under *.${BASE_DOMAIN}"

# 1. Docker + compose plugin.
if ! command -v docker >/dev/null 2>&1; then
    echo "==> Installing Docker..."
    curl -fsSL https://get.docker.com | sh
fi
docker compose version >/dev/null 2>&1 || { echo "docker compose plugin missing"; exit 1; }

# 2. .env (container-host networking is already the default in .env.example).
[ -f .env ] || cp .env.example .env
set_env() {
    local k="$1" v="$2"
    if grep -q "^${k}=" .env; then sed -i "s|^${k}=.*|${k}=${v}|" .env; else echo "${k}=${v}" >> .env; fi
}
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://app.${BASE_DOMAIN}"
# Multi-app portals: Caddy serves each app at slug.<base> with auto-TLS; one SSO session is
# shared across the subdomains (cookie domain = .<base>), and Sanctum trusts the wildcard.
set_env SOPHIX_APP_BASE_DOMAIN "${BASE_DOMAIN}"
set_env SESSION_DOMAIN ".${BASE_DOMAIN}"
set_env SESSION_SECURE_COOKIE true
set_env SANCTUM_STATEFUL_DOMAINS "*.${BASE_DOMAIN}"
set_env MAIL_MAILER smtp
# Containerized logging: stderr -> `docker compose logs`, and no root-vs-www-data
# contention on storage/logs/laravel.log across app/queue/scheduler/one-off containers.
set_env LOG_CHANNEL stderr
# Stable app key via env_file so sessions survive container restarts.
grep -q '^APP_KEY=base64:' .env || set_env APP_KEY "base64:$(openssl rand -base64 32)"

# 3. Build the image and bring up ONLY the datastores first.
echo "==> Stopping any running stack (keeping data volumes)..."
docker compose down --remove-orphans 2>/dev/null || true
echo "==> Building image and starting datastores (postgres, redis)..."
docker compose build
docker compose up -d postgres redis

# 4. Seed the demo profile in a ONE-OFF container — no app/queue/scheduler running, so
#    migrate:fresh is the only process touching the schema (avoids the boot-migrate race).
echo "==> Waiting for the database to be ready..."
until docker compose exec -T postgres pg_isready -U sophix -d sophix >/dev/null 2>&1; do sleep 2; done
echo "==> Seeding demo dataset (migrate:fresh + catalogs + admin + demo data)..."
# CONTAINER_ROLE=oneoff makes the entrypoint skip its own auto-migrate and just run the command.
docker compose run --rm -e CONTAINER_ROLE=oneoff app php artisan sophix:setup demo -n
# Drain the demo's queued workflow + outbox so projections/metrics are live.
docker compose run --rm -e CONTAINER_ROLE=oneoff app php artisan sophix:workflow:work --once || true
docker compose run --rm -e CONTAINER_ROLE=oneoff app php artisan sophix:outbox:dispatch || true
# The one-off seeds ran as root; hand storage back to the php-fpm user.
docker compose run --rm -e CONTAINER_ROLE=oneoff app chown -R www-data:www-data storage || true

# 5. Start the full application stack (app boot-migrate is now a no-op; schema is current).
echo "==> Starting the application stack..."
docker compose up -d
docker compose exec -T app php artisan config:cache || true

cat <<EOF

================ SOPHIX BSS is up ================
  Launcher      https://app.${BASE_DOMAIN}
  CRM           https://crm.${BASE_DOMAIN}
  Catalog       https://catalog.${BASE_DOMAIN}
  Operations    https://ops.${BASE_DOMAIN}
  (also: settings, reporting, templates, brand, studio-workflow/-rules/-dunning)
  API base      https://app.${BASE_DOMAIN}/api
  Mailpit       http://${PUBLIC_HOST}:8025
  Login         admin@sophix.local / password
  Operator      WIK (Kenya, KES)
  TLS           Let's Encrypt via Caddy (first request per host warms the cert)

  Postman       postman/collections/*.json  (19 bundles)
  Postman env   postman/SOPHIX-VPS.postman_environment.json

  Logs          docker compose logs -f app | caddy
  Reset         docker compose down -v && bash deploy/vps-deploy.sh
==================================================
EOF
