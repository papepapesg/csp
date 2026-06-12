<?php

namespace Modules\Notification\Rendering;

use Modules\Notification\Models\Template;

/**
 * Selects the rendering engine per template_format (R-NOT-01-D-3). PDF templates render
 * through the HTML-to-PDF engine; the text formats (EMAIL_*, SMS_TEXT) through the text
 * engine. New formats register their engine here.
 */
class TemplateEngineRegistry
{
    private TemplateEngine $textEngine;

    private TemplateEngine $pdfEngine;

    public function __construct(?TemplateEngine $textEngine = null, ?TemplateEngine $pdfEngine = null)
    {
        $this->textEngine = $textEngine ?? new HandlebarsLikeEngine();
        $this->pdfEngine = $pdfEngine ?? new HtmlToPdfEngine();
    }

    public function engineFor(string $format): TemplateEngine
    {
        return $format === Template::FORMAT_PDF ? $this->pdfEngine : $this->textEngine;
    }
}
