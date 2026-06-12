<?php

namespace Modules\Notification\Icn\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Modules\Notification\Icn\RenderedMessage;
use Modules\Notification\Icn\StaffNotificationEvents;
use Modules\Notification\Models\Icn\StaffNotification;
use Modules\Notification\Models\Icn\StaffNotificationTemplate;

/**
 * ICN-01 template rendering (§7.0 step 4, R-ICN-01-D-13). Mustache-style {{var}} substitution
 * from template_variables. A missing variable is a SOFT error: it renders as «MISSING:var» and
 * emits StaffNotificationTemplateRenderWarning so the source-module dev team can catch their
 * own bug — never a 500.
 */
class TemplateRenderer
{
    public function __construct(private readonly EventBus $events) {}

    public function render(StaffNotification $notification, string $channel): ?RenderedMessage
    {
        $template = StaffNotificationTemplate::resolve($notification->operator_code, $notification->template_code, $channel);
        if (! $template) {
            return null;
        }
        $vars = $notification->template_variables ?? [];
        if ($notification->deeplink_url && ! array_key_exists('deeplinkUrl', $vars)) {
            $vars['deeplinkUrl'] = $notification->deeplink_url;
        }

        $missing = [];
        $body = $this->substitute($template->body_template, $vars, $missing);
        $subject = $template->subject ? $this->substitute($template->subject, $vars, $missing) : null;

        if ($missing !== []) {
            $this->events->publish(new DomainEvent(
                type: StaffNotificationEvents::TEMPLATE_RENDER_WARNING,
                topic: StaffNotificationEvents::TOPIC,
                payload: ['notificationId' => $notification->notification_id, 'templateCode' => $notification->template_code, 'channel' => $channel, 'missing' => array_values(array_unique($missing))],
                aggregateType: 'StaffNotification',
                aggregateId: $notification->notification_id,
            ));
        }

        return new RenderedMessage($channel, $subject, $body, $vars['deeplinkUrl'] ?? $notification->deeplink_url, $notification->urgency);
    }

    /** @param array<string,mixed> $vars */
    private function substitute(string $text, array $vars, array &$missing): string
    {
        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function (array $m) use ($vars, &$missing) {
            $val = data_get($vars, $m[1]);
            if ($val === null) {
                $missing[] = $m[1];

                return '«MISSING:'.$m[1].'»';
            }

            return (string) $val;
        }, $text) ?? $text;
    }
}
