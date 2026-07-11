# SOPHIX module operations contract

Operational support is part of a module's public contract. A capability is not
complete until IT Operations can inspect it, validate its catalogs/configuration,
identify stuck work, reconcile its ledger and perform approved recovery without
editing the database.

## Command categories

| Suffix | Contract |
| --- | --- |
| `ops-status` | Read-only queue/health summary |
| `show` | Read-only aggregate plus history |
| `validate` / `check` | Read-only invariant and catalog validation |
| `explain` | Read-only resolution trace for policy/rating/routing |
| `reconcile` | Compare authoritative views; dry-run by default |
| `retry` / `sweep` | Idempotent routine maintenance |
| `fix` / `repair` | Explicit, audited correction through application services |

All new status checks support `--operator`, `--json` and `--fail-on-alert`.
Human output uses tables; JSON output is stable enough for monitoring agents.
Without `--fail-on-alert`, a read-only check returns success after reporting.
With it, warning/critical non-zero metrics return a failing process status.

## Safety rules

1. `status`, `show`, `check`, `validate` and `explain` never mutate state.
2. Reconciliation is read-only unless an explicit execution option is supplied.
3. Repair commands call the same application service as the API; they never issue
   ad-hoc table updates.
4. Every repair records operator, actor, reason, correlation id, before/after state
   and result.
5. Routine commands are idempotent, bounded and safe with overlapping protection.
6. Secrets, tokens and raw personal data are not printed.
7. Module providers own module schedules so module activation controls workers.

## Current module-owned status commands

Run `php artisan list sophix` for the full catalog. Examples include:

```bash
php artisan sophix:billing-adjustments:ops-status --operator=WIK
php artisan sophix:billing-intent:ops-status --json
php artisan sophix:catalog-network:ops-status --fail-on-alert
php artisan sophix:catalog-rating:ops-status
php artisan sophix:catalog-tax:ops-status
php artisan sophix:icn:ops-status
php artisan sophix:procurement:ops-status
php artisan sophix:osr-swap:ops-status
php artisan sophix:field-audit:ops-status
```

Existing core commands cover Billing, Dunning, Catalog launch, ILM/KYC,
Fulfillment, Notification, OSR, Payment Gateway, Provisioning, RBAC, Reporting,
Rules, Subscription, Ticketing, WorkOrder, Workflow and Workforce.

## Module Definition of Done

A new physical module must ship with:

- a module-owned operational status check;
- aggregate inspection for operationally important entities;
- catalog/config validation when the module owns reference data;
- ledger reconciliation when it owns financial or inventory facts;
- bounded scheduled maintenance where lifecycle work is time-driven;
- tests proving command registration and read-only behavior;
- an incident runbook identifying safe recovery commands.
