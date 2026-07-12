# Catalog Discount

Owns discount definitions, assignments, promotional campaigns, eligibility, stacking and runtime discount computation.

## Use

Discount APIs are composed in `../Catalog/routes/api.php`. Create catalog items first, then governed assignments or campaigns. Review campaign queues with `php artisan sophix:catalog-discount:ops-status --operator=<code>`.

## Configure

Discount values, scopes, validity, priorities and stacking groups are catalogs. Approval thresholds and campaign policies are configuration; assignment history is operational ledger data.

## Extend

Express normal variants as catalog/config records. Add a registered strategy only when computation semantics change, and cover stacking, validity and approval behavior in local tests.

## Exposed APIs

- `GET campaigns`
- `GET discount-assignments/effective`
- `GET discounts`
- `POST campaigns`
- `POST campaigns/{campaign}/activate`
- `POST campaigns/{campaign}/check-eligibility`
- `POST campaigns/{campaign}/end`
- `POST campaigns/{campaign}/participate`
- `POST campaigns/{campaign}/pause`
- `POST campaigns/{campaign}/validate`
- `POST discount-assignments`
- `POST discount-assignments/preview`
- `POST discount-assignments/{discountAssignment}/approval-outcome`
- `POST discount-assignments/{discountAssignment}/cancel`
- `POST discounts`
- `POST discounts/assign`
- `POST discounts/compute`

## Data models

- `CampaignChannel`
- `CampaignOffer`
- `CampaignParticipation`
- `CampaignTargetRule`
- `Discount`
- `DiscountAssignment`
- `DiscountAssignmentHistory`
- `PromoCampaign`

## Services

- `CampaignService`
- `DiscountAssignmentService`
- `DiscountComputeService`

## Events

- `CatalogEvents::CAMPAIGN_ACTIVATED`
- `CatalogEvents::CAMPAIGN_CREATED`
- `CatalogEvents::CAMPAIGN_REDEEMED`
- `CatalogEvents::DISCOUNT_ASSIGNMENT_ACTIVATED`
- `CatalogEvents::DISCOUNT_ASSIGNMENT_APPROVAL_REQUIRED`
- `CatalogEvents::DISCOUNT_ASSIGNMENT_CANCELLED`
- `CatalogEvents::DISCOUNT_ASSIGNMENT_CREATED`
- `CatalogEvents::DISCOUNT_ASSIGNMENT_EXPIRED`
- `CatalogEvents::DISCOUNT_ASSIGNMENT_REJECTED`
- `CatalogEvents::TOPIC`

## Commands

- `sophix:catalog-discount:ops-status`

## Test

Module tests live in `tests/Feature`; shared historical discount tests also remain under `../Catalog/tests/Feature` during transition.
