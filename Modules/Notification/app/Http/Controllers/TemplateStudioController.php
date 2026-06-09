<?php

namespace Modules\Notification\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Models\InvoiceTemplate;
use Modules\Notification\Models\NotificationTemplate;
use Modules\Notification\Services\TemplateService;

/**
 * NOT-01 template studio API — design per-channel notification templates and invoice
 * layouts, preview them with sample variables, and publish (DRAFT → ACTIVE).
 */
class TemplateStudioController extends ApiController
{
    public function __construct(private readonly TemplateService $templates) {}

    // --- notification templates ---
    public function index(Request $request): JsonResponse
    {
        $items = NotificationTemplate::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('channel'), fn ($q, $c) => $q->where('channel', $c))
            ->when($request->query('templateCode'), fn ($q, $t) => $q->where('template_code', $t))
            ->orderBy('template_code')->orderBy('channel')->get();

        return ApiResponse::item(['items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_code' => ['required', 'string', 'max:64'],
            'channel' => ['required', 'in:SMS,EMAIL,PUSH,WHATSAPP'],
            'locale' => ['nullable', 'string', 'max:8'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'variables' => ['nullable', 'array'],
        ]);
        $data['updated_by'] = $request->user()?->uid;
        $data['status'] = NotificationTemplate::DRAFT;

        return ApiResponse::created(NotificationTemplate::query()->create($data));
    }

    public function update(Request $request, NotificationTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'variables' => ['nullable', 'array'],
        ]);
        $template->update($data + ['updated_by' => $request->user()?->uid]);

        return ApiResponse::item($template->refresh());
    }

    public function activate(NotificationTemplate $template): JsonResponse
    {
        $template->update(['status' => NotificationTemplate::ACTIVE]);

        return ApiResponse::item($template);
    }

    /** Render a template with sample variables (studio live preview). */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_code' => ['required', 'string'],
            'channel' => ['required', 'in:SMS,EMAIL,PUSH,WHATSAPP'],
            'variables' => ['nullable', 'array'],
            'locale' => ['nullable', 'string', 'max:8'],
        ]);
        $rendered = $this->templates->render($data['template_code'], $data['channel'], $data['variables'] ?? [], null, $data['locale'] ?? 'en');

        return $rendered
            ? ApiResponse::item($rendered)
            : ApiResponse::item(['subject' => null, 'body' => null, 'templateId' => null]);
    }

    // --- invoice templates ---
    public function invoiceIndex(Request $request): JsonResponse
    {
        $items = InvoiceTemplate::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->orderBy('code')->get();

        return ApiResponse::item(['items' => $items]);
    }

    public function invoiceStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'layout' => ['required', 'array'],
        ]);
        $data['updated_by'] = $request->user()?->uid;

        return ApiResponse::created(InvoiceTemplate::query()->create($data));
    }

    public function invoiceUpdate(Request $request, InvoiceTemplate $invoiceTemplate): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'layout' => ['sometimes', 'array'],
            'status' => ['sometimes', 'in:DRAFT,ACTIVE'],
        ]);
        $invoiceTemplate->update($data + ['updated_by' => $request->user()?->uid]);

        return ApiResponse::item($invoiceTemplate->refresh());
    }
}
