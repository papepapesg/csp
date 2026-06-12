# SOPHIX BSS — Session Handoff

Pick-up note for continuing in a fresh session (e.g. after reopening the environment
with full network egress). All work is on branch `claude/bss-docker-implementation-oj5JD`.

## Where we are
- **SOPHIX V3 Core BSS is fully implemented** against the design docs (`docs/design-text/`).
  The traceability matrix `docs/EXPLORATION_MATRIX.md` is **50/50 ✅** — every numbered feature,
  all cross-cutting platform pieces, all channels, and the DD_CROSS-00 §12 E2E pack.
- **447 tests green** (`php artisan test`; Postgres on :5433 via `bash scripts/dev-postgres.sh`).
- Backoffice: 12 surfaces on a shared visual kit (`resources/js/Components/Bss/*`), role-aware
  sidebar shell, operator theming (`--op-primary`, zero hardcoded brand colour), full i18n,
  federated global search, resilient dashboard. See the matrix Tour log for the full history.

## The pending task — deploy to the test VPS
Deploy the whole stack + foundations to a **disposable VPS** and run a live tour.
- Host: `207.180.209.83`, user `root` (password supplied by the user in-session — NOT stored here).
- Everything needed is committed:
  - `deploy/vps-deploy.sh` — turnkey: installs Docker, writes prod `.env` (public host, stable
    APP_KEY, SMTP→Mailpit), `docker compose up -d --build`, then `php artisan sophix:setup demo`
    (migrate:fresh + catalogs + admin + demo dataset) + drains workflow/outbox.
  - `deploy/DEPLOY.md` — stack, access, Postman usage, reset.
  - `postman/collections/*.json` (19 bundles, regenerate with `php artisan sophix:postman:generate`)
    + `postman/SOPHIX-VPS.postman_environment.json` (base_url → the VPS).

## Why a new session was needed
This environment's network policy was a **domain allowlist**: outbound to the VPS (and any
arbitrary host/tunnel) returned 403 in ~40ms, and SSH:22 timed out with no ssh client installable.
Reopen the environment with **full/open egress** (and port 22 if possible) so the deploy can be
driven server-side. Docs: https://code.claude.com/docs/en/claude-code-on-the-web

## Next-session playbook
1. **Verify connectivity first** (report before touching anything): `nc -zv 207.180.209.83 22`,
   `curl -sS -o /dev/null -w '%{http_code}' http://207.180.209.83/`. Confirm what's reachable.
2. **Deploy**:
   - If SSH works: `ssh root@207.180.209.83` then run the clone + `bash deploy/vps-deploy.sh`.
   - Else if only HTTP works: have the user start a token-guarded command-agent on the VPS and
     drive `deploy/vps-deploy.sh` over it.
3. **Verify the seed**: `curl http://207.180.209.83/api/health`; log in to the UI; check the
   dashboard funnel + global search are populated.
4. **Share access**: UI `http://207.180.209.83`, API `/api`, Mailpit `:8025`,
   login `admin@sophix.local` / `password`, operator `WIK`.
5. **Live tour** of the surfaces with the user.

## House rules (carried over)
- Stick to the design documents; don't invent. Build whole, test, commit+push each increment
  (workspace can revert to old snapshots — only pushed commits survive).
- Don't create PRs unless asked. Don't put model identifiers in commits/code/artifacts.
- Operator-variable decisions live in config/rules tables, not hardcoded `if`.
