# ILM CVM

Customer-value management capability for signal profiles, segments, activities, offers, outcomes and automated flag evaluation.

## Use

Run `php artisan sophix:cvm:evaluate-flags --operator=<code>` to evaluate configured customer signals. Services create activities/offers and publish outcomes for downstream engagement.

## Configure

Segments, thresholds and offer policies belong in decision tables and catalogs. Signal profiles and outcomes are operational/analytical data.

## Extend

Add new signals through focused collectors and expose them as rule facts. Keep segmentation configurable; add code only for new actions, integrated through module APIs/events with idempotency tests.

## Test

Module boot coverage is in `tests/Feature`; CVM behavior should be added here as the capability evolves.
