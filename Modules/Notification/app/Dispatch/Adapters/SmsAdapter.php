<?php

namespace Modules\Notification\Dispatch\Adapters;

use Modules\Notification\Dispatch\AdapterConfigurationException;
use Modules\Notification\Dispatch\ChannelAdapter;
use Modules\Notification\Dispatch\DeliveryResult;
use Modules\Notification\Dispatch\Dispatch;
use Modules\Notification\Dispatch\FailureCategory;
use Modules\Notification\Models\ChannelOperatorConfig;
use Modules\Notification\Models\Template;

/**
 * Default SMS adapter (R-NOT-01-C-4). Renders the SMS_TEXT, truncates/splits to the
 * channel limit, validates the MSISDN, and records a local delivery. Real deployments
 * select a gateway-specific adapter (Africa's Talking, Beem, Orange-SN, ...) via
 * channel_operator_config.adapter_implementation.
 *
 * Failure category mapping:
 *   missing/invalid MSISDN  -> PERMANENT_RECIPIENT
 *   "+00000" sentinel       -> TRANSIENT (exercises retry)
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
        $to = preg_replace('/\s+/', '', $dispatch->recipient);
        if (! preg_match('/^\+?[0-9]{7,15}$/', (string) $to)) {
            return DeliveryResult::failed(FailureCategory::PERMANENT_RECIPIENT, 'invalid MSISDN');
        }
        if (str_starts_with((string) $to, '+00000')) {
            return DeliveryResult::failed(FailureCategory::TRANSIENT, 'gateway timeout (simulated)');
        }

        $text = (string) $dispatch->artifact(Template::FORMAT_SMS_TEXT);
        $limit = 160 * $this->maxParts;
        if (strlen($text) > $limit) {
            $text = substr($text, 0, $limit - 3).'...';
        }
        $parts = (int) max(1, ceil(strlen($text) / 160));
        $ref = bin2hex(random_bytes(8));

        return DeliveryResult::sent($ref, ['gateway_message_id' => $ref, 'parts' => $parts, 'sender' => $this->senderId]);
    }

    public function shutdown(): void {}
}
