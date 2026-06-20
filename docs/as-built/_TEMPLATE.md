# <Module> — As-Built Design

> **Capability codes:** <e.g. SUB-LM-01, SUB-WF-*> · **Module path:** `Modules/<Module>`
> **Source-of-truth tests:** `Modules/<Module>/tests/Feature/*`

## 1. Purpose & boundaries
- **Owns:** <the authoritative state/decisions this module is the single writer of>
- **Does NOT own:** <what it delegates to other modules — name them>
- One-paragraph summary of the module's job.

## 2. Data model
| Table | Purpose | Key invariants |
| --- | --- | --- |
| `<table>` | <…> | <unique keys, status enums, append-only, FKs> |

## 3. Services & responsibilities
| Service | Responsibility | Key methods |
| --- | --- | --- |
| `<Service>` | <…> | `method()` — <what it guarantees> |

## 4. API surface
| Method + path | Permission | Idempotent? | Scope? | Controller→service |
| --- | --- | --- | --- | --- |
| `POST /api/<…>` | `<perm>` | yes/no | `<scope>` | `<Controller@action>` → `<Service::m>` |

## 5. Integration (events)
- **Emits:** `<EventType>` (topic `<topic>`) — <when / payload keys / who consumes>
- **Consumes:** `<EventType>` via `<Listener>` — <what it does>

## 6. Processes (workflow)
- **Flows:** `<process key>` — <when triggered, terminal states>
- **Handlers (topics):** `<topic>` → `<Handler>` — <what the step does, outputs>
- Trigger entrypoint: `<Service::method>`; reconciliation: `<…>`

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
