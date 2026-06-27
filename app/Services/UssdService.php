<?php

namespace App\Services;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use App\Models\UssdMenuDefinition;
use App\Models\UssdRequestLog;
use App\Models\UssdSession;
use Modules\Billing\Dunning\Models\DunningState;
use Modules\Billing\Invoicing\Models\Invoice;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Ilm\Models\CustomerInteraction;
use Modules\Subscription\Models\Subscription;

/**
 * FE-CH-USSD-01 USSD self-care channel — a thin channel adapter. Menus are config
 * (ussd_menu_definition, per operator + language), so a new market or wording is a row edit.
 * The engine walks the menu graph statefully (session.current_menu_code) and dispatches the
 * leaf actions to the owning modules' read APIs (balance/subscription) or write APIs with
 * idempotency keys (ticket creation) — USSD never applies payments, changes subscriptions, or
 * closes tickets locally. Every request is traced (ussd_request_log) and the session is logged
 * to CUST-INT-01 on end.
 */
class UssdService
{
    /** @return array{message:string, continue:bool} */
    public function handle(string $gatewaySessionId, string $msisdn, string $text, ?string $gatewayRequestId = null): array
    {
        $operator = Context::operatorCode();
        $session = $this->loadSession($gatewaySessionId, $operator, $msisdn, $gatewayRequestId);
        $customer = $this->resolveCustomer($session, $msisdn);

        $choice = $this->lastChoice($text);
        $result = $text === ''
            ? $this->renderMenu($session, 'MAIN', $customer)
            : $this->advance($session, $choice, $customer);

        // Trace + close-out.
        $this->log($session, $gatewayRequestId, $text, $result);
        if (! $result['continue']) {
            $session->update(['status' => UssdSession::ENDED, 'active' => false, 'ended_at' => now()]);
            if ($customer) {
                CustomerInteraction::query()->create(['id' => Id::make('int'), 'customer_id' => $customer->customer_id, 'agent_name' => 'ussd', 'reason' => 'USSD_'.$session->current_menu_code, 'findings' => substr($result['message'], 0, 240)]);
            }
        }

        return ['message' => ($result['continue'] ? 'CON ' : 'END ').$result['message'], 'continue' => $result['continue']];
    }

    private function loadSession(string $id, string $operator, string $msisdn, ?string $gatewayRequestId): UssdSession
    {
        $session = UssdSession::query()->firstOrNew(['session_id' => $id]);
        if (! $session->exists) {
            $session->fill(['operator_code' => $operator, 'gateway_session_id' => $id, 'msisdn' => $msisdn, 'language_code' => 'en', 'current_menu_code' => 'MAIN', 'status' => UssdSession::ACTIVE, 'started_at' => now()]);
        }
        $session->last_seen_at = now();
        $session->save();

        return $session;
    }

    private function resolveCustomer(UssdSession $session, string $msisdn): ?Customer
    {
        $customer = Customer::query()->where('primary_msisdn', $msisdn)->first();
        if ($customer && $session->customer_id !== $customer->customer_id) {
            $account = CustomerAccount::query()->where('customer_id', $customer->customer_id)->first();
            $session->update(['customer_id' => $customer->customer_id, 'account_id' => $account?->account_id]);
        }

        return $customer;
    }

    /** Resolve the latest selection against the current menu and either render the next menu or run an action. */
    private function advance(UssdSession $session, string $choice, ?Customer $customer): array
    {
        $menu = UssdMenuDefinition::resolve($session->operator_code, $session->language_code, $session->current_menu_code);
        $target = $menu?->options_json[$choice] ?? null;
        if (! $target) {
            return $this->endMsg('Invalid choice.');
        }

        // A navigational target is itself a menu definition; otherwise it is a leaf action.
        if (UssdMenuDefinition::resolve($session->operator_code, $session->language_code, $target)) {
            return $this->renderMenu($session, $target, $customer);
        }

        return $this->runAction($session, $target, $customer);
    }

    private function renderMenu(UssdSession $session, string $menuCode, ?Customer $customer): array
    {
        $menu = UssdMenuDefinition::resolve($session->operator_code, $session->language_code, $menuCode);
        if (! $menu) {
            return $this->endMsg('Service temporarily unavailable.');
        }
        if ($menu->requires_customer && ! $customer) {
            return $this->endMsg('No account found for this number. Please contact support.');
        }
        $session->update(['current_menu_code' => $menuCode]);

        return ['message' => $menu->menu_text, 'continue' => true];
    }

    private function runAction(UssdSession $session, string $action, ?Customer $customer): array
    {
        // Leaf actions that need a customer fail safe when the number isn't recognised.
        if (! $customer && ! in_array($action, ['LANG_EN', 'LANG_SW'], true)) {
            return $this->endMsg('No account found for this number. Please contact support.');
        }
        $session->update(['current_menu_code' => $action]);
        $accountIds = $customer ? CustomerAccount::query()->where('customer_id', $customer->customer_id)->pluck('account_id') : collect();

        return match ($action) {
            'BALANCE' => $this->balance($accountIds),
            'PAY' => $this->payInstructions($session, $customer),
            'SUBSCRIPTION' => $this->subscription($customer),
            'SUPPORT_CREATE' => $this->createTicket($session, $customer),
            'SUPPORT_STATUS' => $this->ticketStatus($customer),
            'CALLBACK' => $this->createTicket($session, $customer, callback: true),
            'LANG_EN' => $this->setLanguage($session, $customer, 'en'),
            'LANG_SW' => $this->setLanguage($session, $customer, 'sw'),
            'ACCOUNT' => $this->endMsg($customer->name.' — account '.($session->account_id ?? 'N/A')),
            default => $this->endMsg('Invalid choice.'),
        };
    }

    private function balance($accountIds): array
    {
        $due = (float) Invoice::query()->whereIn('account_id', $accountIds)->sum('amount_due');
        $msg = 'Balance due: '.number_format($due, 2).' KES';
        $dunning = DunningState::query()->whereIn('account_id', $accountIds)->where('current_level', '>', 0)->orderByDesc('current_level')->first();
        if ($dunning) {
            $msg .= "\nDunning level {$dunning->current_level} — pay to avoid service interruption.";
        }

        return $this->endMsg($msg);
    }

    private function payInstructions(UssdSession $session, Customer $customer): array
    {
        // PAY-GW-01 owns initiation; USSD v1 shows paybill + reference (no local payment).
        $paybill = config('sophix.ussd.paybill', '888880');

        return $this->endMsg("Pay via M-Pesa Paybill {$paybill}, Account ".($session->account_id ?? $customer->customer_id).". You will get an SMS receipt.");
    }

    private function subscription(Customer $customer): array
    {
        $sub = Subscription::query()->where('customer_id', $customer->customer_id)->orderByDesc('created_at')->first();

        return $sub
            ? $this->endMsg('Subscription '.($sub->package_ref ?? '').': '.$sub->status_code)
            : $this->endMsg('No active subscription found.');
    }

    private function createTicket(UssdSession $session, Customer $customer, bool $callback = false): array
    {
        // Boundary: USSD calls TCK-01; it does not own ticket logic. Idempotent per session so a
        // gateway retry doesn't open duplicate tickets.
        $data = $session->session_data_json ?? [];
        if (! empty($data['ticketRef'])) {
            return $this->endMsg(($callback ? 'Callback requested. ' : 'Ticket created. ').'Ref: '.$data['ticketRef']);
        }
        try {
            $ticket = app(\Modules\Ticketing\Services\TicketService::class)->create([
                'customer_id' => $customer->customer_id,
                'subject' => $callback ? 'USSD callback request' : 'USSD self-service request',
                'category' => 'SERVICE_REQUEST', // intake category; customer link satisfies TCK-2
            ]);
            $ref = $ticket->ticket_number ?? $ticket->getKey();
            $session->update(['session_data_json' => $data + ['ticketRef' => $ref]]);

            return $this->endMsg(($callback ? 'Callback requested. ' : 'Ticket created. ').'Ref: '.$ref);
        } catch (\Throwable) {
            return $this->endMsg('Request received. An agent will follow up.');
        }
    }

    private function ticketStatus(Customer $customer): array
    {
        $ticket = \Modules\Ticketing\Models\Ticket::query()->where('customer_id', $customer->customer_id)->orderByDesc('created_at')->first();

        return $ticket
            ? $this->endMsg('Latest ticket '.($ticket->ticket_number ?? $ticket->getKey()).': '.$ticket->status)
            : $this->endMsg('No tickets found.');
    }

    private function setLanguage(UssdSession $session, ?Customer $customer, string $lang): array
    {
        $session->update(['language_code' => $lang, 'current_menu_code' => 'MAIN']);
        if ($customer) {
            $customer->update(['preferred_language' => $lang]);
        }

        // Re-render the main menu in the new language.
        return $this->renderMenu($session, 'MAIN', $customer);
    }

    private function lastChoice(string $text): string
    {
        $parts = array_values(array_filter(explode('*', $text), fn ($p) => $p !== ''));

        return end($parts) ?: '';
    }

    private function log(UssdSession $session, ?string $gatewayRequestId, string $input, array $result): void
    {
        UssdRequestLog::query()->create([
            'request_log_id' => Id::make('url'), 'session_id' => $session->session_id, 'operator_code' => $session->operator_code,
            'gateway_request_id' => $gatewayRequestId, 'input_text' => $input,
            'response_type' => $result['continue'] ? 'CON' : 'END', 'response_text' => substr($result['message'], 0, 1000),
            'menu_code' => $session->current_menu_code, 'created_at' => now(),
        ]);
    }

    private function endMsg(string $m): array
    {
        return ['message' => $m, 'continue' => false];
    }
}
