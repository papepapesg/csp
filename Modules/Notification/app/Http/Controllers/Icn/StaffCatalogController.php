<?php

namespace Modules\Notification\Http\Controllers\Icn;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Icn\StaffAdapterRegistry;
use Modules\Notification\Models\Icn\StaffGroupMembership;
use Modules\Notification\Models\Icn\StaffNotificationAdapterBinding;
use Modules\Notification\Models\Icn\StaffNotificationChannelConfig;
use Modules\Notification\Models\Icn\StaffNotificationTemplate;
use Modules\Notification\Models\Icn\StaffNotificationUserChannelIdentity;
use Modules\Notification\Models\Icn\StaffNotificationUserPref;

/**
 * ICN-01 catalog management (§5.4): templates, operator channel config, adapter bindings
 * (provider endpoints — config redacted of vault refs for safe display), per-user preferences
 * and channel identities, the adapter registry, and group membership.
 */
class StaffCatalogController extends ApiController
{
    public function __construct(private readonly StaffAdapterRegistry $registry) {}

    // ---- templates ----
    public function templates(Request $request): JsonResponse
    {
        $items = StaffNotificationTemplate::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->orderBy('template_code')->orderBy('channel')->get();

        return ApiResponse::item(['items' => $items]);
    }

    public function upsertTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_code' => ['required', 'string'],
            'channel' => ['required', 'string'],
            'subject' => ['nullable', 'string'],
            'body_template' => ['required', 'string'],
            'required_variables' => ['nullable', 'array'],
            'optional_variables' => ['nullable', 'array'],
            'urgency_default' => ['nullable', 'in:low,medium,high'],
            'display_name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
        ]);
        $operator = $request->input('operatorCode', Context::operatorCode());
        $template = StaffNotificationTemplate::query()->updateOrCreate(
            ['operator_code' => $operator, 'template_code' => $data['template_code'], 'channel' => $data['channel']],
            $data + ['enabled' => true],
        );

        return ApiResponse::created($template);
    }

    public function disableTemplate(string $operator, string $code, string $channel): JsonResponse
    {
        StaffNotificationTemplate::query()->where('operator_code', $operator)->where('template_code', $code)
            ->where('channel', $channel)->update(['enabled' => false]);

        return ApiResponse::item(['disabled' => true]);
    }

    // ---- channel config ----
    public function channelConfig(Request $request): JsonResponse
    {
        return ApiResponse::item(StaffNotificationChannelConfig::forOperator($request->query('operatorCode', Context::operatorCode())));
    }

    public function putChannelConfig(Request $request, string $operator): JsonResponse
    {
        $data = $request->validate([
            'enabled_channels' => ['required', 'array'],
            'default_priority' => ['required', 'array'],
            'fallback_mode' => ['nullable', 'in:PARALLEL,SEQUENTIAL_UNTIL_ACK,SEQUENTIAL_UNTIL_DISPATCH'],
            'retry_max_attempts' => ['nullable', 'integer', 'min:1'],
            'retry_backoff_seconds' => ['nullable', 'array'],
            'ack_window_hours' => ['nullable', 'integer', 'min:1'],
        ]);
        $cfg = StaffNotificationChannelConfig::query()->updateOrCreate(['operator_code' => $operator], $data + ['updated_at' => now()]);

        return ApiResponse::item($cfg);
    }

    // ---- adapter bindings (provider endpoints) ----
    public function bindings(Request $request): JsonResponse
    {
        $items = StaffNotificationAdapterBinding::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))->get()
            ->map(fn ($b) => ['operator_code' => $b->operator_code, 'channel' => $b->channel, 'adapter_impl' => $b->adapter_impl, 'enabled' => $b->enabled, 'config_jsonb' => $this->redact($b->config_jsonb)]);

        return ApiResponse::item(['items' => $items]);
    }

    public function putBinding(Request $request, string $operator, string $channel): JsonResponse
    {
        $data = $request->validate([
            'adapter_impl' => ['required', 'string'],
            'config_jsonb' => ['required', 'array'],
            'enabled' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);
        $binding = StaffNotificationAdapterBinding::query()->updateOrCreate(
            ['operator_code' => $operator, 'channel' => $channel],
            $data + ['updated_by' => $request->user()?->uid],
        );

        return ApiResponse::item($binding);
    }

    public function adapterRegistry(): JsonResponse
    {
        return ApiResponse::item(['implementations' => $this->registry->registeredImpls()]);
    }

    // ---- user pref + identity ----
    public function userPref(string $userId): JsonResponse
    {
        return ApiResponse::item(StaffNotificationUserPref::forUser($userId));
    }

    public function putUserPref(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate([
            'enabled_channels' => ['nullable', 'array'],
            'preferred_priority' => ['nullable', 'array'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
            'quiet_hours_timezone' => ['nullable', 'string'],
            'suppress_channels' => ['nullable', 'array'],
        ]);
        $pref = StaffNotificationUserPref::query()->updateOrCreate(
            ['user_id' => $userId],
            $data + ['operator_code' => Context::operatorCode(), 'updated_at' => now(), 'updated_by' => $request->user()?->uid === $userId ? 'self' : ($request->user()?->uid ?? 'admin')],
        );

        return ApiResponse::item($pref);
    }

    public function userIdentities(string $userId): JsonResponse
    {
        return ApiResponse::item(['items' => StaffNotificationUserChannelIdentity::query()->where('user_id', $userId)->get()]);
    }

    public function putUserIdentity(Request $request, string $userId, string $channel): JsonResponse
    {
        $data = $request->validate(['identity_jsonb' => ['required', 'array']]);
        $source = $request->user()?->uid === $userId ? 'self' : 'admin';
        $identity = StaffNotificationUserChannelIdentity::query()->updateOrCreate(
            ['user_id' => $userId, 'channel' => $channel],
            ['operator_code' => Context::operatorCode(), 'identity_jsonb' => $data['identity_jsonb'], 'source' => $source, 'verified' => false, 'updated_at' => now(), 'updated_by' => $request->user()?->uid],
        );

        return ApiResponse::item($identity);
    }

    public function deleteUserIdentity(string $userId, string $channel): JsonResponse
    {
        StaffNotificationUserChannelIdentity::query()->where('user_id', $userId)->where('channel', $channel)->delete();

        return ApiResponse::item(['deleted' => true]);
    }

    // ---- group membership (the AUTH directory stand-in) ----
    public function groupMembers(Request $request, string $group): JsonResponse
    {
        $operator = $request->query('operatorCode', Context::operatorCode());

        return ApiResponse::item(['group' => $group, 'members' => StaffGroupMembership::members($operator, $group)]);
    }

    /** Redact vault refs from config for safe BO-UI display. */
    private function redact(?array $config): array
    {
        $config ??= [];
        array_walk_recursive($config, function (&$v, $k) {
            if (is_string($k) && str_contains($k, 'vault_ref')) {
                $v = '***redacted***';
            }
        });

        return $config;
    }
}
