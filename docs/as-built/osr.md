# OSR — As-Built Design (Equipment & Stock)

> **Capability codes:** OSR-01 (stock chain), OSR-INSTANCE-01 (serialized instances), OSR-02
> (procurement), OSR-05 (inventory audit), OSR-RMA-01 (swap/RMA), PLM-CFG-06 (SKUs) · **Module path:**
> `Modules/Osr` · **Tests:** `OsrApi`, `ProcurementAudit`, `StockReasonAndBom`, `StockReservation`,
> `StockRules`, `SwapRequest`

## 1. Purpose & boundaries
- **Owns:** the **stock chain** (locations/balances/movements/reservations), the **serialized
  instance** registry, **procurement** (POs), **inventory audit** (counts), and **equipment swap/RMA**.
- **Does NOT own:** field work (WorkOrder), the network (Provisioning), money (Billing) — it calls them.
  Stock authority stays here.
- **Job:** authoritative equipment/stock state with governed movements, procurement, and swaps.

## 📖 Scenarios (service + Foundation involvement)

### 1. Buy stock (EM-CFG-04 gated) → receive it
`ProcurementService::create` → PO `DRAFT`. `approve` opens a **PURCHASE_ORDER** EM-CFG-04 request
(**Foundation/Approvals**): with a policy → `PENDING_APPROVAL`; a **different** approver decides via
`…/approvals/{req}/decide` (SoD). `receive` posts a `GOODS_RECEIPT` `stock_movement` (+qty) and
registers each serial as an `equipment_instance` (`IN_MAIN_WAREHOUSE`). *Proven by `ProcurementAuditTest`.*

### 2. Reserve stock for an install WO → consume / release (event-driven)
`StockService::reserve(wo_id, sku, location, qty)` writes a `stock_reservation` (`ACTIVE`). On
`WorkOrderFinalized` → `ConsumeReservationOnWoLifecycle` (**listener on the outbox**) flips it
`CONSUMED`; on `WorkOrderCancelled` → `RELEASED`. Unconsumed holds past `expires_at` are swept `EXPIRED`
by the `sophix:stock:expire-reservations` command (registered for periodic invocation; the module's own
schedule wiring is currently disabled). *Foundation: outbox listener + sweep command.* *Proven by
`StockReservationTest`.*

### 3. A damaged write-off needs approval
`POST /api/stock-movements` reason `WRITE_OFF` (`stock_reason_code.requires_approval=true`) →
`StockService::submit` holds it as an EM-CFG-04 request (auto-approves only if no policy). A `RECEIPT`
reason posts immediately. *Shows: config-driven approval per reason code.* *Proven by `StockRulesTest`.*

### 4. Inventory count → reconcile (idempotent)
`InventoryAuditService::open(location)` → `count(session, lines)` records system vs counted →
`reconcile` posts **one** `INVENTORY_AUDIT_ADJUSTMENT` movement for the variance; a second reconcile is
a no-op (R-OSR-05-09). *Shows: idempotent correction.* *Proven by `ProcurementAuditTest`.*

### 5. Instance lifecycle: warehouse → van → field
`EquipmentInstanceService::transition` walks the state machine: `IN_MAIN_WAREHOUSE` → (issue to van)
`IN_CONTRACTOR_STOCK` → (install) `IN_FIELD_ACTIVE` bound to a customer/subscription; each step emits
`EquipmentInstanceStateChanged`. *Shows: the serialized state machine + events.*

### 6. HFC swap (recovered) → completed, charged
`SwapRequestService` starts the `osr-swap` workflow: `ValidateSwapEligibilityHandler` →
`ReserveSlotHandler` → `CreateSwapWorkOrderHandler` (→ WorkOrder) → field visit recovers the unit →
`RecoverSourceHandler` (`SOURCE_RECOVERED`) → `CompleteSwapHandler`: swap `COMPLETED`; if `chargeable`
(out-of-warranty/upgrade) it raises the SKU deposit as a BIL-01 intent. *Foundation: workflow + Billing
call.* *Proven by `SwapRequestTest`.*

### 7. EQR — customer refuses return → deposit forfeited
On the field visit `recovered=false` → `CompleteWithoutRecoveryHandler`: swap
`COMPLETED_WITHOUT_RECOVERY`, the unit stays `IN_FIELD_ACTIVE`, and the **deposit is forfeited** — it
resolves the SKU `deposit_amount` and raises a `DEPOSIT_FORFEITURE` BIL-01 intent. Emits
`EquipmentSwapCompleted{depositForfeited:true}`. *Proven by `SwapRequestTest::test_eqr_…`.*

### 8. A movement can never drive on-hand negative
`StockService::move` rejects up front when a debit would push `stock_balance.quantity` below 0
(R-OSR-SC-8) → `DomainException`. *Shows: a hard stock invariant.* *Proven by `StockRulesTest`.*

### (bonus) 9. Defective recovered unit → vendor RMA
A swap completed with `defectConfirmed` records a `vendor_rma_stub` row with `batch_ref='PENDING_BATCH'`
for the v1.0 batch handoff (a real vendor-RMA integration is a connector).

## 2. Data model — ≥4 **complete** sample rows + readings
> **Completeness:** each row lists **every domain column** (nullables shown as `null`). The string
> business key shown is the real primary key (or the surrogate `id` where the table has no business key,
> e.g. `stock_movement`/`stock_balance`); `created_at`/`updated_at` are omitted by convention.

### `equipment_sku` (PLM-CFG-06) · `category`: `ROUTER|ONT|STB|SPLITTER|CABLE|WALL_SOCKET|MOUNT_KIT|SMARTCARD` · `ownership_semantics`: `RETURNABLE|CONSUMABLE|RENTED`
```json
{ "sku_id":"WIK-ONT-HUAWEI-EG8145V5","operator_code":"WIK","name":"Huawei EG8145V5 ONT","category":"ONT","is_serialized":true,"ownership_semantics":"RETURNABLE","deposit_amount":5000,"warranty_days":365,"active":true }
{ "sku_id":"WIK-STB-4K","operator_code":"WIK","name":"4K Android STB","category":"STB","is_serialized":true,"ownership_semantics":"RENTED","deposit_amount":3000,"warranty_days":365,"active":true }
{ "sku_id":"WIK-CABLE-CAT6","operator_code":"WIK","name":"Cat6 Drop Cable (m)","category":"CABLE","is_serialized":false,"ownership_semantics":"CONSUMABLE","deposit_amount":0,"warranty_days":0,"active":true }
{ "sku_id":"WIK-ONT-OLD","operator_code":"WIK","name":"Legacy ONT (retired)","category":"ONT","is_serialized":true,"ownership_semantics":"RETURNABLE","deposit_amount":4000,"warranty_days":90,"active":false }
```
**Reading:** `is_serialized` decides whether each unit is tracked as an `equipment_instance` (ONT/STB)
or only as bulk `stock_balance` (cable, a `CONSUMABLE`). `deposit_amount` is what an EQR forfeiture or
out-of-warranty swap charges; `ownership_semantics` says whether the device is returnable/rented
(deposit-bearing) or consumed. `active:false` = retired SKU (no new stock).

### `equipment_instance` · `state`: `IN_MAIN_WAREHOUSE|IN_CONTRACTOR_STOCK|RESERVED_FOR_WO|IN_FIELD_ACTIVE|IN_FIELD_DEFECTIVE|RECOVERED_BY_CONTRACTOR|RETURNED|FAULTY|RETIRED`
```json
{ "instance_id":"eqi_1","operator_code":"WIK","sku_id":"WIK-ONT-HUAWEI-EG8145V5","serial":"SN-001","mac_address":"AC:DE:48:00:00:01","state":"IN_MAIN_WAREHOUSE","location_id":"WIK-WAREHOUSE-MAIN","customer_id":null,"subscription_id":null,"active":true }
{ "instance_id":"eqi_2","operator_code":"WIK","sku_id":"WIK-ONT-HUAWEI-EG8145V5","serial":"SN-002","mac_address":"AC:DE:48:00:00:02","state":"IN_CONTRACTOR_STOCK","location_id":"WIK-VAN-ctr_9","customer_id":null,"subscription_id":null,"active":true }
{ "instance_id":"eqi_3","operator_code":"WIK","sku_id":"WIK-STB-4K","serial":"SN-100","mac_address":null,"state":"IN_FIELD_ACTIVE","location_id":null,"customer_id":"cust_1","subscription_id":"sub_1","active":true }
{ "instance_id":"eqi_4","operator_code":"WIK","sku_id":"WIK-ONT-OLD","serial":"SN-900","mac_address":"AC:DE:48:00:09:00","state":"IN_FIELD_DEFECTIVE","location_id":null,"customer_id":"cust_2","subscription_id":"sub_9","active":true }
```
**Reading:** the state is *where the unit physically is + its condition*. eqi_1 is sellable warehouse
stock; eqi_2 is on a contractor van; eqi_3 is installed at a customer (bound to a subscription, no
`location_id` — it's in the field); eqi_4 is defective in the field (a swap candidate). A swap moves a
source instance through `RESERVED_FOR_WO → RECOVERED_BY_CONTRACTOR` (or stays in field on EQR). `active`
flips false only once the instance reaches the terminal `RETIRED` state (the transition that emits the
`EquipmentInstanceDecommissioned` event).

### `stock_location` (`type`: `WAREHOUSE|CONTRACTOR_VAN`)
```json
{ "location_id":"WIK-WAREHOUSE-MAIN","operator_code":"WIK","type":"WAREHOUSE","name":"Main Warehouse (Nairobi)","contractor_id":null,"active":true }
{ "location_id":"WIK-VAN-ctr_9","operator_code":"WIK","type":"CONTRACTOR_VAN","name":"Van — Contractor ctr_9","contractor_id":"ctr_9","active":true }
{ "location_id":"WIK-WAREHOUSE-MSA","operator_code":"WIK","type":"WAREHOUSE","name":"Mombasa Warehouse","contractor_id":null,"active":true }
{ "location_id":"WIK-VAN-ctr_4","operator_code":"WIK","type":"CONTRACTOR_VAN","name":"Van — Contractor ctr_4 (retired)","contractor_id":"ctr_4","active":false }
```
**Reading:** stock lives at locations; a `CONTRACTOR_VAN` is a contractor's rolling stock (keyed to
`contractor_id`). A WAREHOUSE has no `contractor_id`. `active:false` (the ctr_4 van) is a decommissioned
location — no new movements post to it.

### `stock_balance` (derived projection per (location, sku); `available = quantity − qty_reserved`)
```json
{ "id":"sb_1","operator_code":"WIK","location_id":"WIK-WAREHOUSE-MAIN","sku_id":"WIK-CABLE-CAT6","quantity":4200,"qty_reserved":150 }
{ "id":"sb_2","operator_code":"WIK","location_id":"WIK-VAN-ctr_9","sku_id":"WIK-CABLE-CAT6","quantity":300,"qty_reserved":0 }
{ "id":"sb_3","operator_code":"WIK","location_id":"WIK-WAREHOUSE-MSA","sku_id":"WIK-CABLE-CAT6","quantity":80,"qty_reserved":80 }
{ "id":"sb_4","operator_code":"WIK","location_id":"WIK-WAREHOUSE-MAIN","sku_id":"WIK-ONT-HUAWEI-EG8145V5","quantity":0,"qty_reserved":0 }
```
**Reading:** `stock_balance` is on-hand per (location, sku) for **non-serialized** SKUs (serialized
units are counted by their instances, so the ONT balance row sb_4 stays 0). `quantity` is the on-hand
total; `qty_reserved` is the held-but-unavailable portion (sb_3 is fully reserved — nothing available).

### `stock_movement` (append-only ledger) · `reason_code` (catalog `stock_reason_code`: `direction` IN/OUT/EITHER, `requires_approval`)
```json
{ "id":"sm_1","operator_code":"WIK","sku_id":"WIK-CABLE-CAT6","location_id":"WIK-WAREHOUSE-MAIN","quantity":5000,"reason_code":"GOODS_RECEIPT","reference":"po_1","approved_by":null }
{ "id":"sm_2","operator_code":"WIK","sku_id":"WIK-CABLE-CAT6","location_id":"WIK-VAN-ctr_9","quantity":-50,"reason_code":"TRANSFER_OUT","reference":"transfer_7","approved_by":null }
{ "id":"sm_3","operator_code":"WIK","sku_id":"WIK-CABLE-CAT6","location_id":"WIK-WAREHOUSE-MAIN","quantity":-10,"reason_code":"WRITE_OFF","reference":"appr_55","approved_by":"u_stockmgr2" }
{ "id":"sm_4","operator_code":"WIK","sku_id":"WIK-CABLE-CAT6","location_id":"WIK-WAREHOUSE-MSA","quantity":-3,"reason_code":"INVENTORY_AUDIT_ADJUSTMENT","reference":"scs_2","approved_by":"u_stockmgr1" }
```
**Reading:** movements are the **immutable ledger**; the `reason_code` fixes the sign/direction and whether
approval was needed. The catalog `stock_reason_code` seeds `RECEIPT|ISSUE|TRANSFER_IN|TRANSFER_OUT|INSTALL|RETURN|ADJUST|WRITE_OFF`
(`ADJUST`/`WRITE_OFF` carry `requires_approval=true`); the services also post the literal flow codes
`GOODS_RECEIPT` (PO receipt), `TRANSFER_OUT`/`TRANSFER_IN` (a two-tier transfer) and `INVENTORY_AUDIT_ADJUSTMENT`
(reconcile). sm_1 is a goods receipt (+), sm_2 a transfer to a van (−), sm_3 an **approval-gated** `WRITE_OFF`
(`reference` is the approval id, `approved_by` the second-person approver per R-OSR-SC-9), sm_4 the single
`INVENTORY_AUDIT_ADJUSTMENT` a reconcile posts (its `reference` is the count session). `quantity` is
signed (+ inbound, − outbound). (`stock_movement` has only `created_at` — no `updated_at` on this append-only ledger.)

### `stock_reason_code` (operator catalog, composite-unique `(operator_code, code)`) · `direction`: `IN|OUT|EITHER`
```json
{ "id":1,"operator_code":"WIK","code":"RECEIPT","description":"Goods received into stock","direction":"IN","requires_approval":false,"active":true }
{ "id":5,"operator_code":"WIK","code":"INSTALL","description":"Consumed on a customer install","direction":"OUT","requires_approval":false,"active":true }
{ "id":7,"operator_code":"WIK","code":"ADJUST","description":"Inventory adjustment (count variance)","direction":"EITHER","requires_approval":true,"active":true }
{ "id":8,"operator_code":"WIK","code":"WRITE_OFF","description":"Damaged/lost write-off","direction":"OUT","requires_approval":true,"active":true }
```
**Reading:** the operator-scoped reason catalog governs `stock_movement.reason_code`: `direction` fixes the
allowed sign, `requires_approval=true` (ADJUST, WRITE_OFF) routes the movement through EM-CFG-04 before it
posts. When a catalog exists for the operator, `StockService::move` rejects any non-catalog code
(`UNKNOWN_STOCK_REASON`). Seeded codes: `RECEIPT|ISSUE|TRANSFER_IN|TRANSFER_OUT|INSTALL|RETURN|ADJUST|WRITE_OFF`.

### `stock_reservation` · `status`: `ACTIVE|CONSUMED|RELEASED|EXPIRED`
```json
{ "reservation_id":"rsv_1","operator_code":"WIK","sku_id":"WIK-CABLE-CAT6","location_id":"WIK-WAREHOUSE-MAIN","qty":150,"wo_id":"wo_1","reference":"FTTH_INSTALL","status":"ACTIVE","expires_at":"2026-07-21T09:00:00Z","resolved_at":null }
{ "reservation_id":"rsv_2","operator_code":"WIK","sku_id":"WIK-CABLE-CAT6","location_id":"WIK-WAREHOUSE-MAIN","qty":40,"wo_id":"wo_1","reference":"FTTH_INSTALL","status":"CONSUMED","expires_at":"2026-07-20T09:00:00Z","resolved_at":"2026-06-20T12:30:00Z" }
{ "reservation_id":"rsv_3","operator_code":"WIK","sku_id":"WIK-CABLE-CAT6","location_id":"WIK-WAREHOUSE-MAIN","qty":25,"wo_id":"wo_5","reference":null,"status":"RELEASED","expires_at":"2026-07-19T09:00:00Z","resolved_at":"2026-06-21T11:45:00Z" }
{ "reservation_id":"rsv_4","operator_code":"WIK","sku_id":"WIK-CABLE-CAT6","location_id":"WIK-WAREHOUSE-MSA","qty":80,"wo_id":"wo_8","reference":null,"status":"EXPIRED","expires_at":"2026-06-18T09:00:00Z","resolved_at":"2026-06-18T09:05:00Z" }
```
**Reading:** a reservation holds stock for a WO (it raises the location's `qty_reserved`). `ACTIVE`
(held) → `CONSUMED` (WO finalized) or `RELEASED` (WO cancelled) via the lifecycle listener; an
un-actioned hold past `expires_at` is swept `EXPIRED` (R-OSR-SC-7) — `resolved_at` records the terminal
transition. This is how install stock is promised without double-allocating. (Serialized SKUs reserve
the instance; bulk SKUs like cable reserve a `qty`.)

### `purchase_order` · `status`: `DRAFT|PENDING_APPROVAL|APPROVED|RECEIVED|REJECTED`
```json
{ "po_id":"po_1","operator_code":"WIK","supplier":"Huawei","location_id":"WIK-WAREHOUSE-MAIN","status":"RECEIVED","approval_request_id":"appr_60","approval_mode":null,"total_value":150000,"created_by":"u_proc1" }
{ "po_id":"po_2","operator_code":"WIK","supplier":"Casa","location_id":"WIK-WAREHOUSE-MAIN","status":"PENDING_APPROVAL","approval_request_id":"appr_70","approval_mode":null,"total_value":900000,"created_by":"u_proc1" }
{ "po_id":"po_3","operator_code":"WIK","supplier":"Local","location_id":"WIK-WAREHOUSE-MSA","status":"APPROVED","approval_request_id":"appr_71","approval_mode":null,"total_value":20000,"created_by":"u_proc2" }
{ "po_id":"po_4","operator_code":"WIK","supplier":"FiberHome","location_id":"WIK-WAREHOUSE-MAIN","status":"REJECTED","approval_request_id":"appr_72","approval_mode":null,"total_value":50000,"created_by":"u_proc2" }
```
**Reading:** `approve()` opens an EM-CFG-04 request and stamps `approval_request_id`; if a policy makes the
request `PENDING` the PO parks in `PENDING_APPROVAL` (po_2), otherwise it auto-approves straight to
`APPROVED`. `decide()` then flips a parked PO to `APPROVED` (po_3) or `REJECTED` (po_4) on the SoD decision.
po_1 is fully `RECEIVED` (stock posted + serials registered). A `receive` is only allowed from `APPROVED`.
(`approval_mode` is a reserved EM-CFG-04 snapshot column — present in the schema but not yet written by the
service, so always `null`.)

### `purchase_order_line` (PO detail, FK → `purchase_order` cascade)
```json
{ "po_line_id":"pol_1","po_id":"po_1","sku_id":"WIK-ONT-HUAWEI-EG8145V5","quantity_ordered":100,"quantity_received":100,"unit_cost":1500 }
{ "po_line_id":"pol_2","po_id":"po_2","sku_id":"WIK-STB-4K","quantity_ordered":300,"quantity_received":0,"unit_cost":3000 }
{ "po_line_id":"pol_3","po_id":"po_3","sku_id":"WIK-CABLE-CAT6","quantity_ordered":5000,"quantity_received":0,"unit_cost":4 }
{ "po_line_id":"pol_4","po_id":"po_4","sku_id":"WIK-ONT-HUAWEI-EG8145V5","quantity_ordered":40,"quantity_received":0,"unit_cost":1500 }
```
**Reading:** each line is a SKU on a PO; `receive` posts a `GOODS_RECEIPT` movement per line and bumps
`quantity_received` (pol_1 is fully received against po_1; serialized lines also register one
`equipment_instance` per serial). `unit_cost × quantity_ordered` rolls up to `purchase_order.total_value`.

### `stock_count_session` · `status`: `OPEN|COUNTED|RECONCILED` · & `stock_count_line`
```json
{ "session_id":"scs_1","operator_code":"WIK","location_id":"WIK-WAREHOUSE-MAIN","status":"RECONCILED","variance_lines":1,"created_by":"u_stockmgr1","reconciled_at":"2026-06-19T16:00:00Z" }
{ "session_id":"scs_2","operator_code":"WIK","location_id":"WIK-WAREHOUSE-MSA","status":"COUNTED","variance_lines":1,"created_by":"u_stockmgr1","reconciled_at":null }
{ "count_line_id":"scl_1","session_id":"scs_1","sku_id":"WIK-CABLE-CAT6","system_qty":4200,"counted_qty":4200,"variance":0 }
{ "count_line_id":"scl_2","session_id":"scs_2","sku_id":"WIK-CABLE-CAT6","system_qty":83,"counted_qty":80,"variance":-3 }
```
**Reading:** `InventoryAuditService` walks a session `open → count → reconcile`. `count` records
`system_qty` vs `counted_qty` per SKU (`variance` is the delta; `variance_lines` counts non-zero lines);
`reconcile` posts ONE `INVENTORY_AUDIT_ADJUSTMENT` movement per variance line (scl_2's −3 produced sm_4
above, `reference=scs_2`) and stamps `reconciled_at`; a second reconcile is a no-op (R-OSR-05-09).

### `equipment_swap_request` · `kind`: `SWAP_HFC|SWAP_GPON|EQP|EQU` · `status`: `CREATED|AWAITING_SLOT|WO_CREATED|FIELD_VISIT_IN_PROGRESS|SOURCE_RECOVERED|COMPLETED|COMPLETED_WITHOUT_RECOVERY|FAILED`
```json
{ "swap_id":"swp_1","operator_code":"WIK","kind":"SWAP_GPON","source_instance_id":"eqi_4","target_instance_id":"eqi_1","subscription_id":"sub_9","customer_id":"cust_2","homepass_id":"hp_7","recovery_contractor_id":"ctr_9","status":"COMPLETED","chargeable":false,"charge_code":null,"charge_amount":null,"failure_code":null,"flow_payload":{"warranty":"in_warranty"},"work_order_id":"wo_30","slot_commitment_id":"sc_5","process_instance_id":"pi_30" }
{ "swap_id":"swp_2","operator_code":"WIK","kind":"EQU","source_instance_id":"eqi_5","target_instance_id":"eqi_6","subscription_id":"sub_10","customer_id":"cust_3","homepass_id":"hp_8","recovery_contractor_id":"ctr_9","status":"COMPLETED","chargeable":true,"charge_code":"UPGRADE_FEE","charge_amount":5000,"failure_code":null,"flow_payload":{"upgrade":"wifi6"},"work_order_id":"wo_31","slot_commitment_id":"sc_6","process_instance_id":"pi_31" }
{ "swap_id":"swp_3","operator_code":"WIK","kind":"EQP","source_instance_id":"eqi_7","target_instance_id":null,"subscription_id":"sub_11","customer_id":"cust_4","homepass_id":"hp_9","recovery_contractor_id":"ctr_4","status":"COMPLETED_WITHOUT_RECOVERY","chargeable":true,"charge_code":"DEPOSIT_FORFEITURE","charge_amount":3000,"failure_code":null,"flow_payload":{"recovered":false},"work_order_id":"wo_32","slot_commitment_id":"sc_7","process_instance_id":"pi_32" }
{ "swap_id":"swp_4","operator_code":"WIK","kind":"SWAP_HFC","source_instance_id":"eqi_8","target_instance_id":null,"subscription_id":"sub_12","customer_id":"cust_5","homepass_id":"hp_3","recovery_contractor_id":"ctr_4","status":"FIELD_VISIT_IN_PROGRESS","chargeable":false,"charge_code":null,"charge_amount":null,"failure_code":null,"flow_payload":null,"work_order_id":"wo_33","slot_commitment_id":"sc_8","process_instance_id":"pi_33" }
```
**Reading:** `kind` selects the flow (GPON/HFC defective swap, EQP pickup, EQU upgrade). swp_1 was a
free in-warranty swap (source recovered, new `target_instance_id` installed); swp_2 an upgrade (charged
via BIL-01); swp_3 an EQR forfeiture (`COMPLETED_WITHOUT_RECOVERY`, no target installed, deposit billed);
swp_4 is mid field-visit. The swap stores its `work_order_id`, `slot_commitment_id` and
`process_instance_id` (the driving workflow); `flow_payload` carries per-flow specifics; `failure_code`
is set only on a `FAILED` swap.

### `vendor_rma_stub` (v1.0 vendor-handoff stub)
```json
{ "id":"vrma_1","operator_code":"WIK","swap_id":"swp_1","source_instance_id":"eqi_4","vendor_ref":null,"batch_ref":"PENDING_BATCH","shipped_at":null }
{ "id":"vrma_2","operator_code":"WIK","swap_id":"swp_2","source_instance_id":"eqi_5","vendor_ref":null,"batch_ref":"PENDING_BATCH","shipped_at":null }
```
**Reading:** when a swap completes with `defectConfirmed`, `CompleteSwapHandler` records one stub per
recovered defective unit with `batch_ref='PENDING_BATCH'` (`vendor_ref`/`shipped_at` stay null until a real
vendor-RMA connector batches and ships it). There is no status column — the row's existence + `batch_ref`
is the whole record in v1.0.

## 3. Services (worked calls)
| Service | Responsibility |
| --- | --- |
| `StockService` | `submit()` (gate write-offs via EM-CFG-04) / `move()` (raw poster, R-OSR-SC-8) / reserve·consume·release / availability |
| `EquipmentInstanceService` | register + `transition()` serialized instances |
| `ProcurementService` | PO create / approve (EM-CFG-04) / receive (serial registration) |
| `InventoryAuditService` | open / count / reconcile |
| `SwapRequestService` | OSR-RMA-01 swap request + the `osr-swap` workflow |

## 4. API surface
`/api/equipment-skus`, `/api/stock-{locations,balances,movements,reservations}`,
`/api/equipment-instances`, `/api/purchase-orders` (+`…/approvals/{req}/decide`), `/api/stock-counts`,
`/api/swap-requests`. Reads under `permission:stock.read`; writes under `permission:stock.manage`
(+ `idempotency` on stock-movements, equipment-instances/swap-requests creates, purchase-order receive).

## 5. Integration (events) — topic `osr.equipment`
- **Emits:** `StockMoved`, `StockReserved`, `StockReservation{Consumed,Released,Expired}`,
  `EquipmentInstance{Registered,StateChanged,RecoveredByContractor,BoundToCustomer,UnboundFromCustomer,Decommissioned}`,
  `EquipmentSkuCreated`, `EquipmentSourceRecovered`, `EquipmentSwap{Requested,Rejected,Completed}`
  (Completed carries `depositForfeited`+amount).
- **Consumes:** `ConsumeReservationOnWoLifecycle` (WO finalize→consume / cancel→release).

## 6. Processes (swap workflow)
Handlers: `ValidateSwapEligibility`, `ReserveSlot`, `CreateSwapWorkOrder`, `RecoverSource`,
`ProvisionSwap`, `CompleteSwap` (chargeable→BIL-01), `CompleteWithoutRecovery` (EQR→forfeit), `FailSwap`.

## 7. Policy & config
`stock_reason_code` (sign/direction + `requires_approval`), SKU catalog, EM-CFG-04 definitions for
write-offs/POs. All per-operator data.

## 8. Cross-module dependencies
- **Calls →** WorkOrder (swap WO), Billing (`BillingIntentService` for swap charge / deposit
  forfeiture), Provisioning (swap provision), Foundation Approvals.
- **Reacts to →** WorkOrder lifecycle (reservation consume/release).

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-OSR-SC-8 | a movement may never drive on-hand negative | `StockService::move` |
| R-OSR-SC-9 | approval-required reasons gate via EM-CFG-04 | `StockService::submit` |
| R-OSR-02-04 | PO approval gated via EM-CFG-04 (SoD) | `ProcurementService::approve` |
| R-OSR-02-07/08 | receipt posts a movement + registers serials | `ProcurementService::receive` |
| R-OSR-05-09 | a second reconcile posts no second correction | `InventoryAuditService::reconcile` |
| OSR-RMA EQR | refused return forfeits the deposit (BIL-01) | `CompleteWithoutRecoveryHandler` |

## 10. Open items / deltas
- PO approval (EM-CFG-04) and EQR deposit-forfeiture billing were wired during hardening.
- `vendor_rma_stub` is a v1.0 batch stub pending a real vendor-RMA integration (connector).
