# SOPHIX BSS — VPS deployment & testing

A turnkey deploy of the full stack onto a disposable VPS, seeded with demo data so every
backoffice surface, API, and report opens populated.

## Stack (Docker, all-Laravel-native)

| Container | Role | Port |
|---|---|---|
| `web` (nginx) | HTTP front for the app | **80** (UI + `/api`) |
| `app` (php-fpm) | Laravel app; auto-migrates on boot | — |
| `queue` | `queue:work` (jobs) | — |
| `scheduler` | `schedule:work` (cron) | — |
| `postgres` | database | 5432 |
| `redis` | cache / sessions / queue | 6379 |
| `mailpit` | catches outbound email (notifications) | **8025** (UI) |

Opt-in reference services (not required — the platform is all-Laravel): `--profile kafka|camunda|drools`.

## Deploy (run on the VPS as root)

```bash
git clone -b claude/bss-docker-implementation-oj5JD https://github.com/papepapesg/csp.git sophix
cd sophix
bash deploy/vps-deploy.sh
```

The script installs Docker (if absent), writes a production `.env` (public host, stable APP_KEY,
SMTP→Mailpit), `docker compose up -d --build`, then runs `php artisan sophix:setup demo` to
migrate + seed catalogs + create the admin + load the demo dataset, and drains the workflow/outbox
so projections and reporting metrics are live.

Override the host/port with env vars: `PUBLIC_HOST=1.2.3.4 APP_PORT=8080 bash deploy/vps-deploy.sh`.

## Access

| What | URL / credential |
|---|---|
| Backoffice UI | `http://207.180.209.83` |
| API base | `http://207.180.209.83/api` |
| Mailpit (emails) | `http://207.180.209.83:8025` |
| Admin login | `admin@sophix.local` / `password` |
| Operator | `WIK` (Kenya, KES) |

## Postman

- Collections: `postman/collections/*.json` — **19 bundles** (one per module), auto-generated from
  the live route table via `php artisan sophix:postman:generate`.
- Environment: import `postman/SOPHIX-VPS.postman_environment.json` (its `base_url` already points at
  the VPS). To authenticate, `POST {{base_url}}/auth/token` with `admin_email`/`admin_password`,
  then set the returned token into the `access_token` variable (sent as `Authorization: Bearer`).

## Reset

```bash
docker compose down -v && bash deploy/vps-deploy.sh   # wipes volumes, redeploys + reseeds
```
