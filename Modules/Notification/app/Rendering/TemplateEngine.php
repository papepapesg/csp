<?php

namespace Modules\Notification\Rendering;

use Modules\Notification\Models\Template;

/**
 * NOT-01 templating-engine contract (R-NOT-01-D-3). The engine is a deployment choice;
 * NOT-01 only specifies "fill placeholders from data". The registry selects an engine
 * per template_format, so PDF formats use a PDF-capable engine and text formats use a
 * text engine — without the rendering service knowing which.
 *
 * @return string the rendered artifact (a string; PDF engines return raw bytes as a string)
 */
interface TemplateEngine
{
    /** @param array<string,mixed> $context event payload + customer snapshot + branding */
    public function render(Template $template, array $context): string;
}
