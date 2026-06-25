<?php

namespace Modules\Ilm\Console;

use Illuminate\Console\Command;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Ilm\Models\CustomerAccountFlag;
use Modules\Ilm\Models\KycApproval;

/**
 * Ops review: show one customer's master state (read-only) — identity, derived
 * kyc_status, the KYC approval trail, and every account with its status/sub-status,
 * attention banner and active flags. Lets support see why a customer/account is in
 * its current state before touching anything.
 */
class CustomerShowCommand extends Command
{
    protected $signature = 'sophix:ilm:customer-show {customer : The customer_id}';

    protected $description = 'Review: show a customer\'s master state, KYC and account flags (read-only)';

    public function handle(): int
    {
        $customerId = (string) $this->argument('customer');
        $customer = Customer::query()->where('customer_id', $customerId)->first();
        if (! $customer) {
            $this->warn("No customer {$customerId}.");

            return self::SUCCESS;
        }

        $this->info("Customer {$customer->customer_id}");
        $this->table(['Field', 'Value'], [
            ['operator_code', $customer->operator_code],
            ['type', $customer->type],
            ['name', $customer->name],
            ['primary_msisdn', $customer->primary_msisdn],
            ['email', $customer->email ?? '—'],
            ['kyc_status', $customer->kyc_status],
            ['created_at', (string) $customer->created_at],
        ]);

        if (in_array($customer->kyc_status, [Customer::KYC_PENDING, Customer::KYC_L1_APPROVED], true)) {
            $this->warn("kyc_status {$customer->kyc_status} — KYC is not yet fully approved (EM-CFG-04 approval pending).");
        }

        $approvals = KycApproval::query()->where('customer_id', $customerId)->orderBy('created_at')->get();
        if ($approvals->isNotEmpty()) {
            $this->line('');
            $this->info('KYC approval trail');
            $this->table(['level', 'level_name', 'decision', 'is_final', 'approver_id', 'at'], $approvals->map(fn (KycApproval $a) => [
                $a->approval_level,
                $a->approval_level_name ?? '—',
                $a->decision,
                $a->is_final ? 'yes' : 'no',
                $a->approver_id ?? '—',
                (string) $a->created_at,
            ])->all());
        }

        $accounts = CustomerAccount::query()->where('customer_id', $customerId)->orderBy('account_id')->get();
        $this->line('');
        $this->info("Accounts ({$accounts->count()})");
        $this->table(['account_id', 'account_number', 'status', 'sub_status', 'attention_banner', 'active_flags'], $accounts->map(function (CustomerAccount $a) {
            $flags = CustomerAccountFlag::query()
                ->where('account_id', $a->account_id)
                ->where('state', CustomerAccountFlag::ACTIVE)
                ->pluck('flag_code')->all();

            return [
                $a->account_id,
                $a->account_number,
                $a->status,
                $a->sub_status,
                $a->attention_banner ?? '—',
                $flags ? implode(', ', $flags) : '—',
            ];
        })->all());

        return self::SUCCESS;
    }
}
