# Catalog Discount

Owns discount definitions, assignments, promotional campaigns, eligibility, stacking and runtime discount computation.

## Use

Discount APIs are composed in `../Catalog/routes/api.php`. Create catalog items first, then governed assignments or campaigns. Review campaign queues with `php artisan sophix:catalog-discount:ops-status --operator=<code>`.

## Configure

Discount values, scopes, validity, priorities and stacking groups are catalogs. Approval thresholds and campaign policies are configuration; assignment history is operational ledger data.

## Extend

Express normal variants as catalog/config records. Add a registered strategy only when computation semantics change, and cover stacking, validity and approval behavior in local tests.

## Test

Module tests live in `tests/Feature`; shared historical discount tests also remain under `../Catalog/tests/Feature` during transition.
