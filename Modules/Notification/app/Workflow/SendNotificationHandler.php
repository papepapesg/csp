<?php

namespace Modules\Notification\Workflow;

use Modules\Notification\Services\NotificationService;
use Modules\Workflow\Contracts\Io;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * Toolbox step: send a notification (NOT-01). Channel/template come from the
 * flow node config; recipient from process variables. No-ops cleanly when no
 * recipient is present so flows stay robust.
 */
class SendNotificationHandler implements TaskHandler
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function topic(): string
    {
        return 'notify.send';
    }

    public function label(): string
    {
        return 'Notification: Send';
    }

    /** @return array<int,array<string,mixed>> */
    public function inputs(): array
    {
        return [
            Io::in('channel', Io::ENUM, 'Delivery channel.', false, 'SMS', ['SMS', 'EMAIL', 'PUSH', 'WHATSAPP']),
            Io::in('template', Io::STRING, 'Template code to render.'),
            Io::in('recipient', Io::STRING, 'Recipient address; falls back to the process variable "recipient".'),
            Io::in('body', Io::STRING, 'Inline body when no template is used.'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function outputs(): array
    {
        return [Io::out('notified', Io::BOOLEAN, 'True when a notification was dispatched (false when there was no recipient).')];
    }

    public function handle(TaskContext $context): TaskResult
    {
        $cfg = $context->config();
        $recipient = $context->var('recipient') ?? ($cfg['recipient'] ?? null);
        if (! $recipient) {
            return TaskResult::success(['notified' => false]);
        }

        $this->notifications->send([
            'channel' => $cfg['channel'] ?? 'SMS',
            'recipient' => $recipient,
            'template_code' => $cfg['template'] ?? null,
            'body' => $cfg['body'] ?? null,
            'customer_id' => $context->var('customerId'),
            'reference' => $context->businessKey(),
        ]);

        return TaskResult::success(['notified' => true]);
    }
}
