<?php

namespace Modules\Ticketing\Services;

use App\Foundation\Rules\RuleEngine;
use Modules\Ilm\Services\AccountService;
use Modules\Ticketing\Models\Ticket;

/**
 * ASR-01..04 intake + routing. Creates a TCK-01 ticket tagged with the ASR type
 * and applies rules.asr.routing to set the queue, priority bump, and auto-actions
 * (Technical Trouble auto-raises a Work Order; high-impact requests escalate). The
 * routing policy is data — operators tune queues per market.
 */
class AsrService
{
    /** ASR type -> ticket category. */
    private const CATEGORY = [
        'TECHNICAL_TROUBLE' => 'TECHNICAL',
        'INFORMATION_REQUEST' => 'INFORMATION',
        'COMPLAINT' => 'COMPLAINT',
        'SERVICE_REQUEST' => 'SERVICE_REQUEST',
    ];

    public function __construct(
        private readonly TicketService $tickets,
        private readonly RuleEngine $rules,
        private readonly AccountService $accounts,
    ) {}

    /** @param array<string,mixed> $data asr_type, subject, description?, customer_id?, account_id?, subscription_id?, opened_by? */
    public function create(array $data): Ticket
    {
        $asrType = $data['asr_type'];
        // Feed the routing rule the *full* decision context — operator/market, service
        // class, account state, customer segment, VIP/loyalty flags — not just the ASR
        // type. The policy stays data; we only supply facts, so an operator can route on
        // whatever a given country needs (e.g. VIP → dedicated queue) without code.
        $facts = array_merge(
            $this->accounts->routingContext($data['account_id'] ?? null, $data['customer_id'] ?? null),
            [
                'asrType' => $asrType,
                'channel' => $data['channel'] ?? $data['source_channel'] ?? null,
                'priorityHint' => $data['priority'] ?? null,
                'subscriptionId' => $data['subscription_id'] ?? null,
                'subcategory' => $data['subcategory'] ?? null,
            ],
        );
        $routing = $this->rules->evaluate('rules.asr.routing', $facts);

        $ticket = $this->tickets->create([
            'asr_type' => $asrType,
            'category' => self::CATEGORY[$asrType] ?? 'INFORMATION',
            'queue' => $routing['queue'] ?? 'GENERAL',
            'priority' => $data['priority'] ?? ($routing['priority'] ?? 'NORMAL'),
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'account_id' => $data['account_id'] ?? null,
            'subscription_id' => $data['subscription_id'] ?? null,
            'opened_by' => $data['opened_by'] ?? null,
        ]);

        // ASR-01 Technical Trouble auto-raises a field Work Order.
        if (($routing['autoCreateWorkOrder'] ?? false) && $ticket->customer_id) {
            $this->tickets->createWorkOrder($ticket, [
                'type' => 'SUPPORT', 'kind' => 'SUPPORT', 'job_type_code' => 'HS3',
                'customer_id' => $ticket->customer_id, 'subscription_id' => $ticket->subscription_id,
            ], $data['opened_by'] ?? null);
        }

        return $ticket->refresh();
    }
}
