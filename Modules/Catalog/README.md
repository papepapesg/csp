# Catalog

Core product-lifecycle capability for services, packages, versions, commercial bundles, availability, launch and retirement.

## Use

Manage products through `routes/api.php`; consumers use available-package/bundle read models rather than reading draft records. Operators can inspect launch plans through the catalog operations commands.

## Configure

Products, versions, availability scopes and migration rules are catalogs. Launch approval/readiness policies are configuration. Network, rating, discount and tax catalogs live in their satellite modules.

## Extend

Prefer new catalog records and validation rules. Add code only for new executable semantics, with a registered behavior key, events, migration and tests. Preserve effective dates so historical orders remain reproducible.

## Exposed APIs

- `GET commercial-bundles`
- `GET commercial-bundles/available`
- `GET commercial-bundles/{bundle}`
- `GET config/{catalog}`
- `GET package-launch-plans`
- `GET package-launch-plans/{launchPlan}`
- `GET packages`
- `GET packages/available`
- `GET packages/{package}`
- `GET service-classes`
- `GET service-classes/{serviceClass}`
- `GET services`
- `GET services/{service}`
- `GET tech-contractor-skills`
- `GET tech-contractors`
- `PATCH packages/{package}`
- `PATCH services/{service}`
- `POST commercial-bundles`
- `POST commercial-bundles/migration-preview`
- `POST commercial-bundles/{bundle}/activate`
- `POST commercial-bundles/{bundle}/approve`
- `POST commercial-bundles/{bundle}/migration-rules`
- `POST commercial-bundles/{bundle}/retire`
- `POST commercial-bundles/{bundle}/submit-review`
- `POST commercial-bundles/{bundle}/validate`
- `POST config/{catalog}`
- `POST package-launch-plans`
- `POST package-launch-plans/run-due`
- `POST package-launch-plans/{launchPlan}/activate`
- `POST package-launch-plans/{launchPlan}/approval-outcome`
- `POST package-launch-plans/{launchPlan}/submit-review`
- `POST package-launch-plans/{launchPlan}/validate`
- `POST package-retirement-plans`
- `POST packages`
- `POST packages/{package}/activate`
- `POST packages/{package}/availability/resume`
- `POST packages/{package}/availability/suspend`
- `POST packages/{package}/versions`
- `POST service-classes`
- `POST services`
- `POST tech-contractor-skills`
- `POST tech-contractors`
- `POST tech-contractors/{techContractor}/retire`

## Data models

- `AdjustmentType`
- `BundleAvailability`
- `BundleComponent`
- `BundleDiscountRule`
- `BundleLaunchCheck`
- `BundleMigrationRule`
- `CommercialBundle`
- `Package`
- `PackageAvailability`
- `PackageLaunchCheck`
- `PackageLaunchPlan`
- `PackageLifecycleEvent`
- `PackageRetirementPlan`
- `PackageService`
- `PackageVersion`
- `PackageVersionCutover`
- `Service`
- `ServiceClass`

## Services

- `BundleService`
- `CatalogPolicy`
- `CatalogService`
- `PackageLaunchService`

## Events

- `ApplyHomePassTransitionOnApproval`
- `ApplyPackageLaunchApproval`
- `CatalogCacheInvalidator`
- `CatalogEvents`
- `CatalogEvents::BUNDLE_ACTIVATED`
- `CatalogEvents::BUNDLE_APPROVED`
- `CatalogEvents::BUNDLE_CREATED`
- `CatalogEvents::BUNDLE_RETIRED`
- `CatalogEvents::BUNDLE_VALIDATED`
- `CatalogEvents::HOMEPASS_ADDRESS_CORRECTED`
- `CatalogEvents::HOMEPASS_CREATED`
- `CatalogEvents::HOMEPASS_REACHED_SELLABLE`
- `CatalogEvents::HOMEPASS_STATUS_CHANGED`
- `CatalogEvents::PACKAGE_ACTIVATED`
- `CatalogEvents::PACKAGE_AVAILABILITY_CHANGED`
- `CatalogEvents::PACKAGE_CREATED`
- `CatalogEvents::PACKAGE_END_OF_SALE`
- `CatalogEvents::PACKAGE_LAUNCH_APPROVAL_REQUIRED`
- `CatalogEvents::PACKAGE_LAUNCH_APPROVED`
- `CatalogEvents::PACKAGE_LAUNCH_PLAN_CREATED`
- `CatalogEvents::PACKAGE_LAUNCH_REJECTED`
- `CatalogEvents::PACKAGE_LAUNCH_VALIDATED`
- `CatalogEvents::PACKAGE_RETIRED`
- `CatalogEvents::PACKAGE_VERSION_ADDED`
- `CatalogEvents::SERVICE_CREATED`
- `CatalogEvents::TECH_REGION_CREATED`
- `CatalogEvents::TOPIC`
- `CatalogEvents::VOICE_DESTINATION_PREFIX_CHANGED`
- `CatalogEvents::VOICE_TARIFF_BINDING_CHANGED`
- `CatalogEvents::VOICE_TARIFF_PLAN_ACTIVATED`
- `CatalogEvents::VOICE_TARIFF_PLAN_RETIRED`
- `CatalogEvents::VOICE_TARIFF_RATE_CHANGED`

## Commands

- `sophix:catalog:launch-activate-due`
- `sophix:catalog:launch-show`
- `sophix:catalog:launch-status`

## Test

Product, bundle, launch and shared catalog API tests are in `tests/Feature`.
