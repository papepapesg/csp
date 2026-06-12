<?php

namespace Modules\Notification\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Models\Template;
use Modules\Notification\Rendering\TemplateEngineRegistry;

/**
 * NOT-01 admin template management (R-NOT-01-O-3). Upload new versions of a template
 * (format + purpose + locale), preview a render with sample data, and activate / deactivate.
 * Templates are versioned — previous versions stay in the table for audit; uploading
 * always creates the next DRAFT version, activation supersedes nothing (resolve() simply
 * prefers the highest ACTIVE version).
 */
class AdminTemplateController extends ApiController
{
    public function __construct(private readonly TemplateEngineRegistry $engines) {}

    public function index(Request $request): JsonResponse
    {
        $items = Template::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('format'), fn ($q, $f) => $q->where('template_format', $f))
            ->when($request->query('purpose'), fn ($q, $p) => $q->where('template_purpose_code', $p))
            ->orderBy('template_purpose_code')->orderBy('template_format')->orderByDesc('version')
            ->get();

        return ApiResponse::item(['items' => $items]);
    }

    /** POST /api/admin/templates — create the next DRAFT version. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_format' => ['required', 'in:PDF,EMAIL_SUBJECT,EMAIL_HTML,EMAIL_TEXT,SMS_TEXT'],
            'template_purpose_code' => ['required', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:8'],
            'engine_type' => ['nullable', 'string', 'max:32'],
            'template_payload' => ['required_without:template_file', 'string'],
            'template_file' => ['required_without:template_payload', 'file'],
            'placeholder_schema' => ['nullable', 'array'],
            'sample_data' => ['nullable', 'array'],
        ]);
        $operator = $request->input('operatorCode', Context::operatorCode());
        $locale = $data['locale'] ?? 'en';
        $payload = $request->hasFile('template_file')
            ? (string) file_get_contents($request->file('template_file')->getRealPath())
            : $data['template_payload'];

        $template = Template::query()->create([
            'operator_code' => $operator,
            'template_format' => $data['template_format'],
            'template_purpose_code' => $data['template_purpose_code'],
            'locale' => $locale,
            'version' => Template::nextVersion($operator, $data['template_format'], $data['template_purpose_code'], $locale),
            'status' => Template::STATUS_DRAFT,
            'engine_type' => $data['engine_type'] ?? ($data['template_format'] === 'PDF' ? 'HTML_TO_PDF' : 'HANDLEBARS'),
            'template_payload' => $payload,
            'placeholder_schema' => $data['placeholder_schema'] ?? null,
            'sample_data' => $data['sample_data'] ?? null,
            'created_by' => $request->user()?->uid,
        ]);

        return ApiResponse::created(['template_id' => $template->id, 'version' => $template->version, 'status' => $template->status]);
    }

    /** POST /api/admin/templates/{template}/preview — render with sample (or supplied) data. */
    public function preview(Request $request, Template $template): JsonResponse
    {
        $vars = $request->input('sample_data', $template->sample_data ?? []);
        $rendered = $this->engines->engineFor($template->template_format)->render($template, $vars);
        if ($template->template_format === Template::FORMAT_PDF) {
            return ApiResponse::item(['template_id' => $template->id, 'format' => 'PDF', 'bytes' => strlen($rendered)]);
        }

        return ApiResponse::item(['template_id' => $template->id, 'format' => $template->template_format, 'rendered' => $rendered]);
    }

    public function activate(Template $template): JsonResponse
    {
        $template->update(['status' => Template::STATUS_ACTIVE]);

        return ApiResponse::item($template->refresh());
    }

    public function deactivate(Template $template): JsonResponse
    {
        $template->update(['status' => Template::STATUS_DISABLED]);

        return ApiResponse::item($template->refresh());
    }
}
