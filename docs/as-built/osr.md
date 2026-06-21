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
`CONSUMED`; on `WorkOrderCancelled` → `RELEASED`. Unconsumed holds are swept `EXPIRED` by
`sophix:stock:expire-reservations` (10 min). *Foundation: outbox listener + scheduled sweep.* *Proven
by `StockReservationTest`.*

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
A swap with `defectConfirmed` records a `vendor_rma_stub` (`PENDING_BATCH`) for the v1.0 batch handoff
(a real vendor-RMA integration is a connector).

## 2. Data model — ≥4 sample rows + readings

### `equipment_sku` (PLM-CFG-06) · `category`: `ROUTER|ONT|STB|SPLITTER|CABLE|WALL_SOCKET|MOUNT_KIT|SMARTCARD`
```json
{ "sku_id":"WIK-ONT-HUAWEI-EG8145V5","category":"ONT","is_serialized":true,"deposit_amount":5000,"warranty_days":365,"active":true }
{ "sku_id":"WIK-STB-4K","category":"STB","is_serialized":true,"deposit_amount":3000,"warranty_days":365,"active":true }
{ "sku_id":"WIK-CABLE-CAT6","category":"CABLE","is_serialized":false,"deposit_amount":0,"warranty_days":0,"active":true }
{ "sku_id":"WIK-ONT-OLD","category":"ONT","is_serialized":true,"deposit_amount":4000,"active":false }
```
**Reading:** `is_serialized` decides whether each unit is tracked as an `equipment_instance` (ONT/STB)
or only as bulk `stock_balance` (cable). `deposit_amount` is what an EQR forfeiture or out-of-warranty
swap charges. `active:false` = retired SKU (no new stock).

### `equipment_instance` · `state`: `IN_MAIN_WAREHOUSE|IN_CONTRACTOR_STOCK|RESERVED_FOR_WO|IN_FIELD_ACTIVE|IN_FIELD_DEFECTIVE|RECOVERED_BY_CONTRACTOR|RETURNED|FAULTY|RETIRED`
```json
{ "instance_id":"eqi_1","sku_id":"WIK-ONT-HUAWEI-EG8145V5","serial":"SN-001","state":"IN_MAIN_WAREHOUSE","location_id":"WIK-WAREHOUSE-MAIN" }
{ "instance_id":"eqi_2","sku_id":"WIK-ONT-HUAWEI-EG8145V5","serial":"SN-002","state":"IN_CONTRACTOR_STOCK","location_id":"WIK-VAN-ctr_9" }
{ "instance_id":"eqi_3","sku_id":"WIK-STB-4K","serial":"SN-100","state":"IN_FIELD_ACTIVE","customer_id":"cust_1","subscription_id":"sub_1" }
{ "instance_id":"eqi_4","sku_id":"WIK-ONT-OLD","serial":"SN-900","state":"IN_FIELD_DEFECTIVE","customer_id":"cust_2" }
```
**Reading:** the state is *where the unit physically is + its condition*. eqi_1 is sellable warehouse
stock; eqi_2 is on a contractor van; eqi_3 is installed at a customer (bound to a subscription); eqi_4
is defective in the field (a swap candidate). A swap moves a source instance through
`RESERVED_FOR_WO → RECOVERED_BY_CONTRACTOR` (or stays in field on EQR).

### `stock_location` (`type`: `WAREHOUSE|CONTRACTOR_VAN`) & `stock_balance`
```json
{ "location_id":"WIK-WAREHOUSE-MAIN","type":"WAREHOUSE","active":true }
{ "location_id":"WIK-VAN-ctr_9","type":"CONTRACTOR_VAN","contractor_id":"ctr_9","active":true }
{ "location_id":"WIK-WAREHOUSE-MSA","type":"WAREHOUSE","active":true }
{ "balance":{ "location_id":"WIK-WAREHOUSE-MAIN","sku_id":"WIK-CABLE-CAT6","quantity":4200 } }
```
**Reading:** stock lives at locations; a `CONTRACTOR_VAN` is a contractor's rolling stock (keyed to
`contractor_id`). `stock_balance` is on-hand per (location, sku) for **non-serialized** SKUs (serialized
units are counted by their instances).

### `stock_movement` · `reason_code` (catalog `stock_reason_code`: `direction` IN/OUT, `requires_approval`)
```json
{ "id":"sm_1","sku_id":"WIK-CABLE-CAT6","location_id":"WIK-WAREHOUSE-MAIN","reason_code":"GOODS_RECEIPT","quantity":5000,"reference":"po_1" }
{ "id":"sm_2","sku_id":"WIK-CABLE-CAT6","location_id":"WIK-VAN-ctr_9","reason_code":"ISSUE","quantity":-50,"reference":"transfer_7" }
{ "id":"sm_3","sku_id":"WIK-CABLE-CAT6","location_id":"WIK-WAREHOUSE-MAIN","reason_code":"WRITE_OFF","quantity":-10,"reference":"appr_55" }
{ "id":"sm_4","sku_id":"WIK-CABLE-CAT6","location_id":"WIK-WAREHOUSE-MSA","reason_code":"INVENTORY_AUDIT_ADJUSTMENT","quantity":-3,"reference":"count_2" }
```
**Reading:** movements are the **immutable ledger**; the `reason_code` (from the operator catalog)
fixes the sign/direction and whether approval was needed. sm_1 is a goods receipt (+), sm_2 a transfer
to a van (−), sm_3 an **approval-gated** write-off (its `reference` is the approval id), sm_4 the single
correction a reconcile posts.

### `stock_reservation` · `status`: `ACTIVE|CONSUMED|RELEASED|EXPIRED`
```json
{ "id":"sr_1","sku_id":"WIK-ONT-HUAWEI-EG8145V5","location_id":"WIK-WAREHOUSE-MAIN","wo_id":"wo_1","quantity":1,"status":"ACTIVE" }
{ "id":"sr_2","sku_id":"WIK-STB-4K","wo_id":"wo_1","quantity":1,"status":"CONSUMED" }
{ "id":"sr_3","sku_id":"WIK-ONT-HUAWEI-EG8145V5","wo_id":"wo_5","quantity":1,"status":"RELEASED" }
{ "id":"sr_4","sku_id":"WIK-STB-4K","wo_id":"wo_8","quantity":1,"status":"EXPIRED" }
```
**Reading:** a reservation holds stock for a WO. `ACTIVE` (held) → `CONSUMED` (WO finalized) or
`RELEASED` (WO cancelled) via the lifecycle listener; an un-actioned hold is swept `EXPIRED`. This is
how install stock is promised without double-allocating.

### `purchase_order` · `status`: `DRAFT|PENDING_APPROVAL|APPROVED|PARTIALLY_RECEIVED|RECEIVED|CANCELLED|REJECTED`
```json
{ "po_id":"po_1","supplier":"Huawei","location_id":"WIK-WAREHOUSE-MAIN","status":"RECEIVED","total_value":150000 }
{ "po_id":"po_2","supplier":"Casa","status":"PENDING_APPROVAL","total_value":900000,"approval_request_id":"appr_70" }
{ "po_id":"po_3","supplier":"Local","status":"APPROVED","total_value":20000 }
{ "po_id":"po_4","supplier":"X","status":"PARTIALLY_RECEIVED","total_value":50000 }
```
**Reading:** po_2 (big) is parked on an EM-CFG-04 approval (`approval_request_id` set). po_1 is fully
received (stock posted + serials registered). po_4 had a partial delivery (more outstanding). A
`receive` is only allowed from `APPROVED`/`PARTIALLY_RECEIVED`.

### `equipment_swap_request` · `kind`: `SWAP_HFC|SWAP_GPON|EQP|EQU` · `status`: `CREATED|AWAITING_SLOT|WO_CREATED|FIELD_VISIT_IN_PROGRESS|SOURCE_RECOVERED|COMPLETED|COMPLETED_WITHOUT_RECOVERY|FAILED`
```json
{ "swap_id":"swp_1","kind":"SWAP_GPON","status":"COMPLETED","chargeable":false,"source_instance_id":"eqi_4" }
{ "swap_id":"swp_2","kind":"EQU","status":"COMPLETED","chargeable":true,"charge_code":"UPGRADE_FEE","charge_amount":5000 }
{ "swap_id":"swp_3","kind":"EQP","status":"COMPLETED_WITHOUT_RECOVERY","chargeable":true,"charge_code":"DEPOSIT_FORFEITURE","charge_amount":3000 }
{ "swap_id":"swp_4","kind":"SWAP_HFC","status":"FIELD_VISIT_IN_PROGRESS","chargeable":false }
```
**Reading:** `kind` selects the flow (GPON/HFC defective swap, EQP pickup, EQU upgrade). swp_1 was a
free warranty swap; swp_2 an upgrade (charged via BIL-01); swp_3 an EQR forfeiture (deposit billed);
swp_4 is mid field-visit. The status is the swap workflow's progress.

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
`/api/swap-requests`. `permission:stock.manage` (+ `idempotency` on creates/movements).

## 5. Integration (events) — topic `osr.equipment`
- **Emits:** `StockMoved`, `StockReservation{Reserved,Consumed,Released,Expired}`,
  `EquipmentInstance{Registered,StateChanged,RecoveredByContractor,BoundToCustomer,Decommissioned}`,
  `EquipmentSwap{Requested,Rejected,Completed}` (Completed carries `depositForfeited`+amount).
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
