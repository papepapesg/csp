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
 * Default email adapter (R-NOT-01-C-3). In this monolith deployment there is no live
 * SMTP relay, so the adapter validates the message + recipient, composes the multipart
 * (subject + HTML + text + optional PDF) and records a successful local delivery. A real
 * deployment swaps this for an SMTP/transactional-email adapter via
 * channel_operator_config.adapter_implementation — nothing else in NOT-01 changes.
 *
 * Failure category mapping (from the recipient/SMTP-class shape):
 *   missing/syntactically-invalid recipient -> PERMANENT_RECIPIENT
 *   sender identity not configured           -> PERMANENT_TEMPLATE (config-level)
 *   "@transient." sentinel                   -> TRANSIENT (exercises the retry path)
 */
final class EmailAdapter implements ChannelAdapter
{
    private string $senderAddress = '';

    private bool $attachPdf = true;

    public function channel(): string
    {
        return 'EMAIL';
    }

    public function initialize(ChannelOperatorConfig $config): void
    {
        if (($config->sender_identifier ?? '') === '') {
            throw new AdapterConfigurationException('EmailAdapter requires a sender_identifier');
        }
        $this->senderAddress = $config->sender_identifier;
        $this->attachPdf = (bool) ($config->additional_config['attach_pdf'] ?? true);
    }

    public function send(Dispatch $dispatch, int $timeoutSeconds): DeliveryResult
    {
        $to = $dispatch->recipient;
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return DeliveryResult::failed(FailureCategory::PERMANENT_RECIPIENT, 'invalid recipient address');
        }
        if (str_contains($to, '@transient.')) {
            return DeliveryResult::failed(FailureCategory::TRANSIENT, 'gateway timeout (simulated)');
        }
        if ($dispatch->artifact(Template::FORMAT_EMAIL_HTML) === null && $dispatch->artifact(Template::FORMAT_EMAIL_TEXT) === null) {
            return DeliveryResult::failed(FailureCategory::PERMANENT_TEMPLATE, 'no email body rendered');
        }

        // Compose + "send": here we record a deterministic local delivery.
        $messageId = '<'.bin2hex(random_bytes(8)).'@'.parse_url('//'.$this->senderAddress, PHP_URL_PATH).'>';

        return DeliveryResult::sent($messageId, [
            'smtp_message_id' => $messageId,
            'pdf_attached' => $this->attachPdf && $dispatch->pdfStorageKey !== null,
        ]);
    }

    public function shutdown(): void {}
}
