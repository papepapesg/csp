# Provisioning — As-Built Design (Network Broadcast & Reconciliation)

> **Capability codes:** PROV-INT-01 · **Module path:** `Modules/Provisioning` (topology in
> `Modules/Catalog`) · **Tests:** `ProvisioningTest`, `ReconciliationTest`, `AdapterRoutingTest`

> **⭐ The vendor-binding module.** Every real NMS/OLT/CMTS/VoIP integration plugs in here behind one
> interface. Understand §2.1 (the path) and §4 (the adapter contract) cold.

## 1. Purpose & boundaries
- **Owns:** the **command ledger**, **desired-vs-observed reconciliation**, governed **force-sync**,
  and the **adapter registry** routing each command to its vendor plane.
- **Does NOT own:** *what* to provision (Subscription), the premises/plant topology (Catalog
  `homepass`/`network_node`), the vendor protocol (the adapter, at deployment).
- **Job:** push BSS-desired state to the network via a swappable per-target adapter, detect drift,
  let NOC correct it — all audited.

## 2.1 ⭐ How a service binds to vendor technology — the path
```
Subscription (sub_123, package_ref=pkg_triple)                 ← WHAT the customer bought
 └ service (svc_inet, provisioner_key, network_profile_shape={speed,vlan})   ← the provisionable unit
     served at HomePass (hp_1), at the leaf of the node chain (GPON):
        ONT(ONT-77) → FAT(F12) → SPLITTER(S1H) → OLT(OLT-NRB-WTL-01) → HEADEND → NMS
   ProvisioningService.broadcast('sub_123','ACTIVATE',[{ target_code, service_ref, desired_state }])
     → provisioning_command{ target_code=HUAWEI_NCE_GPON_KE,    ← WHICH vendor plane (matches the serving OLT)
                             service_ref=svc_inet,
                             desired_state={desiredStatus:ACTIVE, speedProfile:'100M', vlan:101} }
     → dispatch() → AdapterRegistry.forCommand(): adapter_config[target].adapter_class
                       → new HuaweiNceGponAdapter()             ← HOW (the only code a vendor needs)
     → adapter.dispatch(): session to the OLT, subscriber_key="sub_123:svc_inet", returns external_ref
     → recordDesiredState() → provisioning_desired_state        ← the reconcile baseline
```
**In words:** the **service** (what) + the **HomePass/node path** (where) pick a **target plane** (which
vendor system); **adapter_config** binds that plane to a **ProvisioningAdapter** (how); the adapter
addresses the subscriber by **subscriber_key** and pushes **desired_profile**. Swap `adapter_class` →
the same command drives a different vendor, **no platform change**.

## 📖 Scenarios (service + Foundation involvement)

### 1. Activate internet on a sync GPON OLT
`ActivateServiceHandler` (a Subscription workflow step, **Foundation/Workflow**) →
`ProvisioningService::broadcast('sub_123','ACTIVATE',[…])`. broadcast writes a `provisioning_command`
(`PENDING`) and `dispatch()` resolves the Huawei adapter via `ProvisioningAdapterRegistry::forCommand`
→ `adapter.dispatch` confirms inline → command `CONFIRMED`, `external_ref` stored, a
`provisioning_command_attempt` (`SUCCESS`) logged, `recordDesiredState()` writes the baseline. Emits
`ProvisioningCommandConfirmed` to the **outbox**. Handler returns `provisioned:true`; the subscription
flow commits ACTIVE. *(AdapterRoutingTest: a GPON command resolves the GPON adapter.)*

### 2. Activate on an async OLT (accept → poll → confirm)
Same broadcast, but `adapter_config.execution_mode_default=ASYNC_ACCEPTED`. `adapter.dispatch` returns
**accepted** → command `ACCEPTED` (`external_ref` set), and the flow's `ActivateServiceHandler` sees
not-yet-confirmed. The scheduled worker `sophix:provisioning:poll-async` (a **Foundation/Console**
scheduled command, every 5 min) calls `adapter.pollStatus` → `CONFIRMED`. The parked workflow advances
on the next tick. *Shows: async vendors + the poll worker resolving terminal state.*

### 3. Speed change (MODIFY) mid-cycle
A subscription upgrade flow broadcasts `action:MODIFY` with `desired_state.speedProfile:'200M'`.
`dispatch` pushes it; `recordDesiredState` **updates** the existing `provisioning_desired_state`
(`updateOrCreate` on subscriber_key) so the new profile is the reconcile baseline. *Shows: the same
seam handles changes, and desired-state is the single baseline.*

### 4. Account suspended in ILM → the network follows (cross-module via the outbox)
ILM `AccountService` emits `CustomerAccountStatusChanged{affectsProvisioning:true,status:INACTIVE}`
to the **outbox**. `sophix:outbox:dispatch` fires `OutboxEventPublished` →
`SyncProvisioningOnAccountStatusChanged` looks up the account's subscriptions and **re-broadcasts**
each provisioned target at `SUSPENDED` (action `ACCOUNT_STATUS_SYNC`). *Shows: a domain event in one
module driving provisioning, the whole Foundation event backbone (R-ILM-S-3).* *(ReconciliationTest::
test_account_status_change_syncs_provisioning.)*

### 5. Terminate → desired NOT_PRESENT
Termination broadcasts `action:DEACTIVATE`, `desired_state.desiredStatus:NOT_PRESENT`.
`recordDesiredState` sets `desired_status=NOT_PRESENT` — so reconciliation now expects the subscriber
to **not exist** on the plane, and a still-present subscriber is flagged as drift. *Shows: enum-driven
behaviour (NOT_PRESENT inverts the reconcile check).*

### 6. Reconcile — clean run (no drift)
`sophix:provisioning:reconcile` (hourly) → `ReconciliationService::run()` loads each
`provisioning_desired_state`, calls `adapter.fetchObserved(target, subscriber_key, …)`, upserts
`provisioning_observed_state`, and compares. All match → run `COMPLETED`, `mismatch_count=0`, any
prior OPEN items auto-resolve (`MATCHED_SINCE`). Emits `ProvisioningReconciliationRunCompleted`.
*(ReconciliationTest::test_clean_reconciliation_has_no_mismatch.)*

### 7. Reconcile drift → force-sync (EM-CFG-04 + SoD)
A target reports `SUSPENDED` while desired is `ACTIVE` → `run()` opens a
`provisioning_reconciliation_item` (`OPEN`) and emits `…ItemOpened`; it does **not** auto-fix
(R-PROV-08). NOC `POST …/items/{item}/force-sync` → `requestForceSync` opens an **EM-CFG-04**
(`Foundation/Approvals`) request → force-sync `PENDING_APPROVAL`, item `IN_REVIEW`. Execute-before-
approve → **409**. A **different** approver `…/approve` (SoD: requester can't self-approve) → APPROVED
→ `…/execute` re-broadcasts desired state; item `RESOLVED`. *(ReconciliationTest::
test_drift_opens_item_and_force_sync_resolves.)*

### 8. Vendor rejects the command → retry → give up
`adapter.dispatch` returns `failed` (or throws) → command `FAILED`, a `provisioning_command_attempt`
(`FAILED_RETRYABLE`, `error_code`) logged. The retry policy (`adapter_config.retry_policy_json
{baseSeconds:30, factor:2}`) backs off; persistent failure → `FAILED_FINAL`. *Shows: the attempt
ledger + per-target retry policy as the resilience seam.*

### 9. Bind a real vendor (the deployment task — config only)
Implement `class HuaweiNceGponAdapter implements ProvisioningAdapter` (§4), seed one
`provisioning_adapter_config{target_code, adapter_class:HuaweiNceGponAdapter::class}` row. Every
command for that target now drives the OLT. **No platform code change.**

## 2. Data model — 4 sample rows + readings per table

### `network_node` (Catalog — the plant tree)
**`type`:** `HEADEND|OLT|SPLITTER|FAT|FDT|ONT` (GPON) · `DISTRIBUTION_NODE|AMPLIFIER|LINE_EXTENDER` (HFC) · `VOIPSWITCH|NMS|OTHER`. Tree via `parent_node_code`.
```json
{ "node_id":"nnode_he","code":"HEADEND-NRB","type":"HEADEND","parent_node_code":null }
{ "node_id":"nnode_olt","code":"OLT-NRB-WTL-01","type":"OLT","parent_node_code":"HEADEND-NRB" }
{ "node_id":"nnode_fat","code":"FAT-F12","type":"FAT","parent_node_code":"SPLITTER-S1H" }
{ "node_id":"nnode_ont","code":"ONT-77","type":"ONT","parent_node_code":"FAT-F12" }
```
**Reading:** the chain root is the HEADEND (no parent); ONT-77 is the customer leaf. Walking
`parent_node_code` (ONT→FAT→…→OLT→HEADEND) gives the physical path; the serving **OLT** maps to the
GPON **target plane**. An HFC HomePass would chain modem→AMPLIFIER→DISTRIBUTION_NODE→HEADEND and map
to a CMTS target instead.

### `homepass` (Catalog — the premises)
```json
{ "id":"hp_1","status":"SELLABLE","technology":"GPON","house_type_code":"APARTMENT","franchise_ref":"fr_nrb" }
{ "id":"hp_2","status":"UNDER_CONSTRUCTION","technology":"GPON","house_type_code":"VILLA","franchise_ref":"fr_nrb" }
{ "id":"hp_3","status":"SELLABLE","technology":"HFC","house_type_code":"APARTMENT","franchise_ref":"fr_msa" }
{ "id":"hp_4","status":"RETIRED","technology":"GPON","house_type_code":"OFFICE","franchise_ref":"fr_nrb" }
```
**Reading:** only `SELLABLE` premises can take an order (hp_2 isn't built yet; hp_4 is decommissioned).
`technology` decides the node chain + target plane (GPON vs HFC). `franchise_ref` ties to RBAC scope.

### `provisioning_target` (the vendor plane) · `type`: `GPON|HFC|VOIP|NMS`
```json
{ "target_code":"HUAWEI_NCE_GPON_KE","type":"GPON","name":"Huawei NCE GPON","endpoint":"https://nce.wik:18002","active":true }
{ "target_code":"CMTS_HFC_KE","type":"HFC","name":"Casa CMTS (Clearcable NOMS)","endpoint":"https://noms.wik","active":true }
{ "target_code":"SIP_VOICE_KE","type":"VOIP","name":"VoipSwitch","endpoint":"sip://vs.wik","active":true }
{ "target_code":"DEFAULT_NMS","type":"NMS","name":"Default NMS","endpoint":null,"active":true }
```
**Reading:** one plane per technology; a triple-play subscription touches several (internet→GPON,
voice→VOIP). `DEFAULT_NMS` is the catch-all the POC seeds point at.

### `provisioning_adapter_config` (⭐ the vendor binding) · `execution_mode_default`: `SYNC_REQUIRED|ASYNC_ACCEPTED`
```json
{ "adapter_config_id":"pac_1","target_code":"HUAWEI_NCE_GPON_KE","adapter_class":"…\\HuaweiNceGponAdapter","execution_mode_default":"ASYNC_ACCEPTED","retry_policy_json":{"baseSeconds":30,"factor":2},"status":"ACTIVE" }
{ "adapter_config_id":"pac_2","target_code":"SIP_VOICE_KE","adapter_class":"…\\SipVoiceAdapter","execution_mode_default":"SYNC_REQUIRED","status":"ACTIVE" }
{ "adapter_config_id":"pac_3","target_code":"DEFAULT_NMS","adapter_class":"…\\StubProvisioningAdapter","execution_mode_default":"SYNC_REQUIRED","status":"ACTIVE" }
{ "adapter_config_id":"pac_4","target_code":"CMTS_HFC_KE","adapter_class":"…\\CasaCmtsAdapter","execution_mode_default":"ASYNC_ACCEPTED","status":"SUSPENDED" }
```
**Reading:** **this row is the swappable seam** — change `adapter_class` to repoint a plane.
`ASYNC_ACCEPTED` ⇒ commands sit `ACCEPTED` until the poll worker confirms. `status=SUSPENDED` (pac_4)
⇒ that plane is parked (e.g. maintenance) and the registry treats it as unavailable. The default seed
(pac_3) uses the stub so flows run with no hardware.

### `provisioning_desired_state` (the reconcile baseline) · `desired_status`: `ACTIVE|SUSPENDED|RESTRICTED|TERMINATED|NOT_PRESENT`
```json
{ "desired_state_id":"pds_1","subscription_id":"sub_123","homepass_id":"hp_1","service_ref":"svc_inet","target_code":"HUAWEI_NCE_GPON_KE","subscriber_key":"sub_123:svc_inet","desired_status":"ACTIVE","desired_profile":{"speedProfile":"100M"} }
{ "desired_state_id":"pds_2","subscription_id":"sub_123","service_ref":"svc_voice","target_code":"SIP_VOICE_KE","subscriber_key":"sub_123:svc_voice","desired_status":"ACTIVE","desired_profile":{"callerId":true} }
{ "desired_state_id":"pds_3","subscription_id":"sub_9","service_ref":"svc_inet","target_code":"HUAWEI_NCE_GPON_KE","subscriber_key":"sub_9:svc_inet","desired_status":"SUSPENDED","desired_profile":{} }
{ "desired_state_id":"pds_4","subscription_id":"sub_7","service_ref":"svc_inet","target_code":"HUAWEI_NCE_GPON_KE","subscriber_key":"sub_7:svc_inet","desired_status":"NOT_PRESENT","desired_profile":{} }
```
**Reading:** one row per (subscriber, service, plane). pds_1/2 = a triple-play sub provisioned across
two planes. pds_3 = suspended (reconcile expects the plane to report SUSPENDED). pds_4 = terminated, so
the subscriber should be **absent** — a present subscriber is now drift.

### `provisioning_command` (the dispatch ledger) · `action`: `ACTIVATE|MODIFY|DEACTIVATE|SUSPEND|RESUME` · `status`: `PENDING→SENT→CONFIRMED|ACCEPTED|FAILED|MISMATCH`
```json
{ "command_id":"pcmd_1","broadcast_id":"bcast_1","subscription_id":"sub_123","service_ref":"svc_inet","action":"ACTIVATE","target_code":"HUAWEI_NCE_GPON_KE","status":"CONFIRMED","external_ref":"NMS-AB12","execution_mode":"SYNC_REQUIRED","attempts":1 }
{ "command_id":"pcmd_2","broadcast_id":"bcast_1","subscription_id":"sub_123","service_ref":"svc_voice","action":"ACTIVATE","target_code":"SIP_VOICE_KE","status":"ACCEPTED","external_ref":"VS-7781","execution_mode":"ASYNC_ACCEPTED","attempts":1 }
{ "command_id":"pcmd_3","subscription_id":"sub_5","action":"ACTIVATE","target_code":"HUAWEI_NCE_GPON_KE","status":"FAILED","last_error":"OLT rejected: profile unknown","attempts":3 }
{ "command_id":"pcmd_4","subscription_id":"sub_9","action":"SUSPEND","target_code":"HUAWEI_NCE_GPON_KE","status":"CONFIRMED","attempts":1 }
```
**Reading:** `broadcast_id=bcast_1` groups the two commands from one triple-play ACTIVATE (internet
confirmed sync, voice accepted async). pcmd_3 exhausted retries (`attempts:3`, `FAILED`). The command
is the audit of *what was sent to which plane and how it went*.

### `provisioning_command_attempt` (per-try audit) · `status`: `SUCCESS|FAILED_RETRYABLE|FAILED_FINAL|TIMEOUT`
```json
{ "attempt_id":"pcma_1","command_id":"pcmd_1","adapter_class":"…\\HuaweiNceGponAdapter","status":"SUCCESS","vendor_status_code":"OK" }
{ "attempt_id":"pcma_2","command_id":"pcmd_3","adapter_class":"…\\HuaweiNceGponAdapter","status":"FAILED_RETRYABLE","error_code":"ADAPTER_REJECTED" }
{ "attempt_id":"pcma_3","command_id":"pcmd_3","adapter_class":"…\\HuaweiNceGponAdapter","status":"FAILED_FINAL","error_code":"PROFILE_UNKNOWN" }
{ "attempt_id":"pcma_4","command_id":"pcmd_2","adapter_class":"…\\SipVoiceAdapter","status":"TIMEOUT","error_code":"VENDOR_TIMEOUT" }
```
**Reading:** pcmd_3 has two attempts (retryable then final) — this ledger explains *why* a command
failed and which adapter/vendor code returned it. Invaluable for vendor-integration debugging.

### `provisioning_observed_state` (last poll) & `provisioning_reconciliation_run`/`_item`
```json
// observed_state (mirror of what the plane reports)
{ "observed_state_id":"pos_1","target_code":"HUAWEI_NCE_GPON_KE","subscriber_key":"sub_123:svc_inet","observed_status":"ACTIVE","observed_profile":{"speedProfile":"100M"} }
{ "observed_state_id":"pos_2","target_code":"HUAWEI_NCE_GPON_KE","subscriber_key":"sub_9:svc_inet","observed_status":"ACTIVE" }
// reconciliation_run
{ "run_id":"prr_1","target_code":"HUAWEI_NCE_GPON_KE","status":"COMPLETED","desired_count":120,"observed_count":120,"mismatch_count":1 }
{ "run_id":"prr_2","target_code":null,"status":"RUNNING","desired_count":0 }
// reconciliation_item  (status: OPEN|IN_REVIEW|RESOLVED|IGNORED)
{ "item_id":"pri_1","run_id":"prr_1","subscription_id":"sub_9","subscriber_key":"sub_9:svc_inet","desired_status":"SUSPENDED","observed_status":"ACTIVE","status":"OPEN","diff":{"desiredStatus":"SUSPENDED","observedStatus":"ACTIVE"} }
{ "item_id":"pri_2","subscription_id":"sub_5","status":"RESOLVED","resolution":"FORCE_SYNCED","resolved_by":"u_noc" }
```
**Reading:** pos_2 vs pds_3 = **drift** (network says ACTIVE, BSS wants SUSPENDED) → that's pri_1
(`OPEN`). A run summarises a pass (`mismatch_count`); an item is one subscriber's discrepancy with its
`diff`, moving `OPEN→IN_REVIEW` (force-sync raised) `→RESOLVED` (or `IGNORED` if NOC decides it's fine).

### `provisioning_force_sync_request` · `sync_direction`: `BSS_TO_NETWORK|NETWORK_TO_BSS|MARK_IGNORE` · `status`: `PENDING_APPROVAL|APPROVED|RUNNING|COMPLETED|FAILED|CANCELLED`
```json
{ "force_sync_id":"pfs_1","source_item_id":"pri_1","subscription_id":"sub_9","target_code":"HUAWEI_NCE_GPON_KE","sync_direction":"BSS_TO_NETWORK","requested_action":"REAPPLY_PROFILE","status":"PENDING_APPROVAL","approval_request_id":"appr_77","requested_by_user_id":"u_noc1" }
{ "force_sync_id":"pfs_2","source_item_id":"pri_2","sync_direction":"BSS_TO_NETWORK","status":"COMPLETED","command_id":"pcmd_88","requested_by_user_id":"u_noc1","approved_by_user_id":"u_noc2" }
{ "force_sync_id":"pfs_3","sync_direction":"NETWORK_TO_BSS","status":"APPROVED","reason":"network is source of truth here" }
{ "force_sync_id":"pfs_4","sync_direction":"MARK_IGNORE","status":"CANCELLED","reason":"expected during migration" }
```
**Reading:** `BSS_TO_NETWORK` re-pushes desired (the common case). `NETWORK_TO_BSS` would update BSS to
match the network; `MARK_IGNORE` accepts the diff. pfs_2 shows the **SoD** trail (`requested_by` ≠
`approved_by`) + the corrective `command_id`. `approval_request_id` links the EM-CFG-04 decision.

## 3. Services (worked calls)
| Service | Responsibility |
| --- | --- |
| `ProvisioningService` | `broadcast()`/`dispatch()`/`pollAsyncCommands()`/`recordDesiredState()` |
| `ProvisioningAdapterRegistry` | `forCommand($cmd)`/`forTarget($op,$code)` → the vendor adapter (the seam) |
| `ReconciliationService` | `run()`/`requestForceSync()`/`approveForceSync()` (EM-CFG-04)/`executeForceSync()` |

**`broadcast('sub_123','ACTIVATE',[$spec])`** → create command (`PENDING`) → `dispatch()` →
`forCommand` resolves adapter → `adapter.dispatch()` → confirmed/accepted/failed → log attempt →
`recordDesiredState()` on success.

## 4. ⭐ The vendor-adapter contract (`Contracts/ProvisioningAdapter`)
```php
interface ProvisioningAdapter {
  public function dispatch(ProvisioningCommand $command): ProvisioningResult;       // push desired_state to the vendor
  public function fetchObserved(string $targetCode, string $subscriberKey, string $desiredStatus, array $desiredProfile = []): ?array; // read back (null = not present)
  public function pollStatus(ProvisioningCommand $command): ?ProvisioningResult;    // resolve an ASYNC command (null = still pending)
}
```
`ProvisioningResult::confirmed|accepted|failed(externalRef, response)`. `StubProvisioningAdapter` is
the default (logs + mirrors desired; honours `forceFail`/`simulateAsync`/`simulateObservedStatus`/
`simulateNotPresent`). A real driver translates `command.desired_state` into the vendor API.

## 5. Integration (events) — topic `provisioning.command`
- **Emits:** `ProvisioningCommand{Sent,Confirmed,Failed}`, `ProvisioningReconciliation{RunCompleted,
  ItemOpened}`, `ProvisioningForceSync{Requested,Approved,Completed,Cancelled}`.
- **Consumes:** `SyncProvisioningOnAccountStatusChanged` (ILM `CustomerAccountStatusChanged`).

## 6. Processes (workers + handler)
- **Workers (`Foundation/Console` schedule):** `sophix:provisioning:poll-async` (5 min, resolve
  ACCEPTED via `pollStatus`), `:reconcile` (hourly).
- **Handler — `ActivateServiceHandler` (topic `activate-service`):** reads `{subscriptionId, packageRef}`
  + node `config`; does `broadcast('ACTIVATE')`; outputs `{provisioned, provisioningRefs}`; on fail
  `TaskResult::fail(retryable:true)`.

## 7. Policy & config
`provisioning_target` + `provisioning_adapter_config` (the connector binding), `retry_policy_json`,
EM-CFG-04 force-sync definition (seeded gated by default), Catalog topology (`network_node`,`homepass`).

## 8. Cross-module dependencies
- **Called by →** Subscription (`FulfillmentCallHandler`/`ActivateServiceHandler`), OSR
  (`ProvisionSwapHandler`), ILM (account-status sync).
- **Reads →** Catalog topology + service `network_profile_shape`/`provisioner_key` to shape `desired_profile`.
- **Connector seam →** vendor adapters (Huawei NCE, FiberHome UNM2000, Casa CMTS, VoipSwitch,
  Verimatrix) at deployment behind `ProvisioningAdapterRegistry`.

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-PROV-08 | a mismatch never auto-fixes; NOC drives force-sync | `ReconciliationService::run` |
| R-PROV-07 | destructive force-sync gated by EM-CFG-04 (SoD + audit) | `requestForceSync`/`approveForceSync` |
| §10.2 | each command dispatched through ITS target's adapter | `ProvisioningAdapterRegistry::forCommand` |
| §7.2 | ASYNC commands resolved by the poll worker | `pollAsyncCommands` + `pollStatus` |

## 10. Open items / deltas
- Force-sync EM-CFG-04 + account-status→provisioning cascade were wired during hardening.
- A **typed `desired_state` contract** + a **Headend/Hub routing model** (derive `target_code` from the
  HomePass node chain, not flow config) are the refinements for when real adapters land — today
  `target_code` comes from the flow node `config`.
- Only `StubProvisioningAdapter` ships; all vendor adapters are deployment connectors.
