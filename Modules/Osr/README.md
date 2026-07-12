# OSR

Core equipment and stock capability for SKU catalogs, serialized instances, locations, balances, movements, reservations, reasons and bills of material.

## Use

Use `routes/api.php` for stock and equipment operations. Review stock exceptions with `sophix:stock:ops-status`; scheduled expiry releases stale reservations.

## Configure

Equipment/SKU types, serialization, ownership, movement reasons, locations and BOMs are catalogs/configuration. Movements are an immutable stock ledger; balances are controlled projections.

## Extend

Represent country and technology variation through SKU attributes and catalogs. Add movement behavior through services with approval, idempotency and non-negative-stock safeguards. Procurement and swaps belong to their satellites.

## Exposed APIs

- `GET equipment-instances`
- `GET equipment-instances/{equipmentInstance}`
- `GET equipment-skus`
- `GET stock-availability`
- `GET stock-balances`
- `GET stock-locations`
- `POST equipment-instances`
- `POST equipment-instances/{equipmentInstance}/transition`
- `POST equipment-skus`
- `POST stock-locations`
- `POST stock-movements`
- `POST stock-movements/approvals/{approvalRequest}/decide`
- `POST stock-reservations`
- `POST stock-reservations/{woId}/consume`
- `POST stock-reservations/{woId}/release`

## Data models

- `EquipmentInstance`
- `EquipmentInstanceLifecycleEvent`
- `EquipmentSku`
- `StockBalance`
- `StockCountLine`
- `StockCountSession`
- `StockLocation`
- `StockMovement`
- `StockReservation`

## Services

- `EquipmentInstanceService`
- `InventoryAuditService`
- `StockService`

## Events

- `ConsumeReservationOnWoLifecycle`
- `OsrEvents`
- `OsrEvents::INSTANCE_BOUND_TO_CUSTOMER`
- `OsrEvents::INSTANCE_DECOMMISSIONED`
- `OsrEvents::INSTANCE_RECOVERED_BY_CONTRACTOR`
- `OsrEvents::INSTANCE_REGISTERED`
- `OsrEvents::INSTANCE_STATE_CHANGED`
- `OsrEvents::INSTANCE_UNBOUND_FROM_CUSTOMER`
- `OsrEvents::STOCK_MOVED`
- `OsrEvents::STOCK_RESERVATION_CONSUMED`
- `OsrEvents::STOCK_RESERVATION_EXPIRED`
- `OsrEvents::STOCK_RESERVATION_RELEASED`
- `OsrEvents::STOCK_RESERVED`
- `OsrEvents::TOPIC`

## Commands

- `sophix:stock:expire-reservations`
- `sophix:stock:ops-status`
- `sophix:stock:show`

## Test

Stock, reservation, rules, BOM and API scenarios are in `tests/Feature`.
