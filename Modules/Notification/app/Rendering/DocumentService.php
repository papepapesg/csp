<?php

namespace Modules\Notification\Rendering;

use App\Foundation\Documents\DocumentRenderer;
use App\Foundation\Documents\RenderedDocument;
use App\Foundation\Errors\DomainException;
use Illuminate\Support\Collection;
use Modules\Notification\Models\RenderedArtifact;
use Modules\Notification\Models\Template;

/**
 * NOT-01 document layer — render-on-demand, cached, reusable. This is the seam that
 * SEPARATES generation from sending: it resolves a template and renders an artifact (PDF
 * stored in Foundation Files, short text kept inline), records it in rendered_artifact, and
 * returns it. It creates NO notification_log / delivery row and NO render-failure-queue
 * entry — a missing template is surfaced to the caller, not swallowed into the send
 * pipeline. The Notification dispatch path consumes these artifacts; an operator can also
 * fetch one (e.g. print an invoice) with no send at all.
 */
class DocumentService implements DocumentRenderer
{
    public function __construct(
        private readonly TemplateLookupService $lookup,
        private readonly TemplateEngineRegistry $engines,
        private readonly PdfStorageService $pdfStorage,
    ) {}

    public function render(string $operator, string $entityType, string $entityId, string $format, string $purpose, string $locale, array $context, bool $force = false): RenderedDocument
    {
        $existing = $this->cached($operator, $entityType, $entityId, $format, $locale);
        if ($existing && ! $force) {
            return $this->toDocument($existing);
        }

        $template = $this->lookup->resolve($operator, $format, $purpose, $locale);
        if (! $template) {
            throw new DomainException('DOCUMENT_TEMPLATE_NOT_FOUND', "No ACTIVE {$format} template for purpose '{$purpose}' ({$locale}).", 404);
        }

        $rendered = $this->engines->engineFor($format)->render($template, $context);

        $fileId = null;
        $content = null;
        if ($format === Template::FORMAT_PDF) {
            $fileId = $this->pdfStorage->store($operator, strtolower($entityType), $entityId, $rendered)['fileId'];
        } else {
            $content = $rendered;
        }

        $artifact = RenderedArtifact::query()->updateOrCreate(
            ['operator_code' => $operator, 'entity_type' => $entityType, 'entity_id' => $entityId, 'format' => $format, 'locale' => $locale],
            [
                'purpose_code' => $purpose,
                'template_id' => $template->id,
                'file_id' => $fileId,
                'content' => $content,
                'content_hash' => hash('sha256', $rendered),
                'status' => RenderedArtifact::RENDERED,
                'rendered_at' => now(),
            ],
        );

        return $this->toDocument($artifact->refresh());
    }

    /** All stored artifacts for an entity (any format/locale) — read-only listing. */
    public function forEntity(string $entityType, string $entityId): Collection
    {
        return RenderedArtifact::query()
            ->where('entity_type', $entityType)->where('entity_id', $entityId)
            ->orderBy('format')->get();
    }

    private function cached(string $operator, string $entityType, string $entityId, string $format, string $locale): ?RenderedArtifact
    {
        return RenderedArtifact::query()
            ->where('operator_code', $operator)->where('entity_type', $entityType)
            ->where('entity_id', $entityId)->where('format', $format)->where('locale', $locale)
            ->first();
    }

    private function toDocument(RenderedArtifact $a): RenderedDocument
    {
        return new RenderedDocument($a->artifact_id, $a->format, $a->file_id, $a->content, $a->status);
    }
}
