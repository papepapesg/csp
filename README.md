# SOPHIX Core BSS

An all-Laravel implementation of the **SOPHIX V3** telecom Business Support
System, built wave-by-wave from the design corpus in [`docs/design`](docs/design).

- **Backend:** Laravel 12 modular monolith (`nwidart/laravel-modules`, one module per DD bundle)
- **Frontend:** Inertia + Vue 3 + Tailwind (Backoffice shell via Breeze)
- **Datastore:** PostgreSQL (authoritative) + Redis (cache/queue/session)
- **Auth/RBAC:** Sanctum + `spatie/laravel-permission` (DD_EM-CFG-03 catalog)
- **Infra (Laravel-native, swappable):** transactional outbox event bus, native
  workflow/operation engine, native rules engine — each can be swapped for
  Kafka / Camunda / Drools via config + compose profiles.

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the design-doc→Laravel
mapping and [`docs/IMPLEMENTATION_STATUS.md`](docs/IMPLEMENTATION_STATUS.md) for
wave-by-wave progress.

## Quick start (Docker)

```bash
cp .env.example .env
docker compose up -d --build        # app, web (nginx), postgres, redis, queue, scheduler, mailpit
# first boot runs migrations automatically; seed the RBAC catalog + admin user:
docker compose exec app php artisan db:seed --force
```

| Service        | URL                              |
| -------------- | -------------------------------- |
| Backoffice/API | http://localhost:8080            |
| Health check   | http://localhost:8080/api/health |
| Mailpit        | http://localhost:8025            |

Default admin: `admin@sophix.local` / `password`.

Opt-in reference services:

```bash
docker compose --profile kafka up -d      # real Kafka broker
docker compose --profile camunda up -d    # real Camunda engine
docker compose --profile drools up -d     # real Drools / KIE server
```

## Local development (without Docker)

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
# point DB_CONNECTION=sqlite for a quick start, or run a local postgres
php artisan migrate --seed
composer run dev          # serve + queue + vite + logs
```

## API conventions (DD_API-00)

All module APIs share the foundation conventions:

- **Correlation:** `X-Correlation-Id` generated/preserved on every request.
- **Operator scope:** `X-Operator-Code` (multi-operator: WIK, WUG, ...).
- **Idempotency:** `Idempotency-Key` on commands → replay or `409 IDEMPOTENCY_CONFLICT`.
- **Pagination:** `?page=0&size=50&sort=field,desc` → `{ items, page, size, totalElements, totalPages }`.
- **Errors:** `{ errorCode, message, correlationId, retryable, fieldErrors?, nextAction? }`.
- **Commands:** `{ status: "ACCEPTED", entityId?, operationId?, correlationId, nextAction }`.

## Postman (per bundle)

Each bundle gets its own collection. Regenerate after adding routes:

```bash
php artisan sophix:postman:generate
```

Import `postman/SOPHIX.postman_environment.json` and the per-bundle collections
in `postman/collections/`.

## Tests

```bash
php artisan test
```
