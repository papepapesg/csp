<?php

namespace App\Services;

use App\Foundation\Support\Context;
use App\Models\UssdSession;
use Modules\Billing\Models\Invoice;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;

/**
 * USSD self-care channel. Stateless menu driven by the gateway: each request
 * (sessionId, msisdn, text) advances a session and returns a "CON " (continue) or
 * "END " response. Resolves the customer by msisdn and exposes balance, last
 * invoice, and a callback request — no app install needed (feature-phone reach).
 */
class UssdService
{
    /** @return array{message:string, continue:bool} */
    public function handle(string $sessionId, string $msisdn, string $text): array
    {
        $session = UssdSession::query()->firstOrNew(['session_id' => $sessionId]);
        if (! $session->exists) {
            $session->operator_code = Context::operatorCode();
            $session->msisdn = $msisdn;
            $session->state = 'MENU';
            $session->save();
        }

        $choice = $this->lastChoice($text);
        $customer = Customer::query()->where('primary_msisdn', $msisdn)->first();

        if ($text === '') {
            return $this->con("Welcome to SOPHIX\n1. Account balance\n2. Last invoice\n3. Request a callback");
        }
        if (! $customer) {
            return $this->end('No account found for this number. Please contact support.');
        }

        $accountIds = CustomerAccount::query()->where('customer_id', $customer->customer_id)->pluck('account_id');

        return match ($choice) {
            '1' => $this->end('Balance due: '.number_format((float) Invoice::query()->whereIn('account_id', $accountIds)->sum('amount_due'), 2).' KES'),
            '2' => $this->lastInvoice($accountIds),
            '3' => $this->callback($session, $customer->customer_id),
            default => $this->end('Invalid choice.'),
        };
    }

    private function lastInvoice($accountIds): array
    {
        $inv = Invoice::query()->whereIn('account_id', $accountIds)->orderByDesc('created_at')->first();

        return $inv
            ? $this->end('Last invoice '.($inv->legal_invoice_number ?? $inv->invoice_id).': due '.number_format((float) $inv->amount_due, 2).' KES ('.$inv->status.')')
            : $this->end('No invoices found.');
    }

    private function callback(UssdSession $session, string $customerId): array
    {
        $session->update(['state' => 'CALLBACK_REQUESTED', 'context' => ['customerId' => $customerId], 'active' => false]);

        return $this->end('Thanks. An agent will call you back shortly.');
    }

    private function lastChoice(string $text): string
    {
        $parts = array_filter(explode('*', $text), fn ($p) => $p !== '');

        return end($parts) ?: '';
    }

    private function con(string $m): array
    {
        return ['message' => 'CON '.$m, 'continue' => true];
    }

    private function end(string $m): array
    {
        return ['message' => 'END '.$m, 'continue' => false];
    }
}
