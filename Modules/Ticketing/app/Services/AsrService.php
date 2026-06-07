<?php

namespace Modules\Ticketing\Services;

use App\Foundation\Rules\RuleEngine;
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
    ) {}

    /** @param array<string,mixed> $data asr_type, subject, description?, customer_id?, account_id?, subscription_id?, opened_by? */
    public function create(array $data): Ticket
    {
        $asrType = $data['asr_type'];
        $routing = $this->rules->evaluate('rules.asr.routing', ['asrType' => $asrType]);

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
