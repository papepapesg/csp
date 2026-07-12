# ILM CVM

Customer-value management capability for signal profiles, segments, activities, offers, outcomes and automated flag evaluation.

## Use

Run `php artisan sophix:cvm:evaluate-flags --operator=<code>` to evaluate configured customer signals. Services create activities/offers and publish outcomes for downstream engagement.

## Configure

Segments, thresholds and offer policies belong in decision tables and catalogs. Signal profiles and outcomes are operational/analytical data.

## Extend

Add new signals through focused collectors and expose them as rule facts. Keep segmentation configurable; add code only for new actions, integrated through module APIs/events with idempotency tests.

## Exposed APIs

- `GET cvm-activities`
- `GET cvm/profiles/{customerId}`
- `POST cvm-activities`
- `POST cvm-activities/{cvmActivity}/close`
- `POST cvm-offers`
- `POST cvm-offers/{cvmOffer}/accept`
- `POST cvm-offers/{cvmOffer}/reject`
- `POST cvm/customers/{customerId}/evaluate`

## Data models

- `CvmActivity`
- `CvmOfferInstance`
- `CvmOutcome`
- `CvmSegmentMembership`
- `CvmSignalProfile`

## Services

- `CvmActivityService`
- `CvmEvaluationService`
- `CvmFlagEvaluatorService`
- `CvmOfferService`

## Events

- `CvmEvents`
- `CvmEvents::ACTIVITY_CLOSED`
- `CvmEvents::ACTIVITY_CREATED`
- `CvmEvents::CUSTOMER_EVALUATED`
- `CvmEvents::OFFER_ACCEPTED`
- `CvmEvents::OFFER_APPLIED`
- `CvmEvents::OFFER_PROPOSED`
- `CvmEvents::TOPIC`
- `ResumeCvmOfferOnApproval`

## Commands

- `sophix:cvm:evaluate-flags`

## Test

Module boot coverage is in `tests/Feature`; CVM behavior should be added here as the capability evolves.
