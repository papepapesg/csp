<?php

namespace Modules\Notification\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Notification\Dispatch\Adapters\EmailAdapter;
use Modules\Notification\Dispatch\Adapters\SmsAdapter;
use Modules\Notification\Dispatch\AdapterConfigurationException;
use Modules\Notification\Dispatch\ChannelAdapter;
use Modules\Notification\Dispatch\DeliveryResult;
use Modules\Notification\Dispatch\Dispatch;
use Modules\Notification\Dispatch\FailureCategory;
use Modules\Notification\Events\NotificationEvents;
use Modules\Notification\Models\ChannelOperatorConfig;
use Modules\Notification\Models\InternalMessage;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\Template;

/**
 * NOT-01 imperative single-channel send (the legacy shim used by DunningService and the
 * workflow toolbox). Renders the body from a template_code, then dispatches through the
 * SAME pluggable ChannelAdapter providers the event-driven pipeline uses — so there is one
 * real delivery mechanism, not a hidden boolean stub. The DD's event-driven core is
 * NotificationOrchestrator; this method is the "send one message now" convenience.
 */
class NotificationService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly TemplateService $templates,
    ) {}

    /** @param array<string,mixed> $data */
    public function send(array $data): Notification
    {
        return DB::transaction(function () use ($data) {
            // Resolve the per-channel template for template_code when no inline body is
            // supplied; {{placeholders}} fill from payload (NOT-01 template studio).
            $subject = $data['subject'] ?? null;
            $body = $data['body'] ?? null;
            if (($body === null || $body === '') && ! empty($data['template_code'])) {
                $rendered = $this->templates->render($data['template_code'], $data['channel'], $data['payload'] ?? []);
                if ($rendered) {
                    $subject ??= $rendered['subject'];
                    $body = $rendered['body'];
                }
            }

            $notification = Notification::query()->create([
                'channel' => $data['channel'],
                'recipient' => $data['recipient'],
                'template_code' => $data['template_code'] ?? null,
                'subject' => $subject,
                'body' => $body,
                'payload' => $data['payload'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'status' => Notification::QUEUED,
            ]);

            $this->emit(NotificationEvents::QUEUED, $notification);

            // Hand to the real channel provider (EmailAdapter / SmsAdapter).
            $result = $this->dispatchToProvider($notification);

            $notification->update($result->sent
                ? ['status' => Notification::SENT, 'sent_at' => now()]
                : ['status' => Notification::FAILED, 'failure_reason' => $result->category?->value ?? 'failed']);

            $this->emit($result->sent ? NotificationEvents::SENT : NotificationEvents::FAILED, $notification);

            return $notification->refresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function postInternal(array $data): InternalMessage
    {
        $message = InternalMessage::query()->create($data);

        $this->events->publish(new DomainEvent(
            type: NotificationEvents::INTERNAL_POSTED,
            topic: NotificationEvents::TOPIC,
            payload: ['messageId' => $message->message_id, 'toGroup' => $message->to_group, 'toUserId' => $message->to_user_id],
            aggregateType: 'InternalMessage',
            aggregateId: $message->message_id,
        ));

        return $message;
    }

    /**
     * Dispatch through the channel's provider adapter and return its result. Resolves the
     * adapter for the channel, initializes it with the operator's channel_operator_config
     * (or a sensible default when the operator hasn't configured the channel yet), builds the
     * Dispatch the adapter expects, and calls send(). This is the concrete delivery path —
     * the adapter is the swappable SMTP / SMSC integration point.
     */
    private function dispatchToProvider(Notification $notification): DeliveryResult
    {
        $adapter = $this->providerFor($notification->channel);
        try {
            $adapter->initialize($this->channelConfig($notification->operator_code, $notification->channel));
        } catch (AdapterConfigurationException $e) {
            return DeliveryResult::failed(FailureCategory::PERMANENT_TEMPLATE, $e->getMessage());
        }

        $dispatch = new Dispatch(
            dispatchId: Id::make('dsp'),
            notificationId: $notification->notification_id,
            operatorCode: $notification->operator_code,
            customerId: $notification->customer_id,
            channel: $notification->channel,
            recipient: $notification->recipient,
            renderedArtifacts: $this->artifactsFor($notification),
            metadata: ['reference' => $notification->reference],
        );

        return $adapter->send($dispatch, (int) config('sophix.notification.send_timeout_seconds', 15));
    }

    /** The provider adapter for a channel. EMAIL -> EmailAdapter; everything text-shaped -> SmsAdapter. */
    private function providerFor(string $channel): ChannelAdapter
    {
        return $channel === 'EMAIL' ? new EmailAdapter() : new SmsAdapter();
    }

    /**
     * Map the flat subject/body onto the format-keyed artifact map the adapters read: EMAIL
     * needs subject + HTML + text; the text channels need a single body.
     *
     * @return array<string,string>
     */
    private function artifactsFor(Notification $notification): array
    {
        if ($notification->channel === 'EMAIL') {
            return [
                Template::FORMAT_EMAIL_SUBJECT => (string) ($notification->subject ?? ''),
                Template::FORMAT_EMAIL_HTML => (string) ($notification->body ?? ''),
                Template::FORMAT_EMAIL_TEXT => (string) ($notification->body ?? ''),
            ];
        }

        return [Template::FORMAT_SMS_TEXT => (string) ($notification->body ?? '')];
    }

    /** Persisted operator channel config, or a transient default so the stub providers run unconfigured. */
    private function channelConfig(string $operator, string $channel): ChannelOperatorConfig
    {
        return ChannelOperatorConfig::resolve($operator, $channel) ?? new ChannelOperatorConfig([
            'operator_code' => $operator,
            'channel' => $channel,
            'adapter_implementation' => $channel === 'EMAIL' ? 'smtp.default' : 'sms.default',
            'sender_identifier' => $channel === 'EMAIL' ? "no-reply@{$operator}.sophix.local" : strtoupper($operator),
            'enabled' => true,
        ]);
    }

    private function emit(string $type, Notification $notification): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: NotificationEvents::TOPIC,
            payload: ['notificationId' => $notification->notification_id, 'channel' => $notification->channel, 'status' => $notification->status],
            aggregateType: 'Notification',
            aggregateId: $notification->notification_id,
        ));
    }
}
