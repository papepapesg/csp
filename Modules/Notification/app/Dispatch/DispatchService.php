<?php

namespace Modules\Notification\Dispatch;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Modules\Notification\Events\NotificationEvents;
use Modules\Notification\Models\NotificationDeliveryAttempt;
use Modules\Notification\Models\Template;
use Modules\Notification\Routing\ChannelDecision;

/**
 * NOT-01 Concern B — channel delivery + rule group F (failure handling). Sends one
 * Dispatch through its channel adapter, writes the notification_delivery_attempt row, and
 * applies the failure policy:
 *   TRANSIENT               -> PENDING_RETRY with exponential backoff (F-2)
 *   deferred (time-window)  -> PENDING_RETRY scheduled at the allowed time (R-4/P-5)
 *   PERMANENT_RECIPIENT     -> FAILED; orchestrator falls back to the next channel (F-3)
 *   PERMANENT_TEMPLATE      -> disable the template + escalate (F-4)
 *   PERMANENT_BUSINESS_RULE -> FAILED; no retry, no fallback
 */
class DispatchService
{
    public function __construct(
        private readonly ChannelAdapterRegistry $adapters,
        private readonly EventBus $events,
    ) {}

    /** Backoff schedule (seconds) for TRANSIENT retries; 7 retries then escalate (F-2). */
    private function backoff(): array
    {
        return config('sophix.notification.retry_backoff_seconds', [60, 300, 900, 1800, 3600, 7200, 14400]);
    }

    private function maxAttempts(): int
    {
        return count($this->backoff()) + 1;
    }

    /**
     * Dispatch one channel. A deferUntil on the decision queues the send for later instead
     * of sending now. Returns the recorded attempt.
     */
    public function dispatch(Dispatch $dispatch, ChannelDecision $decision, int $attemptNumber = 1): NotificationDeliveryAttempt
    {
        if ($decision->deferUntil !== null && $attemptNumber === 1) {
            return $this->record($dispatch, NotificationDeliveryAttempt::PENDING_RETRY, $attemptNumber, null, 'deferred to allowed window', null, [], $decision->deferUntil);
        }

        try {
            $adapter = $this->adapters->for($dispatch->operatorCode, $dispatch->channel);
        } catch (AdapterConfigurationException $e) {
            // Unconfigured/disabled channel is a permanent template/config failure.
            return $this->record($dispatch, NotificationDeliveryAttempt::FAILED, $attemptNumber, FailureCategory::PERMANENT_TEMPLATE->value, $e->getMessage(), null, [], null, $decision);
        }

        $timeout = (int) config('sophix.notification.send_timeout_seconds', 15);
        $result = $adapter->send($dispatch, $timeout);

        if ($result->sent) {
            return $this->record($dispatch, NotificationDeliveryAttempt::SENT, $attemptNumber, null, null, $result->externalReference, $result->channelResponse);
        }

        return $this->applyFailurePolicy($dispatch, $decision, $attemptNumber, $result);
    }

    /** Re-dispatch a PENDING_RETRY attempt (called by the retry scheduler). */
    public function retry(NotificationDeliveryAttempt $attempt): NotificationDeliveryAttempt
    {
        $ctx = $attempt->dispatch_context ?? [];
        $dispatch = new Dispatch(
            dispatchId: $ctx['dispatchId'] ?? \App\Foundation\Support\Id::make('dsp'),
            notificationId: $attempt->notification_id,
            operatorCode: $attempt->operator_code,
            customerId: $ctx['customerId'] ?? null,
            channel: $attempt->channel,
            recipient: $attempt->recipient,
            renderedArtifacts: $ctx['renderedArtifacts'] ?? [],
            pdfStorageKey: $ctx['pdfStorageKey'] ?? null,
            metadata: $ctx['metadata'] ?? [],
        );
        $decision = new ChannelDecision(
            channel: $attempt->channel,
            purposeCode: $ctx['purposeCode'] ?? '',
            priority: (int) ($ctx['priority'] ?? 1),
            urgency: $ctx['urgency'] ?? 'NORMAL',
            category: $ctx['category'] ?? 'TRANSACTIONAL',
        );

        return $this->dispatch($dispatch, $decision, $attempt->attempt_number + 1);
    }

    private function applyFailurePolicy(Dispatch $dispatch, ChannelDecision $decision, int $attemptNumber, DeliveryResult $result): NotificationDeliveryAttempt
    {
        $category = $result->category;

        if ($category === FailureCategory::TRANSIENT) {
            if ($attemptNumber >= $this->maxAttempts()) {
                $attempt = $this->record($dispatch, NotificationDeliveryAttempt::ESCALATED, $attemptNumber, $category->value, $result->failureDetail, null, $result->channelResponse, null, $decision);
                $this->emit(NotificationEvents::ESCALATED, $dispatch, ['channel' => $dispatch->channel, 'reason' => 'transient_retry_exhausted']);

                return $attempt;
            }
            $next = now()->addSeconds((int) ($this->backoff()[$attemptNumber - 1] ?? 60));

            return $this->record($dispatch, NotificationDeliveryAttempt::PENDING_RETRY, $attemptNumber, $category->value, $result->failureDetail, null, $result->channelResponse, $next, $decision);
        }

        if ($category === FailureCategory::PERMANENT_TEMPLATE) {
            $this->disableTemplate($dispatch);
            $attempt = $this->record($dispatch, NotificationDeliveryAttempt::FAILED, $attemptNumber, $category->value, $result->failureDetail, null, $result->channelResponse);
            $this->emit(NotificationEvents::ESCALATED, $dispatch, ['channel' => $dispatch->channel, 'reason' => 'template_rejected']);

            return $attempt;
        }

        // PERMANENT_RECIPIENT / PERMANENT_BUSINESS_RULE: terminal for this channel.
        return $this->record($dispatch, NotificationDeliveryAttempt::FAILED, $attemptNumber, $category?->value, $result->failureDetail, null, $result->channelResponse);
    }

    /** F-4: mark the offending template DISABLED so subsequent notifications skip it. */
    private function disableTemplate(Dispatch $dispatch): void
    {
        $templateId = $dispatch->metadata['templateIds'][$dispatch->channel] ?? null;
        if ($templateId) {
            Template::query()->whereKey($templateId)->update(['status' => Template::STATUS_DISABLED]);
        }
    }

    /** @param array<string,mixed> $channelResponse */
    private function record(Dispatch $dispatch, string $status, int $attemptNumber, ?string $category, ?string $detail, ?string $externalRef, array $channelResponse, ?\DateTimeInterface $nextAttemptAt = null, ?ChannelDecision $decision = null): NotificationDeliveryAttempt
    {
        return NotificationDeliveryAttempt::query()->create([
            'notification_id' => $dispatch->notificationId,
            'operator_code' => $dispatch->operatorCode,
            'channel' => $dispatch->channel,
            'template_id' => $dispatch->metadata['templateIds'][$dispatch->channel] ?? null,
            'recipient' => $dispatch->recipient,
            'attempt_number' => $attemptNumber,
            'status' => $status,
            'failure_category' => $category,
            'failure_detail' => $detail,
            'channel_response' => $channelResponse ?: null,
            'external_reference' => $externalRef,
            'dispatch_context' => $this->context($dispatch, $decision),
            'attempted_at' => now(),
            'next_attempt_at' => $nextAttemptAt,
        ]);
    }

    /** @return array<string,mixed> */
    private function context(Dispatch $dispatch, ?ChannelDecision $decision): array
    {
        return [
            'dispatchId' => $dispatch->dispatchId,
            'customerId' => $dispatch->customerId,
            'renderedArtifacts' => $dispatch->renderedArtifacts,
            'pdfStorageKey' => $dispatch->pdfStorageKey,
            'metadata' => $dispatch->metadata,
            'purposeCode' => $decision?->purposeCode,
            'priority' => $decision?->priority,
            'urgency' => $decision?->urgency,
            'category' => $decision?->category,
        ];
    }

    /** @param array<string,mixed> $extra */
    private function emit(string $type, Dispatch $dispatch, array $extra = []): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: NotificationEvents::TOPIC,
            payload: ['notificationId' => $dispatch->notificationId, 'customerId' => $dispatch->customerId] + $extra,
            aggregateType: 'NotificationLog',
            aggregateId: $dispatch->notificationId,
        ));
    }
}
