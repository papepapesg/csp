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

## 📚 Reading order (follow top to bottom)

A new dev should read these **in this exact sequence** — it goes spine → one end-to-end story →
the engines → config → the revenue core → execution → edges. Each line says *why it's here*.

**Phase 0 — Orient (the spine)**
1. **`00_SPINE.md`** — the ~7 cross-cutting Foundation patterns (tenancy, errors, outbox events,
   workflow, approvals, rules, idempotency/scope) + the worked **"Jane activates"** trace. Read once;
   then every module is "just business logic on top." **Start here.**
2. `_TEMPLATE.md` *(skim)* — the shape every module doc follows, so you know where to find things.

**Phase 1 — See it work end-to-end (the golden path)**
3. **`fulfillment.md`** *(read the Scenarios first)* alongside
   `Modules/Fulfillment/tests/Feature/FulfillmentJourneyTest.php` — one order from capture → live,
   threading workflow, events, approvals, Subscription, WorkOrder, ILM and Billing in a single story.
   *(You'll re-read it in full at step 12.)*

**Phase 2 — The engines (the toolbox everything composes)**
4. **`workflow.md`** — the process engine (definitions, external tasks, message catches, the worker).
5. **`rules.md`** — decision tables + the evaluate/fallback seam.
6. **`rbac.md`** — roles → permissions → scopes (the request guard).

**Phase 3 — The config substrate**
7. **`catalog.md`** — packages/versions/services, tariffs, **discounts**, **tax-compute**, wallet
   catalog, HomePass topology + network nodes. Everything else prices/sells against this.

**Phase 4 — The revenue core (read in this order — they build on each other)**
8. **`ilm.md`** — customer/account masters, KYC, the flag & sub-status catalogs, CVM, `routingContext`.
9. **`subscription.md`** — the lifecycle master + operation framework. **The gold-standard exemplar;**
   follow its shape when writing any new doc.
10. **`billing.md`** — charging, invoices, wallets, dunning, adjustments, tax invoices (the densest model).

**Phase 5 — Operations (field + network execution)**
11. **`workorder.md`** — WO lifecycle, dispatch, field audits.
12. **`workforce.md`** — contractor capacity (the slots WorkOrder books).
13. **`fulfillment.md`** *(now in full)* — the order-journey orchestrator.
14. **`osr.md`** — equipment instances, stock chain, procurement, swaps.
15. **`provisioning.md`** — ⭐ **the vendor-binding module**: service → HomePass/node path → target
    plane → adapter → the network. Read §2.1 (the path) + §4 (the adapter contract) closely.

**Phase 6 — The edges**
16. **`notification.md`** — customer (NOT-01) + staff (ICN-01) pipelines.
17. **`ticketing.md`** — tickets + SLA + ASR.
18. **`paymentgateway.md`** — inbound payment-rail callbacks.
19. **`reporting.md`** — the event-sourced metrics mart.
20. **`itops.md`** — NOC console: heartbeats, worker control, trace.

> **Each module doc reads the same way:** Purpose → 📖 Scenarios (start here) → Data model (sample
> rows + readings) → Services → API → Events → Processes → Config → Dependencies → Invariants → Deltas.
> Always cross-check a claim against the **test** it cites.

> **Sample-row convention:** every sample row lists **all domain columns** (nullables shown as `null`)
> — never a partial subset — so a reading can never name a column that isn't present. The Laravel
> surrogate `id` and `created_at`/`updated_at` timestamps are omitted by convention.

> **Teaching style (how these are written):** lead each scenario with a **plain-English story** a
> newcomer gets in one read, then the technical detail. **Show, don't just tell** — back a point with a
> sample row or a **worked example with real numbers** (little tables for any calculation/cascade).
> **Draw the flow** with **mermaid** — `stateDiagram-v2` for a status lifecycle, `sequenceDiagram` for
> an approval / cross-module flow, `flowchart` for a branchy decision. (Mermaid renders as a diagram on
> GitHub and in most Markdown viewers.) The **worked exemplars** are `catalog.md` scenario 1 (package
> launch + approval) and its `tax_group`/`tax_rule` section (the tax cascade with numbers).
>
> **Data-model readings = read the rows, don't lecture.** After the sample rows, translate **each row
> into a plain-English sentence** ("**dasg_1** = customer cust_1 gets 25% off, granted by hand, live now")
> in a little table — *illustrate via the data*, not a dense paragraph re-defining columns. A short
> "the columns that did the work" list can follow. Exemplar: `catalog.md` `discount_assignment`.

> **DOCX output:** `node docs/as-built/build-docx.sh.js <file.md …>` renders every mermaid block to a PNG
> and produces `docs/as-built/docx/<file>.docx` with the diagrams embedded as images (needs `pandoc` +
> `@mermaid-js/mermaid-cli`). Run with no args to build all docs.

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
