<?php

namespace Modules\Ilm\Console;

use Illuminate\Console\Command;
use Modules\Ilm\Models\Customer;

/**
 * Ops review: the KYC work queue (read-only) — counts of customers by derived
 * kyc_status, then lists the ones still mid-flow (PENDING awaiting L1, or
 * L1_APPROVED awaiting final) so an operator can see what the EM-CFG-04 KYC
 * approval chain is waiting on. Inspect only; decisions go through the approval engine.
 */
class KycQueueCommand extends Command
{
    protected $signature = 'sophix:ilm:kyc-queue
        {--operator= : Scope to one operator code (default: all)}
        {--limit=25 : Max customers to list per pending state}';

    protected $description = 'Review: customers by KYC status and those stuck mid-approval (read-only)';

    public function handle(): int
    {
        $op = $this->option('operator');
        $limit = max(1, (int) $this->option('limit'));
        $scope = fn () => Customer::query()->when($op, fn ($q) => $q->where('operator_code', $op));

        $this->info('KYC queue'.($op ? " — operator {$op}" : ' — all operators'));
        $this->table(['kyc_status', 'count'], [
            [Customer::KYC_PENDING, $scope()->where('kyc_status', Customer::KYC_PENDING)->count()],
            [Customer::KYC_L1_APPROVED, $scope()->where('kyc_status', Customer::KYC_L1_APPROVED)->count()],
            [Customer::KYC_APPROVED, $scope()->where('kyc_status', Customer::KYC_APPROVED)->count()],
            [Customer::KYC_REJECTED, $scope()->where('kyc_status', Customer::KYC_REJECTED)->count()],
        ]);

        foreach ([Customer::KYC_PENDING => 'awaiting L1 approval', Customer::KYC_L1_APPROVED => 'awaiting final approval'] as $status => $label) {
            $rows = $scope()->where('kyc_status', $status)->orderBy('created_at')->limit($limit)->get();
            if ($rows->isEmpty()) {
                continue;
            }
            $this->line('');
            $this->info("{$status} ({$label}) — showing up to {$limit}");
            $this->table(['customer_id', 'operator_code', 'name', 'primary_msisdn', 'created_at'], $rows->map(fn (Customer $c) => [
                $c->customer_id,
                $c->operator_code,
                $c->name,
                $c->primary_msisdn,
                (string) $c->created_at,
            ])->all());
        }

        return self::SUCCESS;
    }
}
