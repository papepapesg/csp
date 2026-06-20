# Provisioning — As-Built Design (Network Broadcast & Reconciliation)

> **Capability codes:** PROV-INT-01 (broadcast, reconciliation, force-sync) · **Module path:**
> `Modules/Provisioning` · **Source-of-truth tests:** `Modules/Provisioning/tests/Feature/*`
> (Provisioning, Reconciliation, AdapterRouting)

## 1. Purpose & boundaries
- **Owns:** the **provisioning command ledger**, the **desired vs observed** reconciliation, and the
  governed **force-sync**. The cross-cutting framework is the platform; the vendor planes are connectors.
- **Does NOT own:** the BSS intent (Subscription decides *what* to provision); the equipment (OSR). It
  pushes desired state to the network and detects drift.
- **Job:** reliable, audited command dispatch behind a **swappable per-target adapter**, plus drift
  detection and NOC-driven correction.

## 2. Data model (selected)
| Table | Purpose | Invariants |
| --- | --- | --- |
| `provisioning_target` / `provisioning_adapter_config` | NMS/OLT/CMTS/VoIP planes + their adapter binding | adapter = `adapter_class` config (the connector seam) |
| `provisioning_command` (+ `_attempt`) | the dispatch ledger: action, target, desired_state, status, retries | idempotent; async ACCEPTED → resolved by poll |
| `provisioning_desired_state` | BSS-desired state per (sub, service, target) | the reconciliation baseline |
| `provisioning_observed_state` | last polled network state | |
| `provisioning_reconciliation_run` / `_item` | drift detection + open mismatches | no auto-fix (R-PROV-08) |
| `provisioning_force_sync_request` | NOC manual correction | **EM-CFG-04** gated (`approval_request_id`) |

## 3. Services
| Service | Responsibility |
| --- | --- |
| `ProvisioningService` | `broadcast()` (issue commands), `dispatch()` (per-attempt via the target's adapter; sync/async), `pollAsyncCommands()`, `recordDesiredState()` |
| `ProvisioningAdapterRegistry` | resolve the vendor adapter for a target/command (the connector boundary) |
| `ReconciliationService` | `run()` (desired vs observed → open items), `requestForceSync()` / `approveForceSync()` (**EM-CFG-04**, SoD) / `executeForceSync()` (re-broadcast desired) |

## 4. API surface
`/api/provisioning/commands`, `…/reconciliation/run`, `…/reconciliation/items/{item}/force-sync`,
`…/force-sync-requests/{req}/{approve,execute,cancel}` under `permission:provisioning.{view,manage}`.

## 5. Integration (events) — topic `provisioning.command`
- **Emits:** `ProvisioningCommand{Sent,Confirmed,Failed}`, `ProvisioningReconciliation{RunCompleted,
  ItemOpened}`, `ProvisioningForceSync{Requested,Approved,Completed,Cancelled}`.
- **Consumes:** `SyncProvisioningOnAccountStatusChanged` (ILM `CustomerAccountStatusChanged` with
  `affectsProvisioning` → re-broadcast the account's services at the mapped status).

## 6. Processes
- **Handler:** `ActivateServiceHandler` (workflow step that broadcasts activation).
- **Workers:** `sophix:provisioning:poll-async` (resolve ACCEPTED commands, 5 min),
  `:reconcile` (desired vs observed, hourly).

## 7. Policy & config
`provisioning_target` + `provisioning_adapter_config` (the connector binding — real plane = swap
`adapter_class`), retry policy, EM-CFG-04 force-sync definition (seeded gated by default).

## 8. Cross-module dependencies
- **Called by →** Subscription (`FulfillmentCallHandler::broadcast`), OSR (`ProvisionSwapHandler`),
  ILM (account-status sync).
- **Connector seam →** vendor adapters (Huawei NCE, FiberHome, Casa CMTS, VoipSwitch, Verimatrix) plug
  in at deployment behind `ProvisioningAdapterRegistry`.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-PROV-08 | a mismatch never auto-fixes; NOC drives force-sync | `ReconciliationService::run` |
| R-PROV-07 | destructive force-sync gated through EM-CFG-04 (SoD + audit) | `requestForceSync`/`approveForceSync` |
| §10.2 | each command dispatched through ITS target's adapter | `ProvisioningAdapterRegistry` |
| §7.2 | vendor-accepted commands resolved async by the poll worker | `pollAsyncCommands` |

## 10. Open items / deltas
- Force-sync EM-CFG-04 gating and the account-status→provisioning cascade were wired during hardening —
  previously a bare approve flip / an unconsumed event. Fixed.
- A typed `desired_state` contract + Headend/Hub routing model are refinements for when real vendor
  adapters land (the adapters themselves are deployment connectors, not platform code).
