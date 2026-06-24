<?php

namespace Modules\Ilm\Services;

use App\Foundation\Approvals\ApprovalDefinition;
use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Files\FileObject;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Ilm\Events\IlmEvents;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerKycDocument;
use Modules\Ilm\Models\KycApproval;

/**
 * Authoritative writes for the Customer master (ILM-CFG-01).
 *
 * Each mutation persists state and records a domain event in the same DB
 * transaction (outbox pattern) so downstream modules react to committed facts.
 */
class CustomerService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly ApprovalService $approvals,
    ) {}

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
    /**
     * ILM-CFG-01 §5.3 — record a captured KYC document. The binary already lives in
     * FOUNDATION_FILE_STORAGE; we resolve the foundation file by id (so a customer can
     * never reference a non-existent object), keep only the reference + authoritative
     * mime/size/content_hash (R-ILM-K-6), and supersede any prior document of the same
     * type rather than hard-deleting it (R-ILM-K-8).
     *
     * @param  array<string,mixed>  $data  file_id, document_type, document_name?
     */
    public function recordKycDocument(Customer $customer, array $data, ?string $actor = null): CustomerKycDocument
    {
        $file = FileObject::query()->where('file_id', $data['file_id'])->first();
        if (! $file) {
            throw new DomainException('KYC_DOCUMENT_FILE_NOT_FOUND', 'The referenced file is not registered in file storage.', 404);
        }

        return DB::transaction(function () use ($customer, $data, $actor, $file) {
            $document = CustomerKycDocument::query()->create([
                'document_id' => Id::make('kycdoc'),
                'customer_id' => $customer->customer_id,
                'operator_code' => $customer->operator_code,
                'document_type' => $data['document_type'],
                'document_name' => $data['document_name'] ?? $file->filename,
                'file_id' => $file->file_id,
                'storage_path' => $file->path,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
                'content_hash' => $file->checksum, // SHA-256 from the foundation
                'captured_by' => $actor,
            ]);

            // R-ILM-K-8: a newer document of the same type supersedes the prior one (audit kept).
            CustomerKycDocument::query()
                ->where('customer_id', $customer->customer_id)
                ->where('document_type', $data['document_type'])
                ->where('document_id', '!=', $document->document_id)
                ->whereNull('superseded_by_id')
                ->update(['superseded_by_id' => $document->document_id, 'superseded_at' => now()]);

            $this->events->publish(new DomainEvent(
                type: IlmEvents::CUSTOMER_UPDATED,
                topic: IlmEvents::TOPIC,
                payload: ['customerId' => $customer->customer_id, 'kycDocumentId' => $document->document_id, 'documentType' => $document->document_type],
                aggregateType: 'Customer',
                aggregateId: $customer->customer_id,
            ));

            return $document;
        });
    }

    public function recordKycDecision(Customer $customer, int $level, string $decision, array $meta = []): KycApproval
    {
        $isFinal = $level >= 2;

        // KYC IS an approval: it runs on the EM-CFG-04 engine like every other gated action. The
        // two levels (L1 supervisor → final) are an ordered two-stage chain; each decision here is a
        // decide() on the current stage. The engine owns WHO may act (the configured role per stage,
        // or SUPER_ADMIN) and the distinct-approver rule; this method owns the KYC state machine that
        // the chain's progress drives (PENDING → L1_APPROVED → APPROVED, or REJECTED).
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
            $request = $this->openKycRequest($customer);
            $actor = $meta['actor'] ?? null;

            try {
                // The engine enforces the stage's configured role (R-ILM-K-3) and ordering. A reject at
                // any stage fails the whole chain; an approve clears the current stage and advances.
                $request = $this->approvals->decide($request, $decision !== 'REJECTED', $actor, $meta['comments'] ?? null);
            } catch (DomainException $e) {
                // Preserve the KYC error contract: the engine's generic authorisation failure surfaces as
                // the KYC-specific code clients already handle.
                if ($e->errorCode === 'APPROVER_NOT_AUTHORIZED') {
                    throw new DomainException('KYC_APPROVER_ROLE_REQUIRED', "KYC level {$level} requires the configured approver role.", 403, previous: $e);
                }
                throw $e;
            }

            $approval = KycApproval::query()->create([
                'customer_id' => $customer->customer_id,
                'approval_level' => $level,
                'approval_level_name' => $meta['approvalLevelName'] ?? ($isFinal ? 'FINAL' : 'L1_SUPERVISOR'),
                'decision' => $decision,
                'is_final' => $isFinal,
                'approver_id' => $meta['approverId'] ?? $actor?->uid,
                'approver_role' => $meta['approverRole'] ?? null,
                'comments' => $meta['comments'] ?? null,
            ]);

            // kyc_status is DERIVED from the chain's progress, not set independently.
            $newStatus = match (true) {
                $request->status === ApprovalRequest::REJECTED => Customer::KYC_REJECTED,
                $request->status === ApprovalRequest::APPROVED => Customer::KYC_APPROVED,
                default => Customer::KYC_L1_APPROVED, // still PENDING, but stage 1 cleared
            };
            $customer->update(['kyc_status' => $newStatus]);

            $this->events->publish(new DomainEvent(
                type: $decision === 'REJECTED' ? IlmEvents::CUSTOMER_KYC_REJECTED : IlmEvents::CUSTOMER_KYC_APPROVED,
                topic: IlmEvents::TOPIC,
                payload: ['customerId' => $customer->customer_id, 'kycStatus' => $newStatus, 'level' => $level, 'approvalRequestId' => $request->request_id],
                aggregateType: 'Customer',
                aggregateId: $customer->customer_id,
            ));

            return $approval;
        });
    }

    /**
     * The open EM-CFG-04 request driving this customer's KYC, creating it (and seeding the chain) on
     * the first decision. requested_by is null on purpose: KYC has no single "requester" to segregate
     * from, so leaving it unset keeps SoD from blocking a legitimate approver (e.g. a SUPER_ADMIN who
     * clears both stages in the two-step flow).
     */
    private function openKycRequest(Customer $customer): ApprovalRequest
    {
        $request = ApprovalRequest::query()
            ->where('entity_type', 'CUSTOMER_KYC')
            ->where('entity_ref', $customer->customer_id)
            ->where('status', ApprovalRequest::PENDING)
            ->latest('created_at')->first();
        if ($request) {
            return $request;
        }

        $this->seedKycChain($customer->operator_code);

        return $this->approvals->request([
            'operator_code' => $customer->operator_code,
            'entity_type' => 'CUSTOMER_KYC',
            'entity_ref' => $customer->customer_id,
            'requested_by' => null,
        ]);
    }

    /**
     * Declare the operator's CUSTOMER_KYC chain from the kyc_approval_role config: a fixed two-stage
     * ROLE chain (L1 → final). A level with no config row is left ungated (open role pool) so anyone
     * permitted may clear it — preserving the prior "no row means that level isn't gated" behaviour.
     * Idempotent; the resolved chain is frozen onto each request when it is raised.
     */
    private function seedKycChain(string $operator): void
    {
        $cfg = DB::table('kyc_approval_role')
            ->where('operator_code', $operator)
            ->whereIn('approval_level', [1, 2])
            ->get()->keyBy('approval_level');

        $stage = function (int $lvl, string $defaultName) use ($cfg): array {
            $row = $cfg->get($lvl);

            return [
                'name' => $row->level_name ?? $defaultName,
                'approver_kind' => 'ROLE',
                'approver_roles' => $row ? [$row->required_role] : [],
                'required_approvals' => 1,
            ];
        };

        ApprovalDefinition::defineChain($operator, 'CUSTOMER_KYC', null, [
            $stage(1, 'L1_SUPERVISOR'),
            $stage(2, 'FINAL'),
        ]);
    }
}
