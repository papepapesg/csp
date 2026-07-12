# OSR

Core equipment and stock capability for SKU catalogs, serialized instances, locations, balances, movements, reservations, reasons and bills of material.

## Use

Use `routes/api.php` for stock and equipment operations. Review stock exceptions with `sophix:stock:ops-status`; scheduled expiry releases stale reservations.

## Configure

Equipment/SKU types, serialization, ownership, movement reasons, locations and BOMs are catalogs/configuration. Movements are an immutable stock ledger; balances are controlled projections.

## Extend

Represent country and technology variation through SKU attributes and catalogs. Add movement behavior through services with approval, idempotency and non-negative-stock safeguards. Procurement and swaps belong to their satellites.

## Test

Stock, reservation, rules, BOM and API scenarios are in `tests/Feature`.
