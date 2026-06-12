<?php

namespace Modules\Notification\Rendering;

use Modules\Notification\Models\Template;

/**
 * Text/HTML engine: {{ placeholder }} substitution from the render context (dot paths
 * supported). Covers the HANDLEBARS / MUSTACHE engine_type hints for the EMAIL_* and
 * SMS_TEXT formats. Unknown placeholders render empty (safe, never leaks template text).
 */
class HandlebarsLikeEngine implements TemplateEngine
{
    public function render(Template $template, array $context): string
    {
        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function (array $m) use ($context) {
            return (string) (data_get($context, $m[1]) ?? '');
        }, $template->template_payload) ?? $template->template_payload;
    }
}
