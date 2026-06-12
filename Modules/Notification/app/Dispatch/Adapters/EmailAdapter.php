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
 * Email provider (R-NOT-01-C-3). This is the in-process STUB provider that ships with the
 * platform: it composes the multipart message (subject + HTML + text + optional PDF) exactly
 * as a real SMTP adapter would, then "transmits" by logging a simulated send and returning a
 * SENT result with a synthetic message-id. A production deployment swaps this for a true SMTP
 * / Microsoft-Graph / SES adapter via channel_operator_config.adapter_implementation — the
 * ChannelAdapter contract is identical, so nothing else in NOT-01 changes.
 *
 * Failure injection (so the retry/fallback/escalate paths are exercisable end-to-end):
 *   recipient contains "bounce"     -> PERMANENT_RECIPIENT (hard bounce; channel fallback)
 *   recipient contains "@transient" -> TRANSIENT            (gateway hiccup; retry)
 *   recipient empty                 -> PERMANENT_RECIPIENT
 *   no rendered body                -> PERMANENT_TEMPLATE   (content the channel would reject)
 *   otherwise                       -> SENT
 *
 * Category mapping mirrors real SMTP: 550/553 (bad mailbox) -> PERMANENT_RECIPIENT,
 * 552/554 (size/content) -> PERMANENT_TEMPLATE, 4xx + connection errors -> TRANSIENT.
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
        $to = trim($dispatch->recipient);

        if ($to === '') {
            return DeliveryResult::failed(FailureCategory::PERMANENT_RECIPIENT, 'empty recipient address');
        }
        if (str_contains($to, 'bounce')) {
            return DeliveryResult::failed(FailureCategory::PERMANENT_RECIPIENT, 'mailbox unavailable (550)');
        }
        if (str_contains($to, '@transient')) {
            return DeliveryResult::failed(FailureCategory::TRANSIENT, 'smtp 421 service not available');
        }

        // Compose the multipart message the way a real adapter would.
        $subject = $dispatch->artifact(Template::FORMAT_EMAIL_SUBJECT) ?? '';
        $html = $dispatch->artifact(Template::FORMAT_EMAIL_HTML);
        $text = $dispatch->artifact(Template::FORMAT_EMAIL_TEXT);
        if (($html ?? '') === '' && ($text ?? '') === '') {
            return DeliveryResult::failed(FailureCategory::PERMANENT_TEMPLATE, 'no email body rendered (552)');
        }
        $willAttach = $this->attachPdf && $dispatch->pdfStorageKey !== null;

        // "Transmit". PII (recipient) only at DEBUG; INFO carries no PII per the contract.
        Log::info('email.send', ['dispatch_id' => $dispatch->dispatchId, 'notification_id' => $dispatch->notificationId, 'status' => 'SENT']);
        Log::debug('email.send.detail', ['to' => $to, 'from' => $this->senderAddress, 'subject' => $subject, 'pdf_attached' => $willAttach]);

        $messageId = '<'.bin2hex(random_bytes(8)).'@'.($this->senderAddress ?: 'sophix.local').'>';

        return DeliveryResult::sent($messageId, [
            'smtp_message_id' => $messageId,
            'pdf_attached' => $willAttach,
            'simulated' => true,
        ]);
    }

    public function shutdown(): void {}
}
