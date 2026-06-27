<?php

namespace Modules\Notification\Icn\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Collection;
use Modules\Notification\Icn\RecipientInfo;
use Modules\Notification\Icn\StaffAdapterRegistry;
use Modules\Notification\Icn\StaffNotificationEvents;
use Modules\Notification\Models\Icn\StaffNotification;
use Modules\Notification\Models\Icn\StaffNotificationAdapterBinding;
use Modules\Notification\Models\Icn\StaffNotificationChannelConfig;
use Modules\Notification\Models\Icn\StaffNotificationDelivery;
use Modules\Notification\Models\Icn\StaffNotificationUserChannelIdentity;

/**
 * ICN-01 delivery worker (§7.0). Runs the 8-step resolution per delivery row and applies the
 * fallback-mode + failure policy (R-ICN-01-D-6/7/8). In this monolith the "async worker pool"
 * runs inline at ingress (and again from the retry sweep); the delivery rows are the durable
 * state, so a crash resumes safely on the next pass.
 */
class DeliveryDispatcher
{
    public function __construct(
        private readonly StaffAdapterRegistry $registry,
        private readonly TemplateRenderer $renderer,
        private readonly StaffGroupDirectory $directory,
        private readonly EventBus $events,
    ) {}

    /** Permanent per-recipient failures: no retry, straight to TERMINALLY_FAILED. */
    private const PERMANENT = ['BOUNCE', 'IDENTITY_UNRESOLVABLE', 'CHANNEL_NOT_MAPPED'];

    /** Process all eligible delivery rows of a notification under its fallback mode. */
    public function dispatchNotification(StaffNotification $notification): void
    {
        $cfg = StaffNotificationChannelConfig::forOperator($notification->operator_code);
        $mode = $notification->fallback_mode_override ?? ($cfg?->fallback_mode ?? StaffNotificationChannelConfig::PARALLEL);

        $rows = $notification->deliveries()->orderBy('recipient_user_id')->orderBy('channel_priority_idx')->get();
        $byRecipient = $rows->groupBy('recipient_user_id');

        foreach ($byRecipient as $recipientRows) {
            if ($mode === StaffNotificationChannelConfig::PARALLEL) {
                foreach ($recipientRows as $row) {
                    if ($this->isDue($row)) {
                        $this->dispatchDelivery($row, $notification);
                    }
                }

                continue;
            }

            // SEQUENTIAL_*: walk channels in priority order, dispatching the lowest-index
            // eligible row. UNTIL_DISPATCH keeps walking on terminal failure until one is
            // dispatched; once dispatched, the rest are suppressed.
            foreach ($recipientRows as $row) {
                $row->refresh();
                if (in_array($row->status, [StaffNotificationDelivery::DISPATCHED, StaffNotificationDelivery::ACKNOWLEDGED], true)) {
                    $this->suppressRemaining($recipientRows, $row->channel_priority_idx);
                    break;
                }
                if ($row->status === StaffNotificationDelivery::TERMINALLY_FAILED) {
                    continue; // fall through to next channel
                }
                if (! $this->isDue($row)) {
                    break; // waiting for its slot (UNTIL_ACK)
                }
                $this->dispatchDelivery($row, $notification);
                $row->refresh();
                if ($row->status === StaffNotificationDelivery::DISPATCHED) {
                    if ($mode === StaffNotificationChannelConfig::SEQUENTIAL_UNTIL_DISPATCH) {
                        $this->suppressRemaining($recipientRows, $row->channel_priority_idx);
                    }
                    break;
                }
                if ($mode === StaffNotificationChannelConfig::SEQUENTIAL_UNTIL_ACK) {
                    break; // dispatched-but-not-acked; later slot promotes the next channel
                }
                // UNTIL_DISPATCH: terminal/failed -> loop continues to the next channel
            }
        }

        $this->recomputeStatus($notification);
    }

    /** The 8-step single-row dispatch (§7.0). */
    public function dispatchDelivery(StaffNotificationDelivery $delivery, ?StaffNotification $notification = null): void
    {
        $notification ??= $delivery->notification;
        if ($notification->status === StaffNotification::ACKNOWLEDGED) {
            return; // task already in flight; don't pester
        }

        $binding = StaffNotificationAdapterBinding::resolve($notification->operator_code, $delivery->channel);
        if (! $binding) {
            $this->terminal($delivery, $notification, 'NO_BINDING_FOR_CHANNEL');

            return;
        }
        if (! $binding->enabled) {
            $this->suppress($delivery, 'ADAPTER_BINDING_DISABLED');

            return;
        }

        $adapter = $this->registry->forBinding($binding);
        if (! $adapter) {
            $this->terminal($delivery, $notification, 'ADAPTER_NOT_REGISTERED');

            return;
        }

        $message = $this->renderer->render($notification, $delivery->channel);
        if (! $message) {
            $this->terminal($delivery, $notification, 'TEMPLATE_NOT_FOUND');

            return;
        }

        // A DIRECT send carries its address on the delivery row; otherwise resolve the recipient's
        // staff channel-identity (falling back to the FOUNDATION_AUTH profile).
        if ($delivery->recipient_identity) {
            $recipient = new RecipientInfo($delivery->recipient_user_id, $delivery->recipient_identity, null);
        } else {
            $identity = StaffNotificationUserChannelIdentity::resolve($delivery->recipient_user_id, $delivery->channel);
            $recipient = new RecipientInfo(
                $delivery->recipient_user_id,
                $identity?->identity_jsonb,
                fn () => $this->directory->profile($delivery->recipient_user_id),
            );
        }

        $result = $adapter->dispatch($delivery, $message, $recipient);
        $delivery->attempts++;
        $delivery->last_attempt_at = now();
        $delivery->provider_response = $result->providerResponse ?: null;

        if ($result->success) {
            $delivery->status = StaffNotificationDelivery::DISPATCHED;
            $delivery->provider_message_id = $result->providerMessageId;
            $delivery->failure_reason = null;
            $delivery->next_retry_at = null;
            $delivery->save();
            $this->emit(StaffNotificationEvents::DELIVERED, $notification, ['deliveryId' => $delivery->delivery_id, 'channel' => $delivery->channel, 'recipient' => $delivery->recipient_user_id]);

            return;
        }

        $this->handleFailure($delivery, $notification, $result->failureReason ?? 'UNKNOWN');
    }

    private function handleFailure(StaffNotificationDelivery $delivery, StaffNotification $notification, string $reason): void
    {
        $delivery->failure_reason = $reason;

        // IN_APP_PUSH with no live session is not a channel failure — keep PENDING; the
        // expiry sweep SUPPRESSES it after the ack window (§7.4).
        if ($reason === 'NO_ACTIVE_SESSION') {
            $delivery->status = StaffNotificationDelivery::PENDING;
            $delivery->next_retry_at = now()->addSeconds($this->backoff($notification, $delivery->attempts));
            $delivery->save();

            return;
        }

        if (in_array($reason, self::PERMANENT, true)) {
            $this->terminal($delivery, $notification, $reason);

            return;
        }

        // Transient: retry with backoff up to retry_max_attempts, then TERMINALLY_FAILED.
        $cfg = StaffNotificationChannelConfig::forOperator($notification->operator_code);
        $max = $cfg?->retry_max_attempts ?? 3;
        if ($delivery->attempts >= $max) {
            $this->terminal($delivery, $notification, $reason);

            return;
        }
        $delivery->status = StaffNotificationDelivery::FAILED;
        $delivery->next_retry_at = now()->addSeconds($this->backoff($notification, $delivery->attempts));
        $delivery->save();
    }

    private function backoff(StaffNotification $notification, int $attempt): int
    {
        $cfg = StaffNotificationChannelConfig::forOperator($notification->operator_code);
        $schedule = $cfg?->retry_backoff_seconds ?: [30, 120, 600];

        return (int) ($schedule[$attempt - 1] ?? end($schedule));
    }

    private function terminal(StaffNotificationDelivery $delivery, StaffNotification $notification, string $reason): void
    {
        $delivery->status = StaffNotificationDelivery::TERMINALLY_FAILED;
        $delivery->failure_reason = $reason;
        $delivery->next_retry_at = null;
        $delivery->save();
        $this->emit(StaffNotificationEvents::DELIVERY_FAILED, $notification, ['deliveryId' => $delivery->delivery_id, 'channel' => $delivery->channel, 'recipient' => $delivery->recipient_user_id, 'reason' => $reason]);
    }

    private function suppress(StaffNotificationDelivery $delivery, string $reason): void
    {
        $delivery->status = StaffNotificationDelivery::SUPPRESSED;
        $delivery->failure_reason = $reason;
        $delivery->next_retry_at = null;
        $delivery->save();
    }

    /** @param Collection<int,StaffNotificationDelivery> $rows */
    private function suppressRemaining($rows, int $afterIdx): void
    {
        foreach ($rows as $row) {
            $row->refresh();
            if ($row->channel_priority_idx > $afterIdx && in_array($row->status, [StaffNotificationDelivery::PENDING, StaffNotificationDelivery::FAILED], true)) {
                $this->suppress($row, 'SUPERSEDED_BY_DISPATCH');
            }
        }
    }

    private function isDue(StaffNotificationDelivery $delivery): bool
    {
        if (! in_array($delivery->status, [StaffNotificationDelivery::PENDING, StaffNotificationDelivery::FAILED], true)) {
            return false;
        }

        return $delivery->next_retry_at === null || $delivery->next_retry_at->lessThanOrEqualTo(now());
    }

    /** Roll the notification status up from its deliveries (R-ICN-01-D-8). */
    private function recomputeStatus(StaffNotification $notification): void
    {
        $notification->refresh();
        if (in_array($notification->status, [StaffNotification::ACKNOWLEDGED, StaffNotification::EXPIRED], true)) {
            return;
        }
        $rows = $notification->deliveries()->get();
        if ($rows->contains(fn ($r) => $r->status === StaffNotificationDelivery::DISPATCHED)
            && $notification->status === StaffNotification::PROCESSING) {
            $notification->update(['status' => StaffNotification::DISPATCHED]);
        }

        // All channels for all recipients exhausted (terminal or suppressed) -> EXPIRED.
        $unresolved = $rows->contains(fn ($r) => in_array($r->status, [StaffNotificationDelivery::PENDING, StaffNotificationDelivery::FAILED, StaffNotificationDelivery::DISPATCHED, StaffNotificationDelivery::ACKNOWLEDGED], true));
        if ($rows->isNotEmpty() && ! $unresolved) {
            $notification->update(['status' => StaffNotification::EXPIRED, 'expiry_reason' => 'ALL_CHANNELS_EXHAUSTED']);
            $this->emit(StaffNotificationEvents::FAILED_TO_REACH_ANYONE, $notification, [
                'expectedRecipients' => $notification->expected_recipients,
                'deliveryAttempts' => (int) $rows->sum('attempts'),
            ]);
        }
    }

    /** @param array<string,mixed> $extra */
    private function emit(string $type, StaffNotification $notification, array $extra = []): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: StaffNotificationEvents::TOPIC,
            payload: ['notificationId' => $notification->notification_id, 'operatorCode' => $notification->operator_code, 'sourceModule' => $notification->source_module, 'candidateGroup' => $notification->candidate_group, 'templateCode' => $notification->template_code] + $extra,
            aggregateType: 'StaffNotification',
            aggregateId: $notification->notification_id,
        ));
    }
}
