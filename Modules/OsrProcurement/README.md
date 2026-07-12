# OSR Procurement

Procurement lifecycle for suppliers, purchase orders, approvals, receipts and inventory-audit integration.

## Use

Create and approve purchase orders through module services/APIs, then receive items into OSR stock. Review pending and ageing orders with `sophix:procurement:ops-status`.

## Configure

Supplier references, purchasing thresholds, approval chains and receipt tolerances are catalogs/configuration. Purchase-order and receipt histories are operational records.

## Extend

Add ERP or supplier connectivity through adapters/events, not controller coupling. Keep receipt posting idempotent and delegate stock changes to OSR services.

## Exposed APIs

- `GET purchase-orders`
- `POST purchase-orders`
- `POST purchase-orders/approvals/{approvalRequest}/decide`
- `POST purchase-orders/{purchaseOrder}/approve`
- `POST purchase-orders/{purchaseOrder}/receive`
- `POST stock-counts`
- `POST stock-counts/{stockCountSession}/count`
- `POST stock-counts/{stockCountSession}/reconcile`

## Data models

- `PurchaseOrder`
- `PurchaseOrderLine`

## Services

- `ProcurementService`

## Events

- No module-specific event catalog or listener is currently registered.

## Commands

- `sophix:procurement:ops-status`

## Test

Module boot coverage is in `tests/Feature`; procurement and audit integration scenarios should be added locally for new behavior.
