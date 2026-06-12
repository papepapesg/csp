<?php

namespace Modules\Ilm\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Ilm\Events\IlmEvents;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\KycApproval;

/**
 * Authoritative writes for the Customer master (ILM-CFG-01).
 *
 * Each mutation persists state and records a domain event in the same DB
 * transaction (outbox pattern) so downstream modules react to committed facts.
 */
class CustomerService
{
    public function __construct(private readonly EventBus $events) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $customer = Customer::query()->create($data);

            $this->events->publish(new DomainEvent(
                type: IlmEvents::CUSTOMER_CREATED,
                topic: IlmEvents::TOPIC,
                payload: ['customerId' => $customer->customer_id, 'name' => $customer->name],
                aggregateType: 'Customer',
                aggregateId: $customer->customer_id,
            ));

            return $customer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($customer, $data) {
            $customer->update($data);

            $this->events->publish(new DomainEvent(
                type: IlmEvents::CUSTOMER_UPDATED,
                topic: IlmEvents::TOPIC,
                payload: ['customerId' => $customer->customer_id],
                aggregateType: 'Customer',
                aggregateId: $customer->customer_id,
            ));

            return $customer;
        });
    }

    /**
     * Record a KYC decision and advance the derived kyc_status state machine.
     *
     * Levels: L1 (PENDING -> L1_APPROVED) then FINAL (L1_APPROVED -> APPROVED).
     * A reject at any level moves the customer to REJECTED.
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordKycDecision(Customer $customer, int $level, string $decision, array $meta = []): KycApproval
    {
        $isFinal = $level >= 2;

        // R-ILM-K-3: when the operator has configured KYC authority for this level, the
        // acting user must hold the configured role. The mapping is config (kyc_approval_role);
        // no row means the operator hasn't gated that level. SUPER_ADMIN is always authorized.
        $roleCfg = DB::table('kyc_approval_role')
            ->where('operator_code', $customer->operator_code)->where('approval_level', $level)->first();
        if ($roleCfg) {
            $actor = $meta['actor'] ?? null;
            $authorized = $actor && ($actor->hasRole($roleCfg->required_role) || $actor->hasRole('SUPER_ADMIN'));
            if (! $authorized) {
                throw new DomainException('KYC_APPROVER_ROLE_REQUIRED', "KYC level {$level} requires the '{$roleCfg->required_role}' role.", 403);
            }
        }

        if ($decision === 'APPROVED') {
            if ($isFinal && $customer->kyc_status !== Customer::KYC_L1_APPROVED) {
                throw DomainException::ruleRejected(
                    'KYC_L1_REQUIRED',
                    'Final approval requires an L1 approval first.',
                    nextAction: 'SUBMIT_L1_APPROVAL',
                );
            }
            if (! $isFinal && $customer->kyc_status !== Customer::KYC_PENDING) {
                throw DomainException::conflict('Customer is not pending L1 approval.');
            }
        }

        return DB::transaction(function () use ($customer, $level, $decision, $isFinal, $meta) {
            $approval = KycApproval::query()->create([
                'customer_id' => $customer->customer_id,
                'approval_level' => $level,
                'approval_level_name' => $meta['approvalLevelName'] ?? ($isFinal ? 'FINAL' : 'L1_SUPERVISOR'),
                'decision' => $decision,
                'is_final' => $isFinal,
                'approver_id' => $meta['approverId'] ?? null,
                'approver_role' => $meta['approverRole'] ?? null,
                'comments' => $meta['comments'] ?? null,
            ]);

            $newStatus = match (true) {
                $decision === 'REJECTED' => Customer::KYC_REJECTED,
                $isFinal => Customer::KYC_APPROVED,
                default => Customer::KYC_L1_APPROVED,
            };
            $customer->update(['kyc_status' => $newStatus]);

            $this->events->publish(new DomainEvent(
                type: $decision === 'REJECTED' ? IlmEvents::CUSTOMER_KYC_REJECTED : IlmEvents::CUSTOMER_KYC_APPROVED,
                topic: IlmEvents::TOPIC,
                payload: ['customerId' => $customer->customer_id, 'kycStatus' => $newStatus, 'level' => $level],
                aggregateType: 'Customer',
                aggregateId: $customer->customer_id,
            ));

            return $approval;
        });
    }
}
