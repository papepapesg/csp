# Catalog

Core product-lifecycle capability for services, packages, versions, commercial bundles, availability, launch and retirement.

## Use

Manage products through `routes/api.php`; consumers use available-package/bundle read models rather than reading draft records. Operators can inspect launch plans through the catalog operations commands.

## Configure

Products, versions, availability scopes and migration rules are catalogs. Launch approval/readiness policies are configuration. Network, rating, discount and tax catalogs live in their satellite modules.

## Extend

Prefer new catalog records and validation rules. Add code only for new executable semantics, with a registered behavior key, events, migration and tests. Preserve effective dates so historical orders remain reproducible.

## Test

Product, bundle, launch and shared catalog API tests are in `tests/Feature`.
