<?php

namespace Modules\Notification\Icn;

use Modules\Notification\Models\Icn\StaffNotificationDelivery;

/**
 * ICN-01 staff channel adapter contract (§7). One concrete implementation per provider
 * (smtp-classic, slack-bot-api, msteams-incoming-webhook, inapp-websocket-fanout, ...).
 * Selected per (operator, channel) by staff_notification_adapter_binding.adapter_impl.
 *
 * Adding a channel/provider = write the class + register its adapterImplCode() in config +
 * insert a binding row. Nothing else in ICN-01 changes (the binding pattern, §7.5).
 */
interface StaffChannelAdapter
{
    /** Matches staff_notification_adapter_binding.adapter_impl. */
    public function adapterImplCode(): string;

    /**
     * Initialise from the binding's config_jsonb (opaque; the adapter validates its own
     * shape). Secret references inside it are resolved here in a real deployment.
     *
     * @param array<string,mixed> $config
     */
    public function init(array $config): void;

    /** Transmit one delivery. Has no DB/event side effects — the dispatcher owns those. */
    public function dispatch(StaffNotificationDelivery $delivery, RenderedMessage $message, RecipientInfo $recipient): ChannelDispatchResult;

    /**
     * Optional: derive the channel identity from a directory profile (used by the identity
     * sync job). Returns null when this adapter doesn't need a per-user identity.
     *
     * @param array<string,mixed> $userProfile
     * @return array<string,mixed>|null
     */
    public function resolveIdentity(array $userProfile): ?array;
}
