<?php

namespace Modules\Notification\Dispatch\Adapters;

use Illuminate\Support\Facades\Log;
use Modules\Notification\Dispatch\AdapterConfigurationException;
use Modules\Notification\Dispatch\ChannelAdapter;
use Modules\Notification\Dispatch\DeliveryResult;
use Modules\Notification\Dispatch\Dispatch;
use Modules\Notification\Dispatch\FailureCategory;
use Modules\Notification\Models\ChannelOperatorConfig;
use Modules\Notification\Models\Template;

/**
 * SMS provider (R-NOT-01-C-4). The in-process STUB provider: it renders the SMS_TEXT,
 * truncates/splits to the channel limit, then "transmits" by logging a simulated send and
 * returning a SENT result with a synthetic gateway reference. Production deployments select a
 * gateway-specific adapter (Africa's Talking, Beem, Orange-SN, Twilio, ...) via
 * channel_operator_config.adapter_implementation — same ChannelAdapter contract.
 *
 * Failure injection (exercises retry/fallback/escalate):
 *   recipient starts "+00000"   -> TRANSIENT            (gateway timeout; retry)
 *   recipient starts "+99999" or
 *     contains "bounce"          -> PERMANENT_RECIPIENT  (unreachable MSISDN; channel fallback)
 *   recipient empty              -> PERMANENT_RECIPIENT
 *   otherwise                    -> SENT
 */
final class SmsAdapter implements ChannelAdapter
{
    private string $senderId = '';

    private int $maxParts = 4;

    public function channel(): string
    {
        return 'SMS';
    }

    public function initialize(ChannelOperatorConfig $config): void
    {
        if (($config->sender_identifier ?? '') === '') {
            throw new AdapterConfigurationException('SmsAdapter requires a sender_identifier');
        }
        $this->senderId = $config->sender_identifier;
        $this->maxParts = (int) ($config->additional_config['max_parts'] ?? 4);
    }

    public function send(Dispatch $dispatch, int $timeoutSeconds): DeliveryResult
    {
        $to = preg_replace('/\s+/', '', trim($dispatch->recipient));

        if (($to ?? '') === '') {
            return DeliveryResult::failed(FailureCategory::PERMANENT_RECIPIENT, 'empty MSISDN');
        }
        if (str_starts_with($to, '+00000')) {
            return DeliveryResult::failed(FailureCategory::TRANSIENT, 'gateway timeout');
        }
        if (str_starts_with($to, '+99999') || str_contains($to, 'bounce')) {
            return DeliveryResult::failed(FailureCategory::PERMANENT_RECIPIENT, 'unreachable MSISDN');
        }

        $text = (string) $dispatch->artifact(Template::FORMAT_SMS_TEXT);
        $limit = 160 * $this->maxParts;
        if (strlen($text) > $limit) {
            $text = substr($text, 0, $limit - 3).'...';
        }
        $parts = (int) max(1, ceil(max(1, strlen($text)) / 160));

        Log::info('sms.send', ['dispatch_id' => $dispatch->dispatchId, 'notification_id' => $dispatch->notificationId, 'status' => 'SENT', 'parts' => $parts]);
        Log::debug('sms.send.detail', ['to' => $to, 'sender' => $this->senderId, 'text' => $text]);

        $ref = bin2hex(random_bytes(8));

        return DeliveryResult::sent($ref, ['gateway_message_id' => $ref, 'parts' => $parts, 'sender' => $this->senderId, 'simulated' => true]);
    }

    public function shutdown(): void {}
}
