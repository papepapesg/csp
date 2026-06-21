# Provisioning — As-Built Design (Network Broadcast & Reconciliation)

> **Capability codes:** PROV-INT-01 · **Module path:** `Modules/Provisioning` (topology lives in
> `Modules/Catalog`) · **Source-of-truth tests:** `ProvisioningTest`, `ReconciliationTest`,
> `AdapterRoutingTest`

> **⭐ This is the vendor-binding module.** Every real NMS/OLT/CMTS/VoIP integration plugs in here
> behind one interface. A dev integrating a vendor must understand the chain in §2.1 cold.

## 1. Purpose & boundaries
- **Owns:** the **command ledger**, the **desired-vs-observed** reconciliation, the governed
  **force-sync**, and the **adapter registry** that routes each command to its vendor plane.
- **Does NOT own:** *what* to provision (Subscription decides), the premises/plant topology (Catalog
  `homepass` / `network_node`), or the vendor protocol (the adapter, at deployment).
- **Job:** push BSS-desired state to the network through a **swappable per-target adapter**, detect
  drift, and let NOC correct it — all audited.

## 2.1 ⭐ How a service binds to vendor technology — the path
Read top-to-bottom; this is the spine of the module.

```
Subscription (sub_123, package_ref=pkg_triple)         ← WHAT the customer bought
   └─ service (svc_inet, provisioner_key, network_profile_shape={speed,vlan})   ← the provisionable unit
        served at →  HomePass (hp_1)                    ← WHERE (the premises)
             via the network_node chain (walk parent_node_code, GPON example):
                ONT(code=ONT-77) → FAT(F12) → SPLITTER(S1H) → OLT(OLT-NRB-WTL-01) → HEADEND → NMS
                                                                     │
        ProvisioningService.broadcast(sub_123,'ACTIVATE',[ … ]) ────┘
             creates a provisioning_command:
                target_code = HUAWEI_NCE_GPON_KE        ← WHICH vendor plane (matches the head node/type)
                service_ref = svc_inet                  ← the service
                desired_state = {desiredStatus:ACTIVE, speedProfile:'100M', vlan:101}  ← the profile to push
             dispatch() → ProvisioningAdapterRegistry.forCommand(command):
                provisioning_adapter_config[target=HUAWEI_NCE_GPON_KE].adapter_class
                   → new HuaweiNceGponAdapter()         ← HOW (the vendor driver — the only code a vendor needs)
             adapter.dispatch(command):                 ← opens session, sends CLI/NETCONF/REST to the OLT
                identifies the subscriber by subscriber_key = "sub_123:svc_inet"
                returns ProvisioningResult.confirmed(external_ref='NMS-AB12…')
             recordDesiredState(command) → provisioning_desired_state  ← the baseline reconcile compares against
```
**In words:** *the **service** (what) + the **HomePass/node path** (where) pick a **target plane** (which
vendor system); the **adapter_config** binds that plane to a **ProvisioningAdapter** (how); the adapter
addresses the subscriber by **subscriber_key** and pushes the **desired_profile**.* Swap the
`adapter_class` and the same command drives a different vendor — **no platform change.**

## 📖 Scenarios — read these first

### Scenario A — activate internet on a GPON OLT (happy path)
1. `ActivateServiceHandler` (a subscription-activation workflow step) calls
   `ProvisioningService::broadcast('sub_123','ACTIVATE',[{target_code:'HUAWEI_NCE_GPON_KE',
   service_ref:'svc_inet', desired_state:{desiredStatus:'ACTIVE', speedProfile:'100M'}}])`.
2. `broadcast` writes a `provisioning_command` (`PENDING`) → `dispatch` resolves the Huawei adapter
   (via `adapter_config.adapter_class`), calls `adapter.dispatch` → the OLT confirms → command
   `CONFIRMED`, `external_ref` stored; `recordDesiredState` writes the `provisioning_desired_state`
   baseline. Handler returns `provisioned:true`.
- **Proven by:** `ProvisioningTest`, `AdapterRoutingTest` (a GPON command resolves the GPON adapter).

### Scenario B — the network drifted; NOC force-syncs it
1. `sophix:provisioning:reconcile` (hourly) polls each target via `adapter.fetchObserved` and finds
   `sub_2` desired `ACTIVE` but observed `SUSPENDED` → opens a `provisioning_reconciliation_item`. It
   does **not** auto-fix (R-PROV-08).
2. NOC `POST …/items/{item}/force-sync` → a `PENDING_APPROVAL` force-sync + an **EM-CFG-04** request
   (R-PROV-07). Execute-before-approve → **409**. A **different** approver approves (SoD), then
   `…/execute` re-broadcasts the desired state. Item resolves.
- **Proven by:** `ReconciliationTest::test_drift_opens_item_and_force_sync_resolves`.

### Scenario C — bind a real vendor (the deployment task)
1. Implement `class HuaweiNceGponAdapter implements ProvisioningAdapter` (the 3 methods in §4).
2. Seed `provisioning_adapter_config{operator, target_code:'HUAWEI_NCE_GPON_KE',
   adapter_class:HuaweiNceGponAdapter::class, execution_mode_default:'ASYNC_ACCEPTED'}`.
3. Done — every command for that target now drives the Huawei OLT. **No change to the platform.**

## 2. Data model — sample rows + how to read them

### `network_node` (Catalog — the plant topology) & `homepass`
**Enum legend — `network_node.type`:** `HEADEND` / `OLT` / `SPLITTER` / `FAT` / `FDT` / `ONT` (GPON
chain) · `DISTRIBUTION_NODE` / `AMPLIFIER` / `LINE_EXTENDER` (HFC) · `VOIPSWITCH` · `NMS` · `OTHER`.
Nodes form a **tree** via `parent_node_code` (walkable, no cycles).
**Sample rows:**
```json
{ "node_id":"nnode_olt1","code":"OLT-NRB-WTL-01","type":"OLT","parent_node_code":"HEADEND-NRB" }
{ "node_id":"nnode_ont1","code":"ONT-77","type":"ONT","parent_node_code":"FAT-F12" }
```
**Reading:** *ONT-77 is the customer's optical terminal; walking `parent_node_code` (ONT → FAT → …
→ OLT-NRB-WTL-01 → HEADEND) gives the physical path. The serving **OLT** maps to the GPON
**target plane**, so a command for this HomePass uses `target_code` = the Huawei GPON target.*
`homepass` (id, `status`, `technology`, `house_type_code`, `franchise_ref`) is the premises at the
leaf of that chain.

### `provisioning_target` (the vendor plane) & `provisioning_adapter_config` (the binding)
**Enum legend — `target.type`:** `GPON | HFC | VOIP | NMS`. **`adapter_config.execution_mode_default`:**
`SYNC_REQUIRED` (confirm inline) \| `ASYNC_ACCEPTED` (vendor accepts; the poll worker resolves later).
**Sample rows:**
```json
// target
{ "target_code":"HUAWEI_NCE_GPON_KE","type":"GPON","name":"Huawei NCE GPON (Kenya)","endpoint":"https://nce.wik:18002","active":true }
// adapter_config  (THE vendor binding)
{ "adapter_config_id":"pac_1","target_code":"HUAWEI_NCE_GPON_KE",
  "adapter_class":"Modules\\Provisioning\\Adapters\\HuaweiNceGponAdapter",
  "execution_mode_default":"ASYNC_ACCEPTED","retry_policy_json":{"baseSeconds":30,"factor":2},"status":"ACTIVE" }
```
**Reading:** *the target is the plane; the adapter_config row is the **swappable seam** — change
`adapter_class` to repoint to a different driver. `ASYNC_ACCEPTED` means the OLT only returns
"accepted" and `sophix:provisioning:poll-async` later calls `pollStatus` to confirm. The default
seed points at `StubProvisioningAdapter` so flows run with no hardware.*

### `provisioning_desired_state` (the reconcile baseline)
**Enum legend — `desired_status`:** `ACTIVE | SUSPENDED | RESTRICTED | TERMINATED | NOT_PRESENT`.
**Sample row:**
```json
{ "desired_state_id":"pds_9","subscription_id":"sub_123","homepass_id":"hp_1","service_ref":"svc_inet",
  "target_code":"HUAWEI_NCE_GPON_KE","subscriber_key":"sub_123:svc_inet","desired_status":"ACTIVE",
  "desired_profile":{"desiredStatus":"ACTIVE","speedProfile":"100M"} }
```
**Reading:** *"on the Huawei GPON plane, subscriber `sub_123:svc_inet` should be ACTIVE at 100M."
Reconciliation polls the adapter for this `subscriber_key` and flags a mismatch if observed ≠ this.
`NOT_PRESENT` means the subscriber should not exist on the plane (post-termination).*

### `provisioning_command` (the dispatch ledger)
**Enum legend — `action`:** `ACTIVATE | MODIFY | DEACTIVATE | SUSPEND | RESUME`.
**`status` lifecycle:** `PENDING → SENT → CONFIRMED` (sync ok) · `→ ACCEPTED` (async, await poll) `→
CONFIRMED` · `→ FAILED` (error) · `MISMATCH` (reconcile). (`FAILED_RETRYABLE/FINAL`, `TIMED_OUT`,
`SUPERSEDED` are the finer attempt states.)
**Sample row:**
```json
{ "command_id":"pcmd_5","broadcast_id":"bcast_1","subscription_id":"sub_123","service_ref":"svc_inet",
  "action":"ACTIVATE","target_code":"HUAWEI_NCE_GPON_KE","status":"ACCEPTED","external_ref":"NMS-AB12CD34EF",
  "desired_state":{"desiredStatus":"ACTIVE","speedProfile":"100M"},"execution_mode":"ASYNC_ACCEPTED","attempts":1 }
```
**Reading:** *attempt #1 was sent and the OLT **accepted** it (async) returning `NMS-AB12CD34EF`.
`sophix:provisioning:poll-async` will call the adapter's `pollStatus` to flip this to `CONFIRMED`
(or retry per `retry_policy_json`). `broadcast_id` groups all commands issued by the one `ACTIVATE`
action (e.g. internet + voice planes together).*

## 3. Services (with worked calls)
| Service | Responsibility |
| --- | --- |
| `ProvisioningService` | `broadcast()` / `dispatch()` / `pollAsyncCommands()` / `recordDesiredState()` |
| `ProvisioningAdapterRegistry` | `forCommand($cmd)` / `forTarget($op,$code)` → the vendor adapter (the seam) |
| `ReconciliationService` | `run()` / `requestForceSync()` / `approveForceSync()` (EM-CFG-04) / `executeForceSync()` |

**`ProvisioningService::broadcast('sub_123','ACTIVATE',[$spec])`** → per spec: create
`provisioning_command` (`PENDING`) → `dispatch()` → `AdapterRegistry::forCommand` resolves the
adapter → `adapter.dispatch()` → on confirmed set `CONFIRMED`+`external_ref` and
`recordDesiredState()`; on async set `ACCEPTED`; on error `FAILED`+`last_error`. Records a
`provisioning_command_attempt` (adapter, outcome, duration) each try.

## 4. ⭐ The vendor-adapter contract (`Contracts/ProvisioningAdapter`)
Implement these three methods to bind a vendor; nothing else changes.
```php
interface ProvisioningAdapter {
  // Push desired_state to the vendor (open session, CLI/NETCONF/REST). Return confirmed|accepted|failed.
  public function dispatch(ProvisioningCommand $command): ProvisioningResult;
  // Read back the subscriber's observed state for reconciliation (null = not present on the plane).
  public function fetchObserved(string $targetCode, string $subscriberKey, string $desiredStatus, array $desiredProfile = []): ?array;
  // Resolve an ASYNC_ACCEPTED command (null while still in progress).
  public function pollStatus(ProvisioningCommand $command): ?ProvisioningResult;
}
```
`StubProvisioningAdapter` is the default (logs + mirrors desired state; honours test hints
`forceFail` / `simulateAsync` / `simulateObservedStatus` / `simulateNotPresent`). A real driver
translates `command.desired_state` into the vendor's API.

## 5. Integration (events) — topic `provisioning.command`
- **Emits:** `ProvisioningCommand{Sent,Confirmed,Failed}`, `ProvisioningReconciliation{RunCompleted,
  ItemOpened}`, `ProvisioningForceSync{Requested,Approved,Completed,Cancelled}`.
- **Consumes:** `SyncProvisioningOnAccountStatusChanged` (ILM `CustomerAccountStatusChanged` with
  `affectsProvisioning` → re-broadcast the account's services at the mapped status).

## 6. Processes — with handler example
- **Workers:** `sophix:provisioning:poll-async` (resolve ACCEPTED via `pollStatus`, 5 min);
  `:reconcile` (desired vs observed, hourly).

**Worked handler — `ActivateServiceHandler` (topic `activate-service`):**
> Invoked at the subscription flow's "provision" node. **Reads vars:** `{businessKey=subscriptionId,
> packageRef}` + node `config` (`target`, `speedProfile`). **Does:** `broadcast('ACTIVATE', …)`.
> **Outputs:** `{provisioned: bool, provisioningRefs: [external_ref…]}` (the next gateway commits the
> subscription only when `provisioned=true`). **On fail:** `TaskResult::fail(retryable:true)`.

## 7. Policy & config
`provisioning_target` + `provisioning_adapter_config` (the connector binding — real plane = swap
`adapter_class`), `retry_policy_json`, EM-CFG-04 force-sync definition (seeded gated by default).
Network topology (`network_node`, `homepass`) is Catalog config.

## 8. Cross-module dependencies
- **Called by →** Subscription (`FulfillmentCallHandler`/`ActivateServiceHandler`), OSR
  (`ProvisionSwapHandler`), ILM (account-status sync).
- **Reads →** Catalog topology (`homepass` / `network_node`) + service `network_profile_shape` /
  `provisioner_key` to shape `desired_profile`.
- **Connector seam →** vendor adapters (Huawei NCE, FiberHome UNM2000, Casa CMTS, VoipSwitch,
  Verimatrix) plug in at deployment behind `ProvisioningAdapterRegistry`.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-PROV-08 | a mismatch never auto-fixes; NOC drives force-sync | `ReconciliationService::run` |
| R-PROV-07 | destructive force-sync gated through EM-CFG-04 (SoD + audit) | `requestForceSync`/`approveForceSync` |
| §10.2 | each command dispatched through ITS target's adapter | `ProvisioningAdapterRegistry::forCommand` |
| §7.2 | vendor-accepted (ASYNC) commands resolved by the poll worker | `pollAsyncCommands` + `pollStatus` |

## 10. Open items / deltas
- Force-sync EM-CFG-04 gating + the account-status→provisioning cascade were wired during hardening.
- A **typed `desired_state` contract** + an explicit **Headend/Hub routing model** (deriving
  `target_code` from the HomePass node chain rather than flow config) are the refinements to land
  when real vendor adapters arrive — today `target_code` comes from the flow's node `config`.
- All vendor adapters are **deployment connectors**, not platform code (only `StubProvisioningAdapter`
  ships).
