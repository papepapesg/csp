<?php

namespace Modules\Ilm\Services;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Ilm\Events\IlmEvents;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Ilm\Models\CustomerAccountFlag;
use Modules\Ilm\Models\CustomerAccountFlagCatalog;
use Modules\Ilm\Models\CustomerSubStatusCatalog;

/**
 * Authoritative writes for Customer Accounts (ILM-CFG-01 §2.2).
 */
class AccountService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CustomerAccount
    {
        return DB::transaction(function () use ($data) {
            $account = CustomerAccount::query()->create($data);

            $this->events->publish(new DomainEvent(
                type: IlmEvents::CUSTOMER_ACCOUNT_CREATED,
                topic: IlmEvents::TOPIC,
                payload: [
                    'accountId' => $account->account_id,
                    'accountNumber' => $account->account_number,
                    'customerId' => $account->customer_id,
                ],
                aggregateType: 'CustomerAccount',
                aggregateId: $account->account_id,
            ));

            return $account;
        });
    }

    /**
     * Update account fields. A status/sub-status change records the timestamp
     * and emits a status-changed event (history table is a later hardening item).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(CustomerAccount $account, array $data): CustomerAccount
    {
        // The operator's sub-status catalog is config that DRIVES the change: it validates
        // the code, supplies the main status it clones from, and says whether the change
        // needs an approval reference and whether it affects provisioning. An operator
        // tunes any of that by editing the catalog row — no code change.
        $catalog = null;
        if (isset($data['sub_status'])
            && CustomerSubStatusCatalog::query()->where('operator_code', $account->operator_code)->exists()) {
            $catalog = CustomerSubStatusCatalog::query()
                ->where('operator_code', $account->operator_code)->where('sub_status_code', $data['sub_status'])->first();
            if (! $catalog) {
                throw DomainException::ruleRejected('UNKNOWN_SUB_STATUS', "Sub-status {$data['sub_status']} is not in the operator's registry.");
            }
            // R-ILM-S-2: a requires_approval sub-status routes through the EM-CFG-04 engine — which
            // supports BOTH a single approver (flat) and an ordered chain, per the seeded policy. The
            // transition is HELD until the approval clears; ApplySubStatusOnApproval applies it on
            // ApprovalApproved. (`_subStatusApproved` is the internal flag that listener sets to apply.)
            if ($catalog->requires_approval && empty($data['_subStatusApproved'])) {
                $request = $this->approvals->request([
                    'operator_code' => $account->operator_code,
                    'entity_type' => 'CUSTOMER_SUB_STATUS',
                    'action' => $data['sub_status'],
                    'entity_ref' => $account->account_id,
                    'payload' => ['change' => Arr::except($data, ['_subStatusApproved'])],
                    'requested_by' => $data['updated_by'] ?? null,
                ]);
                if ($request->status !== ApprovalRequest::AUTO_APPROVED) {
                    return $account; // held — sub-status unchanged until the approval (single or chained) clears
                }
                $data['approval_reference'] = $request->request_id; // no policy ⇒ auto-approved ⇒ apply now
            }
            // Main status is DERIVED from the catalog (cloned_from), not trusted from the caller.
            $data['status'] = $catalog->main_status;
        }

        return DB::transaction(function () use ($account, $data, $catalog) {
            $statusChanged = (isset($data['status']) && $data['status'] !== $account->status)
                || (isset($data['sub_status']) && $data['sub_status'] !== $account->sub_status);
            $before = ['status' => $account->status, 'sub_status' => $account->sub_status];

            if ($statusChanged) {
                $data['sub_status_changed_at'] = now();
            }

            $account->update(array_diff_key($data, ['approval_reference' => null, '_subStatusApproved' => null]));

            if ($statusChanged) {
                // Append-only history (the Customer 360 status timeline). R-ILM-S-1.
                DB::table('account_status_history')->insert([
                    'account_id' => $account->account_id,
                    'operator_code' => $account->operator_code,
                    'prev_status' => $before['status'], 'new_status' => $account->status,
                    'prev_sub_status' => $before['sub_status'], 'new_sub_status' => $account->sub_status,
                    'reason' => $data['sub_status_reason'] ?? null,
                    'approval_reference' => $data['approval_reference'] ?? null,
                    'changed_by' => $data['updated_by'] ?? null,
                    'changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                // R-ILM-S-3 / §8.2: the event carries affects_provisioning + customer_visible
                // (from the catalog) so FUL-03 and DD_NOT-01 decide without re-reading config.
                $this->events->publish(new DomainEvent(
                    type: IlmEvents::CUSTOMER_ACCOUNT_STATUS_CHANGED,
                    topic: IlmEvents::TOPIC,
                    payload: [
                        'accountId' => $account->account_id,
                        'status' => $account->status,
                        'subStatus' => $account->sub_status,
                        'affectsProvisioning' => (bool) ($catalog?->affects_provisioning ?? false),
                        'customerVisible' => (bool) ($catalog?->customer_visible ?? true),
                    ],
                    aggregateType: 'CustomerAccount',
                    aggregateId: $account->account_id,
                ));
            }

            return $account;
        });
    }

    /**
     * ILM-CFG-01 §3.5 set (raise/update) an account flag. The flag must exist in the
     * operator catalog. A flag that surfaces attention also writes the account's
     * attention_banner. @param array{bool?:bool,score?:int,text?:string} $value
     */
    public function setFlag(CustomerAccount $account, string $flagCode, array $value = [], ?string $source = 'MANUAL', ?string $actor = null): CustomerAccountFlag
    {
        $catalog = CustomerAccountFlagCatalog::resolve($account->operator_code, $flagCode);
        if (! $catalog || ! $catalog->active) {
            throw DomainException::ruleRejected('UNKNOWN_FLAG', "Flag {$flagCode} is not in the operator catalog.");
        }

        return DB::transaction(function () use ($account, $flagCode, $value, $source, $actor, $catalog) {
            $flag = CustomerAccountFlag::query()->updateOrCreate(
                ['account_id' => $account->account_id, 'flag_code' => $flagCode],
                [
                    'operator_code' => $account->operator_code,
                    'bool_value' => $value['bool'] ?? ($catalog->value_kind === 'BOOLEAN' ? true : null),
                    'score_value' => $value['score'] ?? null,
                    'text_value' => $value['text'] ?? null,
                    'state' => CustomerAccountFlag::ACTIVE,
                    'source' => $source,
                    'set_by' => $actor,
                    'set_at' => now(),
                ],
            );

            if ($catalog->surfaces_attention && ! $account->attention_banner) {
                $account->update(['attention_banner' => $catalog->name]);
            }

            $this->events->publish(new DomainEvent(
                type: IlmEvents::ACCOUNT_FLAG_SET,
                topic: IlmEvents::TOPIC,
                payload: ['accountId' => $account->account_id, 'flagCode' => $flagCode, 'source' => $source],
                aggregateType: 'CustomerAccount',
                aggregateId: $account->account_id,
            ));

            return $flag;
        });
    }

    public function clearFlag(CustomerAccount $account, string $flagCode, ?string $actor = null): void
    {
        $flag = CustomerAccountFlag::query()->where('account_id', $account->account_id)->where('flag_code', $flagCode)->first();
        if (! $flag || $flag->state === CustomerAccountFlag::CLEARED) {
            return;
        }
        $flag->update(['state' => CustomerAccountFlag::CLEARED, 'set_by' => $actor, 'set_at' => now()]);
        // Recompute the attention banner from the REMAINING active flags — clearing the
        // last attention-surfacing flag must clear the banner (it was previously left stale).
        $this->recomputeAttentionBanner($account);
        $this->events->publish(new DomainEvent(
            type: IlmEvents::ACCOUNT_FLAG_CLEARED,
            topic: IlmEvents::TOPIC,
            payload: ['accountId' => $account->account_id, 'flagCode' => $flagCode],
            aggregateType: 'CustomerAccount',
            aggregateId: $account->account_id,
        ));
    }

    /** The banner = the name of an active flag whose catalog surfaces_attention, else null. */
    private function recomputeAttentionBanner(CustomerAccount $account): void
    {
        $activeCodes = $this->activeFlags($account)->pluck('flag_code');
        $banner = $activeCodes->isEmpty() ? null : CustomerAccountFlagCatalog::query()
            ->where('operator_code', $account->operator_code)
            ->whereIn('flag_code', $activeCodes->all())
            ->where('surfaces_attention', true)
            ->orderBy('flag_code')
            ->value('name');
        $account->update(['attention_banner' => $banner]);
    }

    /** @return Collection<int,CustomerAccountFlag> active flags. */
    public function activeFlags(CustomerAccount $account): Collection
    {
        return CustomerAccountFlag::query()->where('account_id', $account->account_id)->where('state', CustomerAccountFlag::ACTIVE)->get();
    }

    /**
     * R-ILM-F-4: does the account carry an ACTIVE flag whose catalog marks it
     * affects_provisioning (e.g. FRAUD_SUSPECTED)? FUL-03 reads this to block new
     * service activation while such a flag stands.
     */
    public function hasProvisioningBlockingFlag(CustomerAccount $account): bool
    {
        $active = $this->activeFlags($account)->pluck('flag_code');
        if ($active->isEmpty()) {
            return false;
        }

        return CustomerAccountFlagCatalog::query()
            ->where('operator_code', $account->operator_code)
            ->whereIn('flag_code', $active->all())
            ->where('affects_provisioning', true)
            ->exists();
    }

    /**
     * R-ILM-F-3: does the account carry an ACTIVE flag whose catalog marks it affects_dunning
     * (e.g. NPD)? BIL-04 reads this before deciding the dunning level to escalate faster.
     * Keyed by account_id so the dunning scanner can ask without loading the account model.
     */
    public function hasDunningAccelerantFlag(string $accountId, ?string $operator = null): bool
    {
        $active = CustomerAccountFlag::query()
            ->where('account_id', $accountId)->where('state', CustomerAccountFlag::ACTIVE)->pluck('flag_code');
        if ($active->isEmpty()) {
            return false;
        }

        return CustomerAccountFlagCatalog::query()
            ->when($operator, fn ($q) => $q->where('operator_code', $operator))
            ->whereIn('flag_code', $active->all())
            ->where('affects_dunning', true)
            ->exists();
    }

    /**
     * Read-only decision context for an account/customer (ILM-CFG-01). Returns plain
     * data so any module can feed a *complete* fact set into its rule engine without
     * reading ILM tables directly (cross-module rule). Every routing-relevant attribute
     * lives here — operator/market, service class, account state, customer segment, and
     * the VIP/loyalty flags — so a data-driven policy can branch on whatever a given
     * country needs. Safe (empty-ish) when the references are unknown.
     *
     * @return array<string,mixed>
     */
    public function routingContext(?string $accountId, ?string $customerId = null): array
    {
        $account = $accountId ? CustomerAccount::query()->where('account_id', $accountId)->first() : null;
        $customer = $account?->customer
            ?? ($customerId ? Customer::query()->where('customer_id', $customerId)->first() : null);
        $flags = $account ? $this->activeFlags($account) : collect();

        return [
            'operatorCode' => $account?->operator_code ?? $customer?->operator_code,
            'serviceClass' => $account?->service_class_1,
            'accountStatus' => $account?->status,
            'accountSubStatus' => $account?->sub_status,                         // 'vip', 'staff_account', …
            'customerType' => $customer?->type,                                  // RES | SME | ENT — segment proxy
            'vip' => $account?->sub_status === 'vip',                            // ILM-CFG-01 §3.5 VIP sub-status
            'highValue' => $flags->contains(fn ($f) => $f->flag_code === 'HIGH_VALUE'),
            'loyaltyTier' => optional($flags->firstWhere('flag_code', 'LOYALTY_TIER'))->text_value,
            'attentionBanner' => $account?->attention_banner,
            'flags' => $flags->pluck('flag_code')->values()->all(),
        ];
    }
}
