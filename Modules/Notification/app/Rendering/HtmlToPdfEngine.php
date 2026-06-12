<?php

namespace Modules\Notification\Rendering;

use Modules\Notification\Models\Template;

/**
 * PDF engine (HTML_TO_PDF engine_type). Fills the HTML template's placeholders, then
 * wraps the result in a minimal but valid single-page PDF byte stream. A real deployment
 * swaps in a full HTML-to-PDF renderer (wkhtmltopdf, Chromium, etc.); the contract — HTML
 * in, PDF bytes out — is unchanged, so nothing downstream is affected.
 */
class HtmlToPdfEngine implements TemplateEngine
{
    public function render(Template $template, array $context): string
    {
        $html = preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function (array $m) use ($context) {
            return (string) (data_get($context, $m[1]) ?? '');
        }, $template->template_payload) ?? $template->template_payload;

        return $this->wrapPdf(strip_tags($html));
    }

    /** Minimal valid PDF carrying the rendered text — placeholder for a real PDF toolchain. */
    private function wrapPdf(string $text): string
    {
        $text = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], substr($text, 0, 1800));
        $stream = "BT /F1 12 Tf 50 780 Td ({$text}) Tj ET";
        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
            "4 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream\nendobj\n",
            "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj;
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }
}
