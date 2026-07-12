# Notification ICN

Internal communications capability for staff-targeted notifications, acknowledgement windows, direct identity delivery, retry and expiry.

## Use

Create staff notifications through module services and process queues with `sophix:icn:retry` and `sophix:icn:expire`. Review outstanding acknowledgements using `sophix:icn:ops-status`.

## Configure

Audience resolution, channels, acknowledgement deadlines and retry policy are operator configuration. Notification and delivery records are operational history.

## Extend

Add audience resolvers or delivery adapters behind contracts; do not embed organization-specific recipients in code. Preserve acknowledgement idempotency and authorization boundaries.

## Exposed APIs

- `DELETE staff-notification-user-channel-identity/{userId}/{channel}`
- `GET icn/adapter-registry`
- `GET staff-groups/{group}/members`
- `GET staff-notification-adapter-bindings`
- `GET staff-notification-channel-config`
- `GET staff-notification-templates`
- `GET staff-notification-user-channel-identity/{userId}`
- `GET staff-notification-user-pref/{userId}`
- `GET staff-notifications`
- `GET staff-notifications/inbox`
- `GET staff-notifications/stats`
- `GET staff-notifications/{staffNotification}`
- `POST staff-notification-templates`
- `POST staff-notifications`
- `POST staff-notifications/{staffNotification}/ack`
- `PUT staff-notification-adapter-bindings/{operator}/{channel}`
- `PUT staff-notification-channel-config/{operator}`
- `PUT staff-notification-templates/{operator}/{code}/{channel}/disable`
- `PUT staff-notification-user-channel-identity/{userId}/{channel}`
- `PUT staff-notification-user-pref/{userId}`

## Data models

- `StaffGroupMembership`
- `StaffNotification`
- `StaffNotificationAdapterBinding`
- `StaffNotificationChannelConfig`
- `StaffNotificationDelivery`
- `StaffNotificationTemplate`
- `StaffNotificationUserChannelIdentity`
- `StaffNotificationUserPref`

## Services

- `AckService`
- `DeliveryDispatcher`
- `StaffGroupDirectory`
- `StaffNotificationService`
- `StaffNotificationSweeper`
- `TemplateRenderer`

## Events

- `StaffNotificationEvents::ACKNOWLEDGED`
- `StaffNotificationEvents::CREATED`
- `StaffNotificationEvents::DELIVERED`
- `StaffNotificationEvents::DELIVERY_FAILED`
- `StaffNotificationEvents::EXPIRED`
- `StaffNotificationEvents::FAILED_TO_REACH_ANYONE`
- `StaffNotificationEvents::TEMPLATE_RENDER_WARNING`
- `StaffNotificationEvents::TOPIC`

## Commands

- `sophix:icn:ops-status`

## Test

Module boot coverage is in `tests/Feature`; add local delivery and acknowledgement scenarios for new behavior.
