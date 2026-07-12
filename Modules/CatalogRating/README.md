# Catalog Rating

Owns usage and voice tariff catalogs, bindings, destination resolution, cached rate lookup and executable call-rating policy.

## Use

Rating APIs are composed in `../Catalog/routes/api.php`. Publish active plans/rates before mediation consumes them. Check activation and expiry queues with `sophix:catalog-rating:ops-status`.

## Configure

Plans, rates, zones, prefixes, time bands, allowances and bindings are effective-dated catalogs. Only registered executable semantics should influence rating control flow.

## Extend

Keep catalog writes in `VoiceTariffService`, resolution in `VoiceTariffResolver`, and charging arithmetic in focused raters. Add a strategy for new unit/pulse behavior and test overlap, precedence and historical lookup.

## Test

Local boot tests are in `tests/Feature`; detailed rating scenarios also remain under `../Catalog/tests/Feature` during transition.
