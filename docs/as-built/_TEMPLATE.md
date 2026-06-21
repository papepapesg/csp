# <Module> — As-Built Design

> **Capability codes:** <e.g. SUB-LM-01, SUB-WF-*> · **Module path:** `Modules/<Module>`
> **Source-of-truth tests:** `Modules/<Module>/tests/Feature/*`

## 1. Purpose & boundaries
- **Owns:** <the authoritative state/decisions this module is the single writer of>
- **Does NOT own:** <what it delegates to other modules — name them>
- One-paragraph summary of the module's job.

## 📖 Scenarios (≥ 8) — read these first
> **At least 8** concrete walk-throughs covering happy paths, every enum branch, failures, and
> cross-module reactions. Each must name **which services** are involved and **how the Foundation
> works** (outbox/events, workflow, approvals, idempotency, rules, scope) + the **proving test**.
> Cover: create/activate · each state transition · each `<enum>` value's branch · an approval-gated
> path · an async/worker path · a failure+retry · a cross-module reaction (event → other module) ·
> an idempotent retry.

### Scenario 1 — <happy path>
Services: `<A> → <B>`. Foundation: <outbox/workflow/…>. 1) `POST /api/<…>` `{…}` → 2) <service steps>
→ 3) `<table>` `<status>`: A→B → 4) emits `<Event>` → `<Listener>` reacts. *Proven by `<Test>`.*

### Scenario 2 … 8 — <enum branches, approval, async worker, failure+retry, cross-module, idempotent>
<same shape; one per branch/behaviour>

## 2. Data model — **≥ 4 sample rows + readings per table**
> **Every** table (ledger, config, catalog) gets an **enum legend** (what each value *does*) and
> **at least 4 sample rows** chosen to contrast the enum states, then a plain-English **reading**.

### `<table>`
**Enum legend — `<status_col>`:** `A` = <behaviour>, `B` = <behaviour>, `C` (transient) = <…>.
```json
{ "<id>":"r1", "<status_col>":"A", "<enum2>":"X" }   // typical
{ "<id>":"r2", "<status_col>":"B", "<enum2>":"Y" }   // a different state
{ "<id>":"r3", "<status_col>":"C", "<enum2>":"X" }   // the transient/edge
{ "<id>":"r4", "<status_col>":"A", "<enum2>":"Z" }   // another enum2 branch
```
**Reading:** contrast the rows — *r1 is <…>; r2 differs because `<status_col>=B` ⇒ <…>; r3 is
transient ⇒ <…>; r4 shows `<enum2>=Z` ⇒ <…>.* Spell out what each enum value triggers.

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
