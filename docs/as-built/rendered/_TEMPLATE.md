> 📱 **Rendered view** — diagrams below are images so they show in the GitHub app. Editable source (with mermaid): [`../_TEMPLATE.md`](../_TEMPLATE.md).

# <Module> — As-Built Design

> **Capability codes:** <e.g. SUB-LM-01, SUB-WF-*> · **Module path:** `Modules/<Module>`
> **Source-of-truth tests:** `Modules/<Module>/tests/Feature/*`

## 1. Purpose & boundaries
- **Owns:** <the authoritative state/decisions this module is the single writer of>
- **Does NOT own:** <what it delegates to other modules — name them>
- One-paragraph summary of the module's job.

## 📖 Scenarios (≥ 8) — read these first
> **At least 8** concrete walk-throughs covering happy paths, every enum branch, failures, and
> cross-module reactions. **Write them so a brand-new dev gets it in one read:**
> - **Simple English first.** Open each scenario with a 2–4 line *plain-English story* ("A product
>   manager builds a package… it needs a second person to approve…"). THEN the technical detail
>   (services, tables, events). Don't lead with a wall of `Class::method` + enum jargon.
> - **Show, don't just tell — use a sample.** Include a small JSON row or a worked example that makes
>   the point concrete (real ids, real values, real numbers). If there's any calculation, sequencing,
>   or cascade, show the **numbers** in a little table (see the tax worked example in `catalog.md`).
> - **Draw it when there's a flow.** Add a **mermaid** diagram for: a status **lifecycle**
>   (`stateDiagram-v2`), an **approval / cross-module** flow (`sequenceDiagram`), or a branchy decision
>   (`flowchart`). A picture beats a dense paragraph for journeys, state machines and approvals.
> - Still name **which services** + **how the Foundation works** (outbox/events, workflow, approvals,
>   idempotency, rules, scope) + the **proving test** — right after the plain-English part.
> Cover: create/activate · each state transition · each `<enum>` value's branch · an approval-gated
> path · an async/worker path · a failure+retry · a cross-module reaction (event → other module) ·
> an idempotent retry. (`catalog.md` scenario 1 + the tax data-model section are the worked exemplars.)

### Scenario 1 — <happy path>
**The story:** <2–4 lines of plain English a newcomer understands with no codebase knowledge.>
**Who does what:** 1) `<Service>` does <…> → `<table>` `<status>` A→B → 2) emits `<Event>` →
`<Listener>` reacts. **Sample:** `{ "<id>":"…", "status":"B" }`. *Proven by `<Test>`.*

![diagram](img/_TEMPLATE_1.png)


### Scenario 2 … 8 — <enum branches, approval, async worker, failure+retry, cross-module, idempotent>
<same shape; lead with the plain-English story, add a sample, and a mermaid diagram wherever a
lifecycle/approval/cross-module flow is involved.>

## 2. Data model — **≥ 4 complete sample rows + readings per table**
> **Every** table (ledger, config, catalog) gets: the **column list**, an **enum legend** (what each
> value *does*), and **≥ 4 sample rows** chosen to contrast the enum states, then a plain-English
> **reading**. Where a table drives a **calculation, sequence or cascade** (tax, rating, dunning steps,
> proration), add a **worked example with real numbers** (a little table) and a **mermaid** diagram —
> the sample should *tell the story*, not just sit there (see `catalog.md` `tax_group`/`tax_rule`).
> **Completeness rule:** each sample row shows **every domain column** (nullables included, as `null`)
> — never a partial subset, so a reading can never reference a column that isn't there. The Laravel
> surrogate `id` and the `created_at`/`updated_at` audit timestamps are omitted **by convention**
> (state it once; no reading depends on them).

### `<table>`
**Enum legend — `<status_col>`:** `A` = <behaviour>, `B` = <behaviour>, `C` (transient) = <…>.
```json
{ "<id>":"r1", "<status_col>":"A", "<enum2>":"X" }   // typical
{ "<id>":"r2", "<status_col>":"B", "<enum2>":"Y" }   // a different state
{ "<id>":"r3", "<status_col>":"C", "<enum2>":"X" }   // the transient/edge
{ "<id>":"r4", "<status_col>":"A", "<enum2>":"Z" }   // another enum2 branch
```
**Read each row as a sentence — *this data means this*** (the reading must ILLUSTRATE VIA THE DATA, not
restate column definitions; point at a row and say what it means in plain English — "if you see this
row, it means SO"). Use a little table:

| Row | What it means in plain English |
|-----|--------------------------------|
| **r1** | <plain-English meaning: who/what, in what state, what happens — naming the columns that make it so> |
| **r2** | <…differs because `<status_col>=B`, so…> |
| **r3** | <the transient/edge case in plain words> |
| **r4** | <the `<enum2>=Z` branch in plain words> |

> Then, if useful, **one short list** of *the columns that did the work* (which column decides who it
> applies to / its state / how it combines) — but lead with the per-row sentences, not a prose paragraph.
> Worked exemplar: `catalog.md` `discount_assignment` ("dasg_1 = customer cust_1 gets 25% off, live now…").
> **Never** name a column in a reading that isn't in the rows (the all-columns rule guarantees it's there).

## 3. Services & responsibilities
| Service | Responsibility | Key methods |
| --- | --- | --- |
| `<Service>` | <…> | `method()` — <what it guarantees> |

**Worked call — `<Service::method>`:**
```php
$svc->method($arg, ['k'=>'v']);   // → <what it does step by step> → returns <X>
```

## 4. API surface (+ controller examples)
| Method + path | Permission | Idempotent? | Scope? | Controller→service |
| --- | --- | --- | --- | --- |
| `POST /api/<…>` | `<perm>` | yes/no | `<scope>` | `<Controller@action>` → `<Service::m>` |

**Worked request/response — `<Controller@action>`:**
```http
POST /api/<…>            →   201 { "<id>":"…", "status":"…" }
{ "field":"value" }
```
<one line: what the controller validates and which service it calls>

## 5. Integration (events)
- **Emits:** `<EventType>` (topic `<topic>`) — <when / payload keys / who consumes>
- **Consumes:** `<EventType>` via `<Listener>` — <what it does>

## 6. Processes (workflow) — with handler examples
- **Flows:** `<process key>` — <when triggered, terminal states>
- **Handlers (topics):** `<topic>` → `<Handler>` — <what the step does, outputs>
- Trigger entrypoint: `<Service::method>`; reconciliation: `<…>`

**Worked handler — `<Handler>` (topic `<topic>`):**
> Invoked by the worker when the flow reaches the `<node>` node.
> **Reads vars:** `{subscriptionId, …}` · **Does:** <…> · **Outputs:** `{<key>:<val>}` (drives the
> next gateway) · **On fail:** `TaskResult::fail(...)` (retryable? <y/n>).

## 7. Policy & config (no-code knobs)
- **Rules packages:** `<rules.xxx>` — <inputs → outputs, fallback>
- **Catalogs / config tables:** `<table>` — <what an operator tunes>

## 8. Cross-module dependencies
- Calls → `<Module::Service>` for `<purpose>`
- Called by → `<Module>` via `<event/service>`

## 9. Invariants & rules
| Rule | Statement | Enforced in | Test |
| --- | --- | --- | --- |
| `R-<…>` | <…> | `<class::method>` | `<test name>` |

## 10. Open items / deltas from `docs/design-text/`
- <where the code intentionally differs from the forward design, and why>
- <dead/advisory columns, optional backlog>
