<?php

namespace Modules\PaymentGateway\Console;

use Illuminate\Console\Command;
use Modules\PaymentGateway\Models\PaymentGatewayCallback;

/**
 * Ops review: a one-glance health summary of the inbound payment-gateway callback
 * queue an operator needs to watch (read-only). PAY-GW-01 callbacks are deduped on
 * (provider, external_ref) and routed to BIL; REJECTED rows are the ones that did
 * not apply (e.g. ACCOUNT_NOT_FOUND) and need follow-up.
 */
class OpsStatusCommand extends Command
{
    protected $signature = 'sophix:paymentgateway:ops-status {--operator= : Scope to one operator code (default: all)} {--provider= : Scope to one provider (MPESA|VISA|BANK_TRANSFER)}';

    protected $description = 'Review: counts of payment-gateway callbacks by status (read-only)';

    public function handle(): int
    {
        $op = $this->option('operator');
        $provider = $this->option('provider');
        $scope = function ($q) use ($op, $provider) {
            if ($op) {
                $q->where('operator_code', $op);
            }
            if ($provider) {
                $q->where('provider', $provider);
            }

            return $q;
        };

        $rows = [
            ['Received (not yet routed)', $scope(PaymentGatewayCallback::query())->where('status', PaymentGatewayCallback::RECEIVED)->count()],
            ['Processed (payment applied)', $scope(PaymentGatewayCallback::query())->where('status', PaymentGatewayCallback::PROCESSED)->count()],
            ['Rejected (needs follow-up)', $scope(PaymentGatewayCallback::query())->where('status', PaymentGatewayCallback::REJECTED)->count()],
            ['Duplicate (gateway retries)', $scope(PaymentGatewayCallback::query())->where('status', PaymentGatewayCallback::DUPLICATE)->count()],
        ];

        $label = 'Payment-gateway callback status';
        if ($op) {
            $label .= " — operator {$op}";
        }
        if ($provider) {
            $label .= " — provider {$provider}";
        }

        $this->info($label);
        $this->table(['Queue', 'Count'], $rows);
        $this->line('Inspect a row: sophix:paymentgateway:callback-show <callback_id>');

        return self::SUCCESS;
    }
}
