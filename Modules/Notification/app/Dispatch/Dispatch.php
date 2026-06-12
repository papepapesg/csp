<?php

namespace Modules\Notification\Dispatch;

/**
 * One fully-resolved dispatch instruction handed to a ChannelAdapter: "send this
 * rendered content to this recipient on this channel". All routing, preference, and
 * rendering decisions are already made upstream — the adapter just transmits.
 *
 * @see \Modules\Notification\Dispatch\ChannelAdapter
 */
final class Dispatch
{
    /**
     * @param array<string,string> $renderedArtifacts e.g. EMAIL_SUBJECT => "...", SMS_TEXT => "..."
     * @param array<string,mixed>  $metadata          tracking info copied to the audit row
     */
    public function __construct(
        public readonly string $dispatchId,
        public readonly string $notificationId,
        public readonly string $operatorCode,
        public readonly ?string $customerId,
        public readonly string $channel,
        public readonly string $recipient,
        public readonly array $renderedArtifacts,
        public readonly ?string $pdfStorageKey = null,
        public readonly array $metadata = [],
    ) {}

    public function artifact(string $format): ?string
    {
        return $this->renderedArtifacts[$format] ?? null;
    }
}
