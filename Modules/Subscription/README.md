# Subscription

Owns the customer service lifecycle: activation, status transitions, restrictions, pause/resume, suspension, termination, upgrade, relocation and other governed operations.

## Use

Use `routes/api.php` to create/query subscriptions and initiate operations. `OperationFramework` serializes state-changing work, enforces timeouts and coordinates workflow, billing, work orders and provisioning. Operations use `sophix:subscription:*` commands.

## Configure

Status catalogs, transition maps, per-operation policy, timeouts and restrictions are configuration. Subscriptions and operations are state; transition histories are ledgers.

## Extend

Add an operation as a focused workflow/handler set using stable module contracts. Configure eligibility and transitions as data, keep external effects idempotent, provide compensation, and test concurrent/in-flight behavior.

## Exposed APIs

- `DELETE subscriptions/{subscription}/restrictions/{code}`
- `GET subscription-operations/{operation}`
- `GET subscription-restrictions`
- `GET subscriptions`
- `GET subscriptions/{subscription}`
- `GET subscriptions/{subscription}/in-flight-operation`
- `GET subscriptions/{subscription}/operations`
- `GET subscriptions/{subscription}/operations/{operation}`
- `GET subscriptions/{subscription}/restrictions`
- `POST subscription-operations/{operation}/cancel`
- `POST subscriptions`
- `POST subscriptions/{subscription}/activate`
- `POST subscriptions/{subscription}/downgrade`
- `POST subscriptions/{subscription}/migrate`
- `POST subscriptions/{subscription}/pause`
- `POST subscriptions/{subscription}/relocate`
- `POST subscriptions/{subscription}/restrictions`
- `POST subscriptions/{subscription}/resume`
- `POST subscriptions/{subscription}/suspend-np`
- `POST subscriptions/{subscription}/terminate`
- `POST subscriptions/{subscription}/upgrade`

## Data models

- `Subscription`
- `SubscriptionOperation`
- `SubscriptionOperationConfig`
- `SubscriptionPauseConfig`
- `SubscriptionPauseHistory`
- `SubscriptionRestrictConfig`
- `SubscriptionRestriction`
- `SubscriptionStatusCode`
- `SubscriptionSuspendNpConfig`
- `SubscriptionTransitionReason`
- `SubscriptionUpgradeConfig`

## Services

- `OperationFramework`
- `RestrictionService`
- `SubscriptionService`

## Events

- `ConfirmBillingIntentOnPayment`
- `ConfirmPrepaidIntentOnTopup`
- `SubscriptionEvents`
- `SubscriptionEvents::ACTIVATED`
- `SubscriptionEvents::CREATED`
- `SubscriptionEvents::OPERATION_CANCELLED`
- `SubscriptionEvents::OPERATION_COMPLETED`
- `SubscriptionEvents::OPERATION_FAILED`
- `SubscriptionEvents::OPERATION_STARTED`
- `SubscriptionEvents::PAUSED`
- `SubscriptionEvents::RELOCATED`
- `SubscriptionEvents::RESTRICTION_ADDED`
- `SubscriptionEvents::RESTRICTION_REMOVED`
- `SubscriptionEvents::RESUMED`
- `SubscriptionEvents::STATUS_CHANGED`
- `SubscriptionEvents::SUSPENDED`
- `SubscriptionEvents::SUSPENDED_NON_PAYMENT`
- `SubscriptionEvents::TERMINATED`
- `SubscriptionEvents::TOPIC`
- `SubscriptionEvents::UPGRADED`

## Commands

- `sophix:subscription:operation-cancel`
- `sophix:subscription:operation-show`
- `sophix:subscription:operation-timeouts`
- `sophix:subscription:ops-status`

## Test

Lifecycle, restriction and operation-specific scenarios are in `tests/Feature`.
