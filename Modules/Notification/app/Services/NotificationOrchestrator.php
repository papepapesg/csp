<?php

namespace Modules\Notification\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Notification\Dispatch\Dispatch;
use Modules\Notification\Dispatch\DispatchService;
use Modules\Notification\Events\NotificationEvents;
use Modules\Notification\Models\CustomerNotificationPreference;
use Modules\Notification\Models\NotificationDeliveryAttempt;
use Modules\Notification\Models\NotificationLog;
use Modules\Notification\Models\Template;
use Modules\Notification\Rendering\ArtifactRenderingService;
use Modules\Notification\Routing\ChannelDecision;
use Modules\Notification\Routing\PreferenceFilterService;
use Modules\Notification\Routing\RegulatoryFilterService;
use Modules\Notification\Routing\RoutingResolverService;

/**
 * NOT-01 end-to-end pipeline (Concern C orchestration). Turns one source event into
 * rendered, dispatched, audited notifications:
 *
 *   idempotency (Redis/cache) -> route -> preference filter -> regulatory filter
 *     -> render PDF (if any channel needs it) -> render channel artifacts
 *     -> dispatch per channel -> write notification_log aggregate -> emit outcome event
 *
 * The recipient per channel is resolved from CRM (passed in via $contacts — NOT-01 reads
 * contact details, it does not own them).
 */
class NotificationOrchestrator
{
    public function __construct(
        private readonly RoutingResolverService $routing,
        private readonly PreferenceFilterService $preferences,
        private readonly RegulatoryFilterService $regulatory,
        private readonly ArtifactRenderingService $rendering,
        private readonly DispatchService $dispatch,
        private readonly EventBus $events,
    ) {}

    /**
     * Ingest one event. $contacts maps channel -> recipient (e.g. ['EMAIL'=>addr,'SMS'=>msisdn]).
     *
     * @param array<string,mixed> $payload
     * @param array{customerId?:?string, sourceEntityId?:?string, sourceEventId?:?string, contacts?:array<string,string>, manualResendBy?:?string, originalNotificationId?:?string, channels?:list<string>, pdfBucket?:string} $opts
     */
    public function ingest(string $eventType, string $operator, array $payload, array $opts = []): ?NotificationLog
    {
        Context::setOperatorCode($operator);
        $customerId = $opts['customerId'] ?? ($payload['customerId'] ?? null);
        $sourceEntityId = $opts['sourceEntityId'] ?? ($payload['sourceEntityId'] ?? Id::make('src'));
        $sourceEventId = $opts['sourceEventId'] ?? null;
        $contacts = $opts['contacts'] ?? ($payload['contacts'] ?? []);

        // Step 2 — idempotency: a source event is processed once.
        if ($sourceEventId) {
            $key = "notification:processed:{$sourceEventId}";
            if (! Cache::add($key, 1, now()->addHours(24))) {
                return null;
            }
        }

        // Step 3 — route.
        $decisions = $this->routing->resolve($eventType, $operator, $payload);
        if ($decisions === []) {
            return null; // R-6 internal-only event: no customer-facing notification
        }

        // Optional manual-send channel override (admin O-2).
        if (! empty($opts['channels'])) {
            $decisions = array_values(array_filter($decisions, fn (ChannelDecision $d) => in_array($d->channel, $opts['channels'], true)));
        }

        // Preference then regulatory filters.
        $decisions = $this->preferences->apply($customerId, $decisions);
        if ($decisions === []) {
            return $this->logSuppressed($operator, $customerId, $eventType, $sourceEventId, $sourceEntityId, $opts);
        }
        $decisions = $this->regulatory->apply($operator, $customerId, $decisions);

        $locale = $this->localeFor($customerId);

        return DB::transaction(function () use ($eventType, $operator, $customerId, $sourceEntityId, $sourceEventId, $contacts, $decisions, $locale, $payload, $opts) {
            $log = NotificationLog::query()->create([
                'operator_code' => $operator,
                'customer_id' => $customerId,
                'event_type' => $eventType,
                'source_event_id' => $sourceEventId,
                'source_entity_id' => $sourceEntityId,
                'channels_attempted' => [],
                'final_status' => NotificationLog::DISPATCHED,
                'dispatched_at' => now(),
                'manual_resend_by' => $opts['manualResendBy'] ?? null,
                'original_notification_id' => $opts['originalNotificationId'] ?? null,
            ]);

            $pdfCache = [];   // purpose => ['key'=>..]
            $attempted = [];
            foreach ($decisions as $decision) {
                $recipient = $contacts[$decision->channel] ?? null;
                if (! $recipient) {
                    continue; // no contact detail for this channel — nothing to dispatch
                }

                // Render PDF once per purpose if this channel needs it.
                $pdfKey = null;
                if ($decision->needsPdf) {
                    $pdfCache[$decision->purposeCode] ??= $this->rendering->renderPdf(
                        $operator, $decision->purposeCode, $locale, $payload, $eventType, $sourceEntityId, $opts['pdfBucket'] ?? 'invoices'
                    );
                    $pdfKey = $pdfCache[$decision->purposeCode]['key'] ?? null;
                }

                // Render this channel's text artifacts.
                $artifacts = [];
                $templateIds = [];
                $missing = false;
                foreach (Template::CHANNEL_FORMATS[$decision->channel] ?? [] as $format) {
                    $rendered = $this->rendering->renderText($operator, $format, $decision->purposeCode, $locale, $payload, $eventType, $sourceEntityId);
                    if ($rendered === null) {
                        // EMAIL_SUBJECT/HTML/TEXT all required for EMAIL; SMS_TEXT for SMS.
                        $missing = true;
                        break;
                    }
                    $artifacts[$format] = $rendered['content'];
                    $templateIds[$format] = $rendered['templateId'];
                }
                if ($missing) {
                    continue; // render failure already queued; skip this channel
                }

                $dispatch = new Dispatch(
                    dispatchId: Id::make('dsp'),
                    notificationId: $log->id,
                    operatorCode: $operator,
                    customerId: $customerId,
                    channel: $decision->channel,
                    recipient: $recipient,
                    renderedArtifacts: $artifacts,
                    pdfStorageKey: $pdfKey,
                    metadata: ['templateIds' => [$decision->channel => $templateIds[Template::CHANNEL_FORMATS[$decision->channel][0]] ?? null]],
                );
                $this->dispatch->dispatch($dispatch, $decision);
                $attempted[] = $decision->channel;
            }

            if ($attempted === []) {
                $log->update(['final_status' => NotificationLog::SUPPRESSED, 'channels_attempted' => []]);
                $this->emit(NotificationEvents::SUPPRESSED, $log);

                return $log->refresh();
            }

            $final = $this->recomputeFinalStatus($log->id);
            $log->update(['channels_attempted' => array_values(array_unique($attempted)), 'final_status' => $final]);
            $this->emit($this->eventForStatus($final), $log);

            return $log->refresh();
        });
    }

    /**
     * Recompute a notification_log's final_status from the latest attempt per channel.
     * Called after dispatch and again by the retry scheduler when attempts reach terminal.
     */
    public function recomputeFinalStatus(string $notificationId): string
    {
        $attempts = NotificationDeliveryAttempt::query()
            ->where('notification_id', $notificationId)
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('channel');

        if ($attempts->isEmpty()) {
            return NotificationLog::SUPPRESSED;
        }

        $sent = $pending = $escalated = $failed = 0;
        foreach ($attempts as $channelAttempts) {
            $latest = $channelAttempts->last();
            match ($latest->status) {
                NotificationDeliveryAttempt::SENT => $sent++,
                NotificationDeliveryAttempt::PENDING_RETRY => $pending++,
                NotificationDeliveryAttempt::ESCALATED => $escalated++,
                default => $failed++,
            };
        }

        if ($sent > 0) {
            return ($pending + $escalated + $failed) === 0 ? NotificationLog::DISPATCHED : NotificationLog::PARTIALLY_DISPATCHED;
        }
        if ($escalated > 0) {
            return NotificationLog::ESCALATED;
        }
        if ($pending > 0) {
            return NotificationLog::PARTIALLY_DISPATCHED; // in-flight; resolves on retry
        }

        return NotificationLog::UNDELIVERABLE;
    }

    /** Recompute and persist a notification_log's final_status (used after retries). */
    public function recomputeAndPersist(string $notificationId): void
    {
        $log = NotificationLog::query()->whereKey($notificationId)->first();
        if (! $log) {
            return;
        }
        $final = $this->recomputeFinalStatus($notificationId);
        if ($log->final_status !== $final) {
            $log->update(['final_status' => $final]);
            $this->emit($this->eventForStatus($final), $log);
        }
    }

    private function localeFor(?string $customerId): string
    {
        $pref = $customerId ? CustomerNotificationPreference::forCustomer($customerId) : null;

        return $pref->locale ?? config('sophix.notification.default_locale', 'en');
    }

    /** @param array<string,mixed> $opts */
    private function logSuppressed(string $operator, ?string $customerId, string $eventType, ?string $sourceEventId, string $sourceEntityId, array $opts): NotificationLog
    {
        $log = NotificationLog::query()->create([
            'operator_code' => $operator,
            'customer_id' => $customerId,
            'event_type' => $eventType,
            'source_event_id' => $sourceEventId,
            'source_entity_id' => $sourceEntityId,
            'channels_attempted' => [],
            'final_status' => NotificationLog::SUPPRESSED,
            'dispatched_at' => now(),
            'manual_resend_by' => $opts['manualResendBy'] ?? null,
        ]);
        $this->emit(NotificationEvents::SUPPRESSED, $log);

        return $log;
    }

    private function eventForStatus(string $status): string
    {
        return match ($status) {
            NotificationLog::SUPPRESSED => NotificationEvents::SUPPRESSED,
            NotificationLog::ESCALATED => NotificationEvents::ESCALATED,
            NotificationLog::UNDELIVERABLE => NotificationEvents::UNDELIVERABLE,
            default => NotificationEvents::DISPATCHED,
        };
    }

    private function emit(string $type, NotificationLog $log): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: NotificationEvents::TOPIC,
            payload: ['notificationId' => $log->id, 'customerId' => $log->customer_id, 'eventType' => $log->event_type, 'finalStatus' => $log->final_status, 'channels' => $log->channels_attempted],
            aggregateType: 'NotificationLog',
            aggregateId: $log->id,
        ));
    }
}
