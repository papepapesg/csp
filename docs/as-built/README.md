# SOPHIX V3 — As-Built Design (code-true)

This folder is the **reverse-engineered, code-true design** of the platform: written *from*
the implementation (`Modules/*`, `app/Foundation`) and verified against the test suite.

It complements `docs/design-text/` (the **forward** design the code was originally built from).
Where the two differ, **as-built wins** — the code is now the source of truth, and several
behaviours were corrected after the forward design was written.

## Why this exists / how to use it
- **Onboarding path** for new developers (read in order, below).
- **Maintenance reference** that matches what the code actually does.
- The writing of these docs *is* the onboarding exercise: you learn a module by producing
  its as-built doc from the code and proving each claim against that module's tests.

## Reading order (onboarding)
1. **`00_SPINE.md`** — the ~6 cross-cutting Foundation patterns every module reuses. Read once;
   then every module is "just business logic on top." **Start here.**
2. **Golden-path trace** — open `Modules/Fulfillment/tests/Feature/FulfillmentJourneyTest.php`
   and follow one order end-to-end (order → subscription + install WO → KYC gate → activation).
   It exercises workflow, events, cross-module calls, approvals and billing in one story.
3. **Module deep-dives**, in dependency / data-flow order:

   | Layer | Modules |
   | --- | --- |
   | Substrate | `Workflow`, `Rules`, `Rbac`, `Catalog` (packages/tariffs/discounts/tax-compute) |
   | Core domain | `Ilm` → `Subscription` → `Billing` |
   | Operations | `WorkOrder`, `Workforce`, `Osr`, `Provisioning`, `Fulfillment` |
   | Edges | `Notification` (NOT-01 + ICN-01), `Ticketing`, `PaymentGateway`, `Reporting`, `ItOps` |

   `subscription.md` is the **gold-standard exemplar** — follow its shape for every module.

## How to write a module's as-built doc (the recipe)
Read these six code sources **in order**; each maps to a section of `_TEMPLATE.md`:

| Read | Yields |
| --- | --- |
| `database/migrations/` + `Models/` | data model + invariants |
| `Services/` | behaviours & business rules ("what it does") |
| `routes/api.php` + `Controllers/` | API surface |
| `Events/` + `Listeners/` + `EventServiceProvider` | integration (emits / consumes) |
| `Workflow/` handlers + flow seeders | processes |
| rules registrations + decision-table/config seeders | policy / config knobs |
| `tests/Feature/` | **the authoritative behaviour spec** — verify every claim against a test |

## Status

**All 17 module docs + the Foundation spine are complete at the deep bar** — every table has an
enum legend + ≥4 contrasting sample rows + a reading, and every module has ≥8 worked scenarios
naming the services + Foundation mechanics, each citing the test that proves it.

| Group | Docs |
| --- | --- |
| Spine + meta | `00_SPINE.md`, `_TEMPLATE.md`, `README.md` |
| Core domain | `subscription.md`, `catalog.md`, `billing.md`, `ilm.md` |
| Operations | `fulfillment.md`, `workorder.md`, `workforce.md`, `osr.md`, `provisioning.md` |
| Edges | `notification.md`, `ticketing.md`, `paymentgateway.md`, `reporting.md`, `itops.md` |
| Substrate engines | `workflow.md`, `rules.md`, `rbac.md` |
