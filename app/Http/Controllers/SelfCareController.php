<?php

namespace App\Http\Controllers;

use App\Foundation\Errors\DomainException;
use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\PaymentService;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\RestrictionService;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Services\TicketService;

/**
 * FE-APP-04 customer self-care API. Every action is scoped to the authenticated
 * principal's customer (users with role CUSTOMER carry customer_id); staff with
 * selfcare.access may act on behalf of a customer via ?customerId. The PWA at
 * /care consumes this surface (token auth, offline-tolerant).
 */
class SelfCareController extends ApiController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly TicketService $tickets,
        private readonly RestrictionService $restrictions,
    ) {}

    /** Resolve the customer this request acts for. */
    private function customerId(Request $request): string
    {
        $cid = $request->user()?->customer_id ?? $request->query('customerId');
        if (! $cid) {
            throw DomainException::ruleRejected('NO_CUSTOMER_CONTEXT', 'No customer associated with this self-care session.');
        }

        return $cid;
    }

    /** @return array<int,string> */
    private function accountIds(string $customerId): array
    {
        return CustomerAccount::query()->where('customer_id', $customerId)->pluck('account_id')->all();
    }

    /** GET /api/selfcare/me */
    public function me(Request $request): JsonResponse
    {
        $cid = $this->customerId($request);
        $customer = Customer::query()->find($cid);

        return ApiResponse::item([
            'customer' => $customer,
            'accounts' => CustomerAccount::query()->where('customer_id', $cid)->get(),
        ]);
    }

    /** GET /api/selfcare/subscriptions */
    public function subscriptions(Request $request): JsonResponse
    {
        $cid = $this->customerId($request);

        return ApiResponse::item([
            'items' => Subscription::query()->where('customer_id', $cid)->orderByDesc('created_at')->get(),
        ]);
    }

    /** GET /api/selfcare/invoices */
    public function invoices(Request $request): JsonResponse
    {
        $cid = $this->customerId($request);

        return ApiResponse::item([
            'items' => Invoice::query()
                ->whereIn('account_id', $this->accountIds($cid))
                ->orderByDesc('created_at')->limit(50)->get(),
        ]);
    }

    /** POST /api/selfcare/payments */
    public function pay(Request $request): JsonResponse
    {
        $cid = $this->customerId($request);
        $data = $request->validate([
            'account_id' => ['required', 'string'],
            'paid_amount' => ['required', 'numeric', 'min:1'],
            'method' => ['nullable', 'string', 'max:32'],
            'target_invoice_id' => ['nullable', 'string'],
        ]);

        // Guard: the account must belong to this customer.
        if (! in_array($data['account_id'], $this->accountIds($cid), true)) {
            throw DomainException::ruleRejected('ACCOUNT_NOT_OWNED', 'That account does not belong to your profile.');
        }

        $payment = $this->payments->receiveAndApply([
            'account_id' => $data['account_id'],
            'paid_amount' => $data['paid_amount'],
            'method' => $data['method'] ?? 'SELFCARE',
            'target_invoice_id' => $data['target_invoice_id'] ?? null,
        ]);

        return ApiResponse::created($payment);
    }

    /** GET /api/selfcare/tickets */
    public function ticketIndex(Request $request): JsonResponse
    {
        $cid = $this->customerId($request);

        return ApiResponse::item([
            'items' => Ticket::query()->where('customer_id', $cid)->orderByDesc('created_at')->limit(50)->get(),
        ]);
    }

    /** POST /api/selfcare/tickets */
    public function raiseTicket(Request $request): JsonResponse
    {
        $cid = $this->customerId($request);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:48'],
            'priority' => ['nullable', 'in:LOW,NORMAL,HIGH,URGENT'],
        ]);

        $ticket = $this->tickets->create([
            'customer_id' => $cid,
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? 'INFORMATION',
            'priority' => $data['priority'] ?? 'NORMAL',
            'opened_by' => $request->user()?->uid,
        ]);

        return ApiResponse::created($ticket);
    }

    /** GET /api/selfcare/subscriptions/{subscription}/restrictions */
    public function restrictions(Request $request, Subscription $subscription): JsonResponse
    {
        $cid = $this->customerId($request);
        if ($subscription->customer_id !== $cid) {
            throw DomainException::ruleRejected('SUBSCRIPTION_NOT_OWNED', 'That subscription does not belong to your profile.');
        }

        return ApiResponse::item([
            'subscriptionId' => $subscription->subscription_id,
            'activeRestrictions' => $this->restrictions->list($subscription),
        ]);
    }
}
