<?php

namespace Modules\Notification\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Models\CustomerNotificationPreference;

/**
 * NOT-01 customer self-service preferences (R-NOT-01-P-1/P-2). The customer reads and
 * updates opt-in flags, locale, and time-window. Opt-out applies to marketing only —
 * transactional messages are always delivered — so the response makes that explicit.
 */
class CustomerPreferenceController extends ApiController
{
    /** GET /api/notifications/preferences */
    public function show(Request $request): JsonResponse
    {
        $customerId = $this->customerId($request);
        $pref = CustomerNotificationPreference::forCustomer($customerId) ?? $this->defaults($customerId);

        return ApiResponse::item($pref);
    }

    /** PUT /api/notifications/preferences */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email_opt_in' => ['sometimes', 'boolean'],
            'sms_opt_in' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', 'string', 'max:8'],
            'preferred_time_window_start' => ['nullable', 'date_format:H:i'],
            'preferred_time_window_end' => ['nullable', 'date_format:H:i'],
        ]);
        $customerId = $this->customerId($request);

        $pref = CustomerNotificationPreference::query()->updateOrCreate(
            ['customer_id' => $customerId],
            $data + ['operator_code' => Context::operatorCode()],
        );

        return ApiResponse::item([
            'preference' => $pref->refresh(),
            'message' => 'Preferences updated. Marketing opt-out is honored; transactional messages (invoices, payments, security) are always sent.',
        ]);
    }

    private function customerId(Request $request): string
    {
        // A customer JWT carries the customer_id; admins may pass ?customerId= for support.
        return $request->user()?->customer_id
            ?? $request->query('customerId')
            ?? (string) $request->user()?->uid;
    }

    private function defaults(string $customerId): CustomerNotificationPreference
    {
        return new CustomerNotificationPreference([
            'customer_id' => $customerId,
            'operator_code' => Context::operatorCode(),
            'email_opt_in' => true,
            'sms_opt_in' => true,
            'locale' => config('sophix.notification.default_locale', 'en'),
            'email_status' => CustomerNotificationPreference::EMAIL_VALID,
        ]);
    }
}
