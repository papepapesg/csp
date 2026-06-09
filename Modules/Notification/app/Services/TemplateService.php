<?php

namespace Modules\Notification\Services;

use App\Foundation\Support\Context;
use Modules\Notification\Models\NotificationTemplate;

/**
 * NOT-01 template rendering. Resolves the ACTIVE per-channel template for a code and
 * substitutes {{placeholders}} from the supplied variables. The same template_code
 * renders differently per channel (a terse SMS vs a full EMAIL) — the channel picks
 * the variant. Returns null when no template is configured (caller falls back to an
 * inline body).
 *
 * @phpstan-type Rendered array{subject:?string, body:string, templateId:string}
 */
class TemplateService
{
    /**
     * @param  array<string,mixed>  $vars
     * @return array{subject:?string, body:string, templateId:string}|null
     */
    public function render(string $templateCode, string $channel, array $vars = [], ?string $operator = null, string $locale = 'en'): ?array
    {
        $template = NotificationTemplate::resolve($operator ?? Context::operatorCode(), $templateCode, $channel, $locale);
        if (! $template) {
            return null;
        }

        return [
            'subject' => $template->subject ? $this->substitute($template->subject, $vars) : null,
            'body' => $this->substitute($template->body, $vars),
            'templateId' => $template->template_id,
        ];
    }

    /** Replace {{ var }} tokens; unknown tokens render empty (logged-safe). */
    private function substitute(string $text, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function (array $m) use ($vars) {
            return (string) (data_get($vars, $m[1]) ?? '');
        }, $text) ?? $text;
    }
}
