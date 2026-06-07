<?php

namespace Modules\Notification\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Models\InternalMessage;
use Modules\Notification\Models\Notification;
use Modules\Notification\Services\NotificationService;

/**
 * NOT-01 notification + ICN-01 internal communications API.
 */
class NotificationController extends ApiController
{
    public function __construct(private readonly NotificationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = Notification::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('customerId'), fn ($q, $c) => $q->where('customer_id', $c))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'in:SMS,EMAIL,PUSH,WHATSAPP'],
            'recipient' => ['required', 'string', 'max:255'],
            'template_code' => ['nullable', 'string', 'max:64'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'payload' => ['nullable', 'array'],
            'customer_id' => ['nullable', 'string'],
            'reference' => ['nullable', 'string'],
        ]);

        return ApiResponse::created($this->service->send($data));
    }

    public function show(Notification $notification): JsonResponse
    {
        return ApiResponse::item($notification);
    }

    public function postInternal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'to_group' => ['nullable', 'string', 'max:64'],
            'to_user_id' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', 'in:LOW,NORMAL,HIGH,URGENT'],
            'reference' => ['nullable', 'string'],
        ]);

        return ApiResponse::created($this->service->postInternal($data));
    }

    public function internalIndex(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = InternalMessage::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('toGroup'), fn ($q, $g) => $q->where('to_group', $g))
            ->when($request->query('toUserId'), fn ($q, $u) => $q->where('to_user_id', $u))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }
}
