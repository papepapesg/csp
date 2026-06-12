<?php

namespace Modules\Notification\Icn;

/** A rendered staff message handed to an adapter: per-channel subject/body + the deep-link. */
final class RenderedMessage
{
    public function __construct(
        public readonly string $channel,
        public readonly ?string $subject,
        public readonly string $body,
        public readonly ?string $deeplinkUrl,
        public readonly string $urgency,
    ) {}
}
