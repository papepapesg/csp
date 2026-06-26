<?php

namespace App\Foundation\Documents;

/**
 * A rendered presentation of a domain entity (an invoice, a notice, …) produced by the
 * document layer — independent of whether it is ever delivered. PDF/HTML artifacts carry a
 * Foundation Files `fileId`; short text formats (SMS/EMAIL_TEXT) carry inline `content`.
 */
final class RenderedDocument
{
    public function __construct(
        public readonly string $artifactId,
        public readonly string $format,
        public readonly ?string $fileId,
        public readonly ?string $content,
        public readonly string $status,
    ) {}
}
