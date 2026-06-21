# OSR — As-Built Design (Equipment & Stock)

> **Capability codes:** OSR-01 (stock chain), OSR-INSTANCE-01 (serialized instances), OSR-02
> (procurement), OSR-05 (inventory audit), OSR-RMA-01 (equipment swap/RMA), PLM-CFG-06 (SKUs) ·
> **Module path:** `Modules/Osr` · **Source-of-truth tests:** `Modules/Osr/tests/Feature/*`
> (OsrApi, ProcurementAudit, StockReason+Bom, StockReservation, StockRules, SwapRequest)

## 1. Purpose & boundaries
- **Owns:** the **stock chain** (locations, balances, movements, reservations), the **serialized
  equipment instance** registry, **procurement** (POs), **inventory audit** (counts), and the
  **equipment swap / RMA** workflow.
- **Does NOT own:** the field work (WorkOrder), the network (Provisioning), the money (Billing) — it
  calls them. Stock authority stays here.
- **Job:** authoritative equipment/stock state with governed movements, procurement, and swaps.

## 📖 Scenarios — read these first

### Scenario A — buy stock (governed) and receive it
1. **Create:** `POST /api/purchase-orders` (supplier, lines of SKU×qty). PO `DRAFT`.
2. **Approve:** `POST /api/purchase-orders/po_1/approve` opens an **EM-CFG-04** `PURCHASE_ORDER`
   request. With a policy it parks `PENDING_APPROVAL`; a **different** approver decides via
   `…/approvals/{req}/decide` (the requester can't self-approve — SoD). PO → `APPROVED`.
3. **Receive:** `…/po_1/receive` with serials → posts a `GOODS_RECEIPT` `stock_movement` (+qty) and
   registers each serial as an `equipment_instance` at the warehouse. PO → `RECEIVED`.
- **Proven by:** `ProcurementAuditTest`.

### Scenario B — customer refuses to return equipment (EQR → deposit forfeited)
1. A pickup swap runs; on the field visit the tech reports `recovered=false`.
2. `CompleteWithoutRecoveryHandler`: swap → `COMPLETED_WITHOUT_RECOVERY`, equipment stays in the
   field, and the **deposit is forfeited** — it resolves the unit's SKU `deposit_amount` and raises a
   `DEPOSIT_FORFEITURE` BIL-01 intent (so the operator actually collects it). Emits
   `EquipmentSwapCompleted{depositForfeited:true, depositForfeitureAmount}`.
- **Proven by:** `SwapRequestTest::test_eqr_customer_refuses_return_forfeits_deposit`.

### Scenario C — a write-off needs approval
- `POST /api/stock-movements` with reason `WRITE_OFF_DAMAGE` (catalog `requires_approval=true`) →
  `StockService::submit` holds it as an EM-CFG-04 request; the movement only posts once approved
  (a `RECEIPT` reason posts immediately). On-hand can never go negative (R-OSR-SC-8).

## 2. Data model (selected)
| Table | Purpose | Invariants |
| --- | --- | --- |
| `equipment_sku` | PLM-CFG-06 catalog (serialized?, `deposit_amount`, warranty) | |
| `equipment_instance` (+ lifecycle) | per-serial unit + state machine (warehouse/contractor/field/…) | one customer binding |
| `stock_location` / `stock_balance` / `stock_movement` | the chain; movements are the ledger | **on-hand never negative** (R-OSR-SC-8); reason-coded |
| `stock_reservation` | a WO's hold on stock; ACTIVE → CONSUMED/RELEASED/EXPIRED | swept on expiry |
| `purchase_order(+line)` | OSR-02 procurement | approval via EM-CFG-04 (`approval_request_id`); receive → goods-receipt movement |
| `stock_count_session(+line)` | OSR-05 audit | reconcile posts one correction (idempotent) |
| `equipment_swap_request` | OSR-RMA-01 swap root | per-flow state machine |
| `vendor_rma_stub` | defective-unit handoff (v1.0 stub) | |

## 3. Services
| Service | Responsibility |
| --- | --- |
| `StockService` | movements (`submit()` gates write-offs through EM-CFG-04 by reason code; `move()` raw poster), reservations (reserve/consume/release), availability |
| `EquipmentInstanceService` | register + transition serialized instances |
| `ProcurementService` | PO create/**approve (EM-CFG-04 gated)**/receive (serial registration) |
| `InventoryAuditService` | open/count/reconcile stock counts |
| `SwapRequestService` | OSR-RMA-01 swap request + the workflow handoff |

## 4. API surface
`/api/equipment-skus`, `/api/stock-{locations,balances,movements,reservations}`,
`/api/equipment-instances`, `/api/purchase-orders` (+ `…/approvals/{req}/decide`), `/api/stock-counts`,
`/api/swap-requests`. Writes `permission:stock.manage` (+ `idempotency` on creates/movements).

## 5. Integration (events) — topic `osr.equipment`
- **Emits:** `StockMoved`, `StockReservation{Reserved,Consumed,Released,Expired}`,
  `EquipmentInstance{Registered,StateChanged,RecoveredByContractor,BoundToCustomer,Decommissioned}`,
  `EquipmentSwap{Requested,Rejected,Completed}` (Completed carries `depositForfeited` +
  `depositForfeitureAmount`).
- **Consumes:** `ConsumeReservationOnWoLifecycle` (WO finalize→consume / cancel→release reservation).
- **Downstream:** Billing (deposit forfeiture/charge), Provisioning (swap provision).

## 6. Processes (swap, as workflow)
- **Handlers:** `ValidateSwapEligibilityHandler`, `ReserveSlotHandler`, `CreateSwapWorkOrderHandler`,
  `RecoverSourceHandler`, `ProvisionSwapHandler`, `CompleteSwapHandler` (chargeable → BIL-01),
  `CompleteWithoutRecoveryHandler` (EQR → **forfeit deposit via BIL-01**), `FailSwapHandler`.

## 7. Policy & config
`stock_reason_code` (which movements need approval), SKU catalog, EM-CFG-04 definitions for
write-offs / POs. All per-operator data.

## 8. Cross-module dependencies
- **Calls →** WorkOrder (swap WO), Billing (`BillingIntentService` for swap charge / deposit
  forfeiture), Provisioning (swap provision), Foundation Approvals.
- **Reacts to →** WorkOrder lifecycle (reservation consume/release).

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| R-OSR-SC-8 | a movement may never drive on-hand negative | `StockService::move` |
| R-OSR-SC-9 | approval-required reasons gate through EM-CFG-04 | `StockService::submit` |
| R-OSR-02-04 | PO approval gated through EM-CFG-04 | `ProcurementService::approve` |
| R-OSR-02-07/08 | receipt posts a movement + registers serials | `ProcurementService::receive` |
| OSR-RMA EQR | refused return forfeits the deposit (billed via BIL-01) | `CompleteWithoutRecoveryHandler` |

## 10. Open items / deltas
- PO approval (EM-CFG-04) and EQR deposit-forfeiture billing were wired during hardening — previously
  a bare status flip / an unbilled forfeiture. Fixed.
- `vendor_rma_stub` is a v1.0 batch stub pending a real vendor-RMA integration (connector).
