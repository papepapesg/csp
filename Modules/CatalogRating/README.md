# Catalog Rating

Owns usage and voice tariff catalogs, bindings, destination resolution, cached rate lookup and executable call-rating policy.

## Use

Rating APIs are composed in `../Catalog/routes/api.php`. Publish active plans/rates before mediation consumes them. Check activation and expiry queues with `sophix:catalog-rating:ops-status`.

## Configure

Plans, rates, zones, prefixes, time bands, allowances and bindings are effective-dated catalogs. Only registered executable semantics should influence rating control flow.

## Extend

Keep catalog writes in `VoiceTariffService`, resolution in `VoiceTariffResolver`, and charging arithmetic in focused raters. Add a strategy for new unit/pulse behavior and test overlap, precedence and historical lookup.

## Exposed APIs

- `GET plm/voice-destination-prefixes`
- `GET plm/voice-destination-zones`
- `GET plm/voice-tariff-allowances`
- `GET plm/voice-tariff-bindings`
- `GET plm/voice-tariff-plans`
- `GET plm/voice-tariff-plans/{tariffPlan}`
- `GET plm/voice-tariff-rates`
- `GET plm/voice-time-bands`
- `PATCH plm/voice-tariff-plans/{tariffPlan}`
- `POST plm/voice-destination-prefixes`
- `POST plm/voice-destination-prefixes/bulk-import`
- `POST plm/voice-destination-zones`
- `POST plm/voice-rating/lookup`
- `POST plm/voice-rating/rate`
- `POST plm/voice-tariff-allowances`
- `POST plm/voice-tariff-bindings`
- `POST plm/voice-tariff-plans`
- `POST plm/voice-tariff-plans/{tariffPlan}/activate`
- `POST plm/voice-tariff-plans/{tariffPlan}/retire`
- `POST plm/voice-tariff-rates`
- `POST plm/voice-tariff-rates/bulk-import`
- `POST plm/voice-tariff-rates/validate-overlap`
- `POST plm/voice-time-bands`

## Data models

- `UsageTariff`
- `VoiceDestinationPrefix`
- `VoiceDestinationZone`
- `VoiceTariff`
- `VoiceTariffAllowance`
- `VoiceTariffBinding`
- `VoiceTariffPlan`
- `VoiceTariffRate`
- `VoiceTimeBand`

## Services

- `UsageRatingService`
- `VoiceCallRater`
- `VoiceTariffResolver`
- `VoiceTariffService`

## Events

- `CatalogEvents::TOPIC`
- `CatalogEvents::VOICE_DESTINATION_PREFIX_CHANGED`
- `CatalogEvents::VOICE_TARIFF_BINDING_CHANGED`
- `CatalogEvents::VOICE_TARIFF_PLAN_ACTIVATED`
- `CatalogEvents::VOICE_TARIFF_PLAN_CREATED`
- `CatalogEvents::VOICE_TARIFF_PLAN_RETIRED`
- `CatalogEvents::VOICE_TARIFF_RATE_CHANGED`

## Commands

- `sophix:catalog-rating:ops-status`

## Test

Local boot tests are in `tests/Feature`; detailed rating scenarios also remain under `../Catalog/tests/Feature` during transition.
