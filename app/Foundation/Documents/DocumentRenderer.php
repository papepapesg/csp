<?php

namespace App\Foundation\Documents;

/**
 * Document/artifact rendering — the layer BETWEEN data and delivery. A module renders a
 * presentation of one of its entities (e.g. an invoice → PDF) on demand; the artifact is
 * stored and reusable. Rendering is deliberately decoupled from sending: producing a
 * document creates NO notification/delivery record. The Notification module binds the
 * implementation (it owns the templates + engines); consumers depend only on this contract.
 */
interface DocumentRenderer
{
    /**
     * Render (or return the cached) artifact for an entity in a given format. Cached by
     * (operator, entity_type, entity_id, format, locale); pass $force to re-render.
     *
     * @param  array<string,mixed>  $context  template placeholder data, built by the caller
     *
     * @throws \App\Foundation\Errors\DomainException when no ACTIVE template matches
     */
    public function render(
        string $operator,
        string $entityType,
        string $entityId,
        string $format,
        string $purpose,
        string $locale,
        array $context,
        bool $force = false,
    ): RenderedDocument;
}
