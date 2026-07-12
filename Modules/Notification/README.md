# Notification

Customer-notification capability for templates, rendering, channel dispatch, delivery attempts, retries and delivery history.

## Use

Publish notification requests through module services/events and manage templates through `routes/api.php`. Use `sophix:notification:*` commands to inspect and safely retry render or delivery failures.

## Configure

Templates, locales, channel policies, retry/backoff and operator channel bindings are catalogs/configuration. Delivery logs and attempts are operational history.

## Extend

Implement a channel adapter and register it by configuration key. Keep rendering separate from transport, make provider callbacks idempotent, redact sensitive payloads, and add contract plus retry tests.

## Exposed APIs

- `GET admin/notifications/dashboard`
- `GET admin/notifications/failure-queue`
- `GET admin/templates`
- `GET internal-messages`
- `GET invoice-templates`
- `GET notification-templates`
- `GET notifications`
- `GET notifications/preferences`
- `GET notifications/{notification}`
- `PATCH invoice-templates/{invoiceTemplate}`
- `PATCH notification-templates/{template}`
- `POST admin/notifications/bounces`
- `POST admin/notifications/render-failures/{renderFailureQueue}/retry`
- `POST admin/notifications/routing/pause`
- `POST admin/notifications/send`
- `POST admin/notifications/{notificationLog}/resend`
- `POST admin/templates`
- `POST admin/templates/{template}/activate`
- `POST admin/templates/{template}/deactivate`
- `POST admin/templates/{template}/preview`
- `POST internal-messages`
- `POST invoice-templates`
- `POST notification-templates`
- `POST notification-templates/preview`
- `POST notification-templates/{template}/activate`
- `POST notifications`
- `PUT notifications/preferences`

## Data models

- `ChannelOperatorConfig`
- `CustomerNotificationPreference`
- `InternalMessage`
- `InvoiceTemplate`
- `Notification`
- `NotificationDeliveryAttempt`
- `NotificationLog`
- `NotificationRoutingRule`
- `NotificationTemplate`
- `RenderFailureQueue`
- `RenderedArtifact`
- `Template`

## Services

- `BounceService`
- `NotificationOrchestrator`
- `NotificationService`
- `RenderRetryService`
- `RetryScheduler`
- `TemplateService`

## Events

- `AccountStatusNotificationBridge`
- `DunningNotificationBridge`
- `NotificationEvents`
- `NotificationEvents::BOUNCE_PROCESSED`
- `NotificationEvents::DISPATCHED`
- `NotificationEvents::ESCALATED`
- `NotificationEvents::FAILED`
- `NotificationEvents::INTERNAL_POSTED`
- `NotificationEvents::PDF_READY`
- `NotificationEvents::QUEUED`
- `NotificationEvents::RENDER_FAILED`
- `NotificationEvents::SENT`
- `NotificationEvents::SUPPRESSED`
- `NotificationEvents::TOPIC`
- `NotificationEvents::UNDELIVERABLE`
- `NotifyApproversOnApprovalRequested`

## Commands

- `sophix:icn:expire`
- `sophix:icn:retry`
- `sophix:notification:delivery-show`
- `sophix:notification:ops-status`
- `sophix:notification:retry-dispatch`
- `sophix:notification:retry-fix`
- `sophix:notification:retry-render`

## Test

Pipeline, template, delivery and ICN compatibility scenarios are in `tests/Feature`.
