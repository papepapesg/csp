<?php

namespace Modules\Notification\Rendering;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Modules\Notification\Events\NotificationEvents;
use Modules\Notification\Models\RenderFailureQueue;
use Modules\Notification\Models\Template;

/**
 * NOT-01 Concern A — artifact rendering (rule group D). Resolves a template, renders it
 * with the engine for its format, and (for PDF) stores the artifact. Missing templates
 * and engine errors land in render_failure_queue and emit RenderFailed (D-2/D-7); the
 * caller skips the affected channel rather than failing the whole notification.
 */
class ArtifactRenderingService
{
    public function __construct(
        private readonly TemplateLookupService $lookup,
        private readonly TemplateEngineRegistry $engines,
        private readonly PdfStorageService $pdfStorage,
        private readonly EventBus $events,
    ) {}

    /**
     * Render one text artifact (EMAIL_SUBJECT/HTML/TEXT, SMS_TEXT). Returns null and
     * queues a render failure if the template is missing or the engine errors.
     *
     * @param array<string,mixed> $context
     */
    public function renderText(string $operator, string $format, string $purpose, string $locale, array $context, string $eventType, string $sourceEntityId): ?array
    {
        $template = $this->lookup->resolve($operator, $format, $purpose, $locale);
        if (! $template) {
            $this->queueFailure($operator, $eventType, $sourceEntityId, RenderFailureQueue::TEMPLATE_NOT_FOUND, "missing {$format}/{$purpose}/{$locale}", $context);

            return null;
        }
        try {
            return ['templateId' => $template->id, 'content' => $this->engines->engineFor($format)->render($template, $context)];
        } catch (\Throwable $e) {
            $this->queueFailure($operator, $eventType, $sourceEntityId, RenderFailureQueue::RENDER_ENGINE_ERROR, $e->getMessage(), $context);

            return null;
        }
    }

    /**
     * Render + store a PDF artifact. Returns the storage key (and fileId) or null on
     * failure (queued). Emits PdfReady on success (R-NOT-01-D-6).
     *
     * @param array<string,mixed> $context
     * @return array{key:string, fileId:string, templateId:string}|null
     */
    public function renderPdf(string $operator, string $purpose, string $locale, array $context, string $eventType, string $sourceEntityId, string $bucket = 'invoices'): ?array
    {
        $template = $this->lookup->resolve($operator, Template::FORMAT_PDF, $purpose, $locale);
        if (! $template) {
            $this->queueFailure($operator, $eventType, $sourceEntityId, RenderFailureQueue::TEMPLATE_NOT_FOUND, "missing PDF/{$purpose}/{$locale}", $context);

            return null;
        }
        try {
            $bytes = $this->engines->engineFor(Template::FORMAT_PDF)->render($template, $context);
            $stored = $this->pdfStorage->store($operator, $bucket, $sourceEntityId, $bytes);
            $this->events->publish(new DomainEvent(
                type: NotificationEvents::PDF_READY,
                topic: NotificationEvents::TOPIC,
                payload: ['entityId' => $sourceEntityId, 'entityType' => $eventType, 'pdfUrl' => $stored['key'], 'fileId' => $stored['fileId'], 'renderedAt' => now()->toIso8601String()],
                aggregateType: 'Notification',
                aggregateId: $sourceEntityId,
            ));

            return ['key' => $stored['key'], 'fileId' => $stored['fileId'], 'templateId' => $template->id];
        } catch (\Throwable $e) {
            $this->queueFailure($operator, $eventType, $sourceEntityId, RenderFailureQueue::RENDER_ENGINE_ERROR, $e->getMessage(), $context);

            return null;
        }
    }

    /** @param array<string,mixed> $payload */
    private function queueFailure(string $operator, string $eventType, string $sourceEntityId, string $reason, string $detail, array $payload): void
    {
        $backoff = config('sophix.notification.render_backoff_seconds', [300, 900, 1800, 3600, 7200, 14400, 28800, 86400]);
        RenderFailureQueue::query()->create([
            'operator_code' => $operator,
            'event_type' => $eventType,
            'source_entity_id' => $sourceEntityId,
            'failure_reason' => $reason,
            'failure_detail' => $detail,
            'attempt_count' => 1,
            'last_attempt_at' => now(),
            'next_attempt_at' => now()->addSeconds((int) ($backoff[0] ?? 300)),
            'status' => RenderFailureQueue::PENDING_RETRY,
            'original_event_payload' => $payload,
        ]);
        $this->events->publish(new DomainEvent(
            type: NotificationEvents::RENDER_FAILED,
            topic: NotificationEvents::TOPIC,
            payload: ['eventType' => $eventType, 'sourceEntityId' => $sourceEntityId, 'reason' => $reason],
            aggregateType: 'Notification',
            aggregateId: $sourceEntityId,
        ));
    }
}
