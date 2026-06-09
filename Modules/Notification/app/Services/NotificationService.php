<?php

namespace Modules\Notification\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Notification\Events\NotificationEvents;
use Modules\Notification\Models\InternalMessage;
use Modules\Notification\Models\Notification;

/**
 * NOT-01 notification service. Queues a notification, hands it to the channel
 * provider, and records delivery state. Provider integration is stubbed behind
 * dispatchToProvider() so SMS/email gateways can be wired without touching callers.
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

            // Hand to provider (stubbed: succeeds synchronously for the native driver).
            $ok = $this->dispatchToProvider($notification);

            $notification->update($ok
                ? ['status' => Notification::SENT, 'sent_at' => now()]
                : ['status' => Notification::FAILED, 'failure_reason' => 'provider_unavailable']);

            $this->emit($ok ? NotificationEvents::SENT : NotificationEvents::FAILED, $notification);

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

    /** Stub channel provider. Wire SMS/email/push gateways here. */
    private function dispatchToProvider(Notification $notification): bool
    {
        return $notification->recipient !== '';
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
