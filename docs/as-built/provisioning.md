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

## 2. Data model — ≥4 **complete** sample rows + readings per table
> **Completeness:** each row lists **every domain column** (nullables shown as `null`). The surrogate
> primary key shown is the real one; `created_at`/`updated_at` are omitted by convention. `network_node`
> and `homepass` are **Catalog-owned** — shown here only as the path context; their full-width sample
> rows live in `catalog.md` (`homepass` is ~50 columns of structured address/GIS/RoE, **projected** below
> to the columns provisioning's path resolution actually reads).

### `network_node` (Catalog — the plant tree)
**`type`:** `HEADEND|OLT|SPLITTER|FAT|FDT|ONT` (GPON) · `DISTRIBUTION_NODE|AMPLIFIER|LINE_EXTENDER` (HFC) · `VOIPSWITCH|NMS|OTHER`. Tree via `parent_node_code`. **`status`:** `DRAFT|ACTIVE|RETIRED` (default `ACTIVE`).
```json
{ "node_id":"nnode_he","operator_code":"WIK","code":"HEADEND-NRB","type":"HEADEND","name":"Nairobi Headend","parent_node_code":null,"description":"Westlands core","metadata":{"site":"WTL"},"status":"ACTIVE" }
{ "node_id":"nnode_olt","operator_code":"WIK","code":"OLT-NRB-WTL-01","type":"OLT","name":"Westlands OLT 01","parent_node_code":"HEADEND-NRB","description":null,"metadata":{"ports":16},"status":"ACTIVE" }
{ "node_id":"nnode_fat","operator_code":"WIK","code":"FAT-F12","type":"FAT","name":"FAT F12","parent_node_code":"SPLITTER-S1H","description":null,"metadata":null,"status":"ACTIVE" }
{ "node_id":"nnode_ont","operator_code":"WIK","code":"ONT-77","type":"ONT","name":"ONT 77","parent_node_code":"FAT-F12","description":"customer leaf","metadata":null,"status":"ACTIVE" }
```
**Reading:** the chain root is the HEADEND (no parent); ONT-77 is the customer leaf. Walking
`parent_node_code` (ONT→FAT→…→OLT→HEADEND) gives the physical path; the serving **OLT** maps to the
GPON **target plane**. An HFC HomePass would chain modem→AMPLIFIER→DISTRIBUTION_NODE→HEADEND and map
to a CMTS target instead.

### `homepass` (Catalog — the premises) · **projected** to provisioning-relevant columns
> Full ~50-column schema (structured address, building, GIS lat/lng, RoE dates) is in `catalog.md`.
> `status` is **not** a hardcoded enum — it's a code from the `homepass_status_code` catalog whose
> *flags* (`is_sellable`, `is_active`, …) drive behaviour; the codes below are illustrative.
```json
{ "id":"hp_1","operator_code":"WIK","code":"HP-NRB-0001","status":"RFS","technology":"GPON","house_type_code":"M2M","network_nodes":["ONT-77","OLT-NRB-WTL-01"],"tech_region_id":"KE-NRB-KAREN","has_been_active":true,"has_been_sellable":true }
{ "id":"hp_2","operator_code":"WIK","code":"HP-NRB-0002","status":"WAI","technology":"GPON","house_type_code":"S1H","network_nodes":[],"tech_region_id":"KE-NRB-KAREN","has_been_active":false,"has_been_sellable":false }
{ "id":"hp_3","operator_code":"WIK","code":"HP-MSA-0007","status":"RFS","technology":"HFC","house_type_code":"M2M","network_nodes":["CM-12","DN-3"],"tech_region_id":"KE-MSA-NYALI","has_been_active":true,"has_been_sellable":true }
{ "id":"hp_4","operator_code":"WIK","code":"HP-NRB-0099","status":"RETIRED","technology":"GPON","house_type_code":"OFF","network_nodes":["ONT-3"],"tech_region_id":"KE-NRB-KAREN","has_been_active":true,"has_been_sellable":true }
```
**Reading:** only a status whose `is_sellable` flag is set can take an order (hp_2's `WAI` = waiting/under
construction; hp_4's `RETIRED` = decommissioned). `technology` decides the node chain + target plane
(GPON→OLT vs HFC→CMTS); `network_nodes` is the cached path (leaf→…→headend); `tech_region_id` ties to
RBAC scope. `has_been_sellable` is the latch that makes `HomePassReachedSellable` fire only once.

### `provisioning_target` (the vendor plane) · `type`: `GPON|HFC|VOIP|NMS`
```json
{ "target_code":"HUAWEI_NCE_GPON_KE","operator_code":"WIK","type":"GPON","name":"Huawei NCE GPON","endpoint":"https://nce.wik:18002","active":true }
{ "target_code":"CMTS_HFC_KE","operator_code":"WIK","type":"HFC","name":"Casa CMTS (Clearcable NOMS)","endpoint":"https://noms.wik","active":true }
{ "target_code":"SIP_VOICE_KE","operator_code":"WIK","type":"VOIP","name":"VoipSwitch","endpoint":"sip://vs.wik","active":true }
{ "target_code":"DEFAULT_NMS","operator_code":"WIK","type":"NMS","name":"Default NMS","endpoint":null,"active":true }
```
**Reading:** one plane per technology; a triple-play subscription touches several (internet→GPON,
voice→VOIP). `DEFAULT_NMS` is the catch-all the POC seeds point at.

### `provisioning_adapter_config` (⭐ the vendor binding) · `execution_mode_default`: `SYNC_REQUIRED|ASYNC_ACCEPTED` · `status`: `ACTIVE|SUSPENDED`
```json
{ "adapter_config_id":"pac_1","operator_code":"WIK","provisioner_key":"GPON_INET","target_code":"HUAWEI_NCE_GPON_KE","adapter_class":"…\\HuaweiNceGponAdapter","execution_mode_default":"ASYNC_ACCEPTED","timeout_ms":25000,"max_retry_count":5,"retry_policy_json":{"baseSeconds":30,"factor":2},"status":"ACTIVE" }
{ "adapter_config_id":"pac_2","operator_code":"WIK","provisioner_key":null,"target_code":"SIP_VOICE_KE","adapter_class":"…\\SipVoiceAdapter","execution_mode_default":"SYNC_REQUIRED","timeout_ms":15000,"max_retry_count":3,"retry_policy_json":null,"status":"ACTIVE" }
{ "adapter_config_id":"pac_3","operator_code":"WIK","provisioner_key":null,"target_code":"DEFAULT_NMS","adapter_class":"…\\StubProvisioningAdapter","execution_mode_default":"SYNC_REQUIRED","timeout_ms":25000,"max_retry_count":5,"retry_policy_json":null,"status":"ACTIVE" }
{ "adapter_config_id":"pac_4","operator_code":"WIK","provisioner_key":null,"target_code":"CMTS_HFC_KE","adapter_class":"…\\CasaCmtsAdapter","execution_mode_default":"ASYNC_ACCEPTED","timeout_ms":40000,"max_retry_count":8,"retry_policy_json":{"baseSeconds":60,"factor":2},"status":"SUSPENDED" }
```
**Reading:** **this row is the swappable seam** — change `adapter_class` to repoint a plane. The optional
`provisioner_key` (pac_1) refines the binding to a specific PLM service provisioner; `timeout_ms`/
`max_retry_count`/`retry_policy_json` are the per-target resilience knobs.
`ASYNC_ACCEPTED` ⇒ commands sit `ACCEPTED` until the poll worker confirms. `status=SUSPENDED` (pac_4)
⇒ that plane is parked (e.g. maintenance) and the registry treats it as unavailable. The default seed
(pac_3) uses the stub so flows run with no hardware.

### `provisioning_desired_state` (the reconcile baseline) · `desired_status`: `ACTIVE|SUSPENDED|RESTRICTED|TERMINATED|NOT_PRESENT`
```json
{ "desired_state_id":"pds_1","operator_code":"WIK","subscription_id":"sub_123","customer_id":"cust_50","homepass_id":"hp_1","service_ref":"svc_inet","target_code":"HUAWEI_NCE_GPON_KE","subscriber_key":"sub_123:svc_inet","desired_status":"ACTIVE","desired_profile":{"speedProfile":"100M","vlan":101},"source_module":"Subscription","source_ref":"subop_991","effective_from":"2026-06-20T09:00:00Z" }
{ "desired_state_id":"pds_2","operator_code":"WIK","subscription_id":"sub_123","customer_id":"cust_50","homepass_id":"hp_1","service_ref":"svc_voice","target_code":"SIP_VOICE_KE","subscriber_key":"sub_123:svc_voice","desired_status":"ACTIVE","desired_profile":{"callerId":true},"source_module":"Subscription","source_ref":"subop_991","effective_from":"2026-06-20T09:00:00Z" }
{ "desired_state_id":"pds_3","operator_code":"WIK","subscription_id":"sub_9","customer_id":"cust_12","homepass_id":"hp_7","service_ref":"svc_inet","target_code":"HUAWEI_NCE_GPON_KE","subscriber_key":"sub_9:svc_inet","desired_status":"SUSPENDED","desired_profile":{},"source_module":"Ilm","source_ref":"acct_status_sync","effective_from":"2026-06-18T00:00:00Z" }
{ "desired_state_id":"pds_4","operator_code":"WIK","subscription_id":"sub_7","customer_id":"cust_8","homepass_id":null,"service_ref":"svc_inet","target_code":"HUAWEI_NCE_GPON_KE","subscriber_key":"sub_7:svc_inet","desired_status":"NOT_PRESENT","desired_profile":{},"source_module":"Subscription","source_ref":"term_55","effective_from":"2026-06-15T00:00:00Z" }
```
**Reading:** one row per (subscriber, service, plane). pds_1/2 = a triple-play sub provisioned across
two planes. pds_3 = suspended (reconcile expects the plane to report SUSPENDED). pds_4 = terminated, so
the subscriber should be **absent** — a present subscriber is now drift.

### `provisioning_command` (the dispatch ledger) · `action`: `ACTIVATE|MODIFY|DEACTIVATE|SUSPEND|RESUME` · `status`: `PENDING→SENT→CONFIRMED|ACCEPTED|FAILED|MISMATCH` · `execution_mode`: `SYNC|ASYNC_ACCEPTED`
```json
{ "command_id":"pcmd_1","operator_code":"WIK","broadcast_id":"bcast_1","subscription_id":"sub_123","service_ref":"svc_inet","action":"ACTIVATE","target_code":"HUAWEI_NCE_GPON_KE","desired_state":{"desiredStatus":"ACTIVE","speedProfile":"100M"},"observed_state":null,"status":"CONFIRMED","external_ref":"NMS-AB12","request":{"op":"create-sub"},"response":{"ok":true},"attempts":1,"last_error":null,"correlation_id":"corr_77","sent_at":"2026-06-20T09:00:01Z","confirmed_at":"2026-06-20T09:00:02Z","execution_mode":"SYNC","accepted_at":null }
{ "command_id":"pcmd_2","operator_code":"WIK","broadcast_id":"bcast_1","subscription_id":"sub_123","service_ref":"svc_voice","action":"ACTIVATE","target_code":"SIP_VOICE_KE","desired_state":{"desiredStatus":"ACTIVE","callerId":true},"observed_state":null,"status":"ACCEPTED","external_ref":"VS-7781","request":{"op":"add-line"},"response":{"queued":true},"attempts":1,"last_error":null,"correlation_id":"corr_77","sent_at":"2026-06-20T09:00:01Z","confirmed_at":null,"execution_mode":"ASYNC_ACCEPTED","accepted_at":"2026-06-20T09:00:01Z" }
{ "command_id":"pcmd_3","operator_code":"WIK","broadcast_id":"bcast_4","subscription_id":"sub_5","service_ref":"svc_inet","action":"ACTIVATE","target_code":"HUAWEI_NCE_GPON_KE","desired_state":{"desiredStatus":"ACTIVE","speedProfile":"1G"},"observed_state":null,"status":"FAILED","external_ref":null,"request":{"op":"create-sub"},"response":{"error":"profile unknown"},"attempts":3,"last_error":"OLT rejected: profile unknown","correlation_id":"corr_88","sent_at":"2026-06-20T10:00:00Z","confirmed_at":null,"execution_mode":"SYNC","accepted_at":null }
{ "command_id":"pcmd_4","operator_code":"WIK","broadcast_id":"bcast_9","subscription_id":"sub_9","service_ref":"svc_inet","action":"SUSPEND","target_code":"HUAWEI_NCE_GPON_KE","desired_state":{"desiredStatus":"SUSPENDED"},"observed_state":null,"status":"CONFIRMED","external_ref":"NMS-CD34","request":{"op":"suspend"},"response":{"ok":true},"attempts":1,"last_error":null,"correlation_id":"corr_90","sent_at":"2026-06-18T00:00:01Z","confirmed_at":"2026-06-18T00:00:02Z","execution_mode":"SYNC","accepted_at":null }
```
**Reading:** `broadcast_id=bcast_1` groups the two commands from one triple-play ACTIVATE (internet
confirmed sync — `confirmed_at` set; voice accepted async — `accepted_at` set, `confirmed_at` still
null until the poll worker resolves it). pcmd_3 exhausted retries (`attempts:3`, `FAILED`, `last_error`
recorded, no `external_ref`). `correlation_id` threads a command back to the originating flow; the
command is the audit of *what was sent to which plane and how it went*.

### `provisioning_command_attempt` (per-try audit) · `status`: `SUCCESS|FAILED_RETRYABLE|FAILED_FINAL|TIMEOUT`
> (`created_at` uses the DB default; omitted by convention along with the audit timestamps.)
```json
{ "attempt_id":"pcma_1","operator_code":"WIK","command_id":"pcmd_1","attempt_no":1,"adapter_class":"…\\HuaweiNceGponAdapter","status":"SUCCESS","response_payload":{"externalRef":"NMS-AB12"},"vendor_status_code":"OK","duration_ms":420,"error_code":null }
{ "attempt_id":"pcma_2","operator_code":"WIK","command_id":"pcmd_3","attempt_no":1,"adapter_class":"…\\HuaweiNceGponAdapter","status":"FAILED_RETRYABLE","response_payload":{"err":"busy"},"vendor_status_code":"503","duration_ms":1500,"error_code":"ADAPTER_REJECTED" }
{ "attempt_id":"pcma_3","operator_code":"WIK","command_id":"pcmd_3","attempt_no":3,"adapter_class":"…\\HuaweiNceGponAdapter","status":"FAILED_FINAL","response_payload":{"err":"profile unknown"},"vendor_status_code":"422","duration_ms":1300,"error_code":"PROFILE_UNKNOWN" }
{ "attempt_id":"pcma_4","operator_code":"WIK","command_id":"pcmd_2","attempt_no":1,"adapter_class":"…\\SipVoiceAdapter","status":"TIMEOUT","response_payload":null,"vendor_status_code":null,"duration_ms":15000,"error_code":"VENDOR_TIMEOUT" }
```
**Reading:** pcmd_3 has two attempts (`attempt_no` 1 retryable → 3 final) — this ledger explains *why* a
command failed and which adapter/vendor code returned it. `duration_ms` flags the slow TIMEOUT (15 s vs
~0.4 s success). Invaluable for vendor-integration debugging.

### `provisioning_observed_state` (last poll — mirror of what the plane reports)
```json
{ "observed_state_id":"pos_1","operator_code":"WIK","target_code":"HUAWEI_NCE_GPON_KE","subscriber_key":"sub_123:svc_inet","observed_status":"ACTIVE","observed_profile":{"speedProfile":"100M"},"source_run_id":"prr_1","collected_at":"2026-06-21T01:00:00Z" }
{ "observed_state_id":"pos_2","operator_code":"WIK","target_code":"HUAWEI_NCE_GPON_KE","subscriber_key":"sub_9:svc_inet","observed_status":"ACTIVE","observed_profile":{},"source_run_id":"prr_1","collected_at":"2026-06-21T01:00:00Z" }
{ "observed_state_id":"pos_3","operator_code":"WIK","target_code":"SIP_VOICE_KE","subscriber_key":"sub_123:svc_voice","observed_status":"ACTIVE","observed_profile":{"callerId":true},"source_run_id":"prr_1","collected_at":"2026-06-21T01:00:00Z" }
{ "observed_state_id":"pos_4","operator_code":"WIK","target_code":"HUAWEI_NCE_GPON_KE","subscriber_key":"sub_7:svc_inet","observed_status":null,"observed_profile":null,"source_run_id":"prr_1","collected_at":"2026-06-21T01:00:00Z" }
```
**Reading:** one row per (plane, subscriber), upserted each run (`source_run_id`/`collected_at` show
which pass wrote it). pos_1/pos_3 match their desired rows (clean). **pos_2 vs pds_3 = drift** (network
says ACTIVE, BSS wants SUSPENDED). pos_4 reports `null` (subscriber absent) which *matches* pds_4's
`NOT_PRESENT` — absence is the correct observation there.

### `provisioning_reconciliation_run` (one pass) · `scope_type`: `FULL_TARGET|REGION|SUBSCRIPTION|SERVICE_CLASS` · `status`: `RUNNING|COMPLETED|FAILED|PARTIAL`
```json
{ "run_id":"prr_1","operator_code":"WIK","target_code":"HUAWEI_NCE_GPON_KE","scope_type":"FULL_TARGET","scope_value":null,"status":"COMPLETED","desired_count":120,"observed_count":120,"mismatch_count":1,"started_at":"2026-06-21T01:00:00Z","completed_at":"2026-06-21T01:03:00Z" }
{ "run_id":"prr_2","operator_code":"WIK","target_code":null,"scope_type":"FULL_TARGET","scope_value":null,"status":"RUNNING","desired_count":0,"observed_count":0,"mismatch_count":0,"started_at":"2026-06-21T02:00:00Z","completed_at":null }
{ "run_id":"prr_3","operator_code":"WIK","target_code":"HUAWEI_NCE_GPON_KE","scope_type":"SUBSCRIPTION","scope_value":"sub_9","status":"COMPLETED","desired_count":1,"observed_count":1,"mismatch_count":1,"started_at":"2026-06-20T12:00:00Z","completed_at":"2026-06-20T12:00:05Z" }
{ "run_id":"prr_4","operator_code":"WIK","target_code":"SIP_VOICE_KE","scope_type":"FULL_TARGET","scope_value":null,"status":"FAILED","desired_count":30,"observed_count":0,"mismatch_count":0,"started_at":"2026-06-21T01:00:00Z","completed_at":"2026-06-21T01:00:30Z" }
```
**Reading:** prr_1 is the hourly full sweep of the GPON plane (120 desired = 120 observed, 1 mismatch).
prr_2 (`target_code=null`) is an all-targets pass still `RUNNING`. prr_3 is a **scoped** rerun of just
`sub_9` (after a fix). prr_4 `FAILED` — the SIP plane was unreachable, so `observed_count=0` and no
mismatches are opened (a failed fetch is not treated as drift).

### `provisioning_reconciliation_item` (one mismatch) · `status`: `OPEN|RESOLVED|IGNORED` (UI surfaces `IN_REVIEW` once a force-sync is raised) · `resolution`: `FORCE_SYNCED|MANUAL|MATCHED_SINCE`
```json
{ "item_id":"pri_1","operator_code":"WIK","run_id":"prr_1","target_code":"HUAWEI_NCE_GPON_KE","subscription_id":"sub_9","service_ref":"svc_inet","subscriber_key":"sub_9:svc_inet","desired_status":"SUSPENDED","observed_status":"ACTIVE","diff":{"desiredStatus":"SUSPENDED","observedStatus":"ACTIVE"},"status":"OPEN","resolution":null,"resolved_by":null,"resolved_at":null }
{ "item_id":"pri_2","operator_code":"WIK","run_id":"prr_3","target_code":"HUAWEI_NCE_GPON_KE","subscription_id":"sub_5","service_ref":"svc_inet","subscriber_key":"sub_5:svc_inet","desired_status":"ACTIVE","observed_status":"SUSPENDED","diff":{"desiredStatus":"ACTIVE","observedStatus":"SUSPENDED"},"status":"RESOLVED","resolution":"FORCE_SYNCED","resolved_by":"u_noc2","resolved_at":"2026-06-20T12:30:00Z" }
{ "item_id":"pri_3","operator_code":"WIK","run_id":"prr_1","target_code":"HUAWEI_NCE_GPON_KE","subscription_id":"sub_3","service_ref":"svc_inet","subscriber_key":"sub_3:svc_inet","desired_status":"ACTIVE","observed_status":null,"diff":{"desiredStatus":"ACTIVE","observedStatus":null},"status":"IGNORED","resolution":"MANUAL","resolved_by":"u_noc1","resolved_at":"2026-06-21T08:00:00Z" }
{ "item_id":"pri_4","operator_code":"WIK","run_id":"prr_3","target_code":"HUAWEI_NCE_GPON_KE","subscription_id":"sub_9","service_ref":"svc_inet","subscriber_key":"sub_9:svc_inet","desired_status":"SUSPENDED","observed_status":"SUSPENDED","diff":null,"status":"RESOLVED","resolution":"MATCHED_SINCE","resolved_by":null,"resolved_at":"2026-06-20T13:00:00Z" }
```
**Reading:** pri_1 is the live drift (`OPEN`, no resolution yet). pri_2 was corrected by a force-sync
(`FORCE_SYNCED`, `resolved_by=u_noc2`). pri_3 a NOC decided to leave (`IGNORED`/`MANUAL`). pri_4 shows
auto-close: a later run found desired==observed, so the prior item is resolved `MATCHED_SINCE` with no
human (`resolved_by=null`).

### `provisioning_force_sync_request` · `sync_direction`: `BSS_TO_NETWORK|NETWORK_TO_BSS|MARK_IGNORE` · `status`: `PENDING_APPROVAL|APPROVED|RUNNING|COMPLETED|FAILED|CANCELLED`
```json
{ "force_sync_id":"pfs_1","operator_code":"WIK","source_item_id":"pri_1","subscription_id":"sub_9","service_ref":"svc_inet","target_code":"HUAWEI_NCE_GPON_KE","sync_direction":"BSS_TO_NETWORK","requested_action":"REAPPLY_PROFILE","status":"PENDING_APPROVAL","approval_request_id":"appr_77","requested_by_user_id":"u_noc1","approved_by_user_id":null,"command_id":null,"reason":null }
{ "force_sync_id":"pfs_2","operator_code":"WIK","source_item_id":"pri_2","subscription_id":"sub_5","service_ref":"svc_inet","target_code":"HUAWEI_NCE_GPON_KE","sync_direction":"BSS_TO_NETWORK","requested_action":"REAPPLY_PROFILE","status":"COMPLETED","approval_request_id":"appr_78","requested_by_user_id":"u_noc1","approved_by_user_id":"u_noc2","command_id":"pcmd_88","reason":null }
{ "force_sync_id":"pfs_3","operator_code":"WIK","source_item_id":"pri_3","subscription_id":"sub_3","service_ref":"svc_inet","target_code":"HUAWEI_NCE_GPON_KE","sync_direction":"NETWORK_TO_BSS","requested_action":null,"status":"APPROVED","approval_request_id":"appr_79","requested_by_user_id":"u_noc1","approved_by_user_id":"u_noc2","command_id":null,"reason":"network is source of truth here" }
{ "force_sync_id":"pfs_4","operator_code":"WIK","source_item_id":null,"subscription_id":null,"service_ref":null,"target_code":"HUAWEI_NCE_GPON_KE","sync_direction":"MARK_IGNORE","requested_action":null,"status":"CANCELLED","approval_request_id":null,"requested_by_user_id":"u_noc1","approved_by_user_id":null,"command_id":null,"reason":"expected during migration" }
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
- **Handler — `ActivateServiceHandler` (topic `provisioning.activate-service`):** reads the subscription
  business key + `packageRef` var + node `config` (`target`/`speedProfile`/`serviceRef`); does
  `broadcast('ACTIVATE')`; outputs `{provisioned, provisioningRefs}`; on fail
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
