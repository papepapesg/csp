<?php

namespace Modules\Billing\Providers;

use App\Foundation\Events\OutboxEventPublished;
use App\Foundation\Rules\RuleEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Adapters\StubTaxGateway;
use Modules\Billing\Console\CycleCloseCommand;
use Modules\Billing\Console\DunningRunCommand;
use Modules\Billing\Console\GenerationFailureRetryCommand;
use Modules\Billing\Console\ProFormaScanCommand;
use Modules\Billing\Console\RateUsageCommand;
use Modules\Billing\Console\RunCycleBillingCommand;
use Modules\Billing\Console\TaxRetryScanCommand;
use Modules\Billing\Console\TaxSignScanCommand;
use Modules\Billing\Console\WalletExpiryCommand;
use Modules\Billing\Contracts\TaxGateway;
use Modules\Billing\Listeners\ApplyCreditBalanceOnInvoice;
use Modules\Billing\Listeners\DunningEventBridge;
use Modules\Billing\Listeners\EvictPlmCatalogCache;
use Modules\Billing\Listeners\RetryFrozenCycleOnTopup;
use Modules\Billing\Listeners\TaxEventBridge;
use Modules\Billing\Services\AdjustmentService;
use Modules\Billing\Tax\TaxSignerRegistry;

/** Binds the tax-fiscalisation gateway (driver via SOPHIX_TAX_DRIVER). */
class BillingRuntimeProvider extends ServiceProvider
{
    public function boot(): void
    {
        // FOUNDATION_CACHE: evict cached PLM wallet-catalog copies on Wallet* events.
        Event::listen(OutboxEventPublished::class, [EvictPlmCatalogCache::class, 'handle']);

        // BIL-03: a wallet top-up may unfreeze a prepaid cycle that missed payment.
        Event::listen(OutboxEventPublished::class, [RetryFrozenCycleOnTopup::class, 'handle']);

        // BIL-01-PAY-01 OV-2: a newly issued invoice auto-draws any account credit balance.
        Event::listen(OutboxEventPublished::class, [ApplyCreditBalanceOnInvoice::class, 'handle']);

        // BIL-04: prepaid CyclePaymentMissed → enter dunning; voluntary pause/resume
        // → suspend/resume dunning; WalletToppedUp → prepaid recovery.
        Event::listen(OutboxEventPublished::class, [DunningEventBridge::class, 'handle']);

        // BIL-02-TAX-01: every payment moment (PaymentApplied / WalletToppedUp /
        // PaymentReceived) generates a tax invoice when the operator has it enabled.
        Event::listen(OutboxEventPublished::class, [TaxEventBridge::class, 'handle']);

        // ADJ-01 approval routing fallback: when no decision table is deployed
        // for rules.billing.adjustment-approval, derive the same answer from
        // adjustment_limits_config (steps + auto_approve_under threshold).
        $this->app->make(RuleEngine::class)->register(AdjustmentService::APPROVAL_RULE_SET, function (array $facts) {
            $config = DB::table('adjustment_limits_config')->where('operator_code', $facts['operatorCode'] ?? '')->first();
            $steps = (int) ($config->approval_steps_required ?? 1);
            if ($config?->auto_approve_under !== null && (float) ($facts['amount'] ?? 0) < (float) $config->auto_approve_under) {
                $steps = 0;
            }

            return ['stepsRequired' => $steps, 'ruleId' => 'FALLBACK-ADJ-LIMITS-CONFIG'];
        });

        if ($this->app->runningInConsole()) {
            $this->commands([DunningRunCommand::class, RateUsageCommand::class, RunCycleBillingCommand::class, CycleCloseCommand::class, WalletExpiryCommand::class, ProFormaScanCommand::class, TaxSignScanCommand::class, TaxRetryScanCommand::class, GenerationFailureRetryCommand::class,
                \Modules\Billing\Console\OpsStatusCommand::class, \Modules\Billing\Console\DunningShowCommand::class, \Modules\Billing\Console\DunningFixCommand::class]);
        }
    }

    public function register(): void
    {
        $this->app->singleton(TaxGateway::class, function () {
            return match (config('sophix.tax_driver', 'stub')) {
                default => new StubTaxGateway,
            };
        });
        // BIL-02-TAX-01 signer registry caches initialized signers; keep it a singleton.
        $this->app->singleton(TaxSignerRegistry::class);
    }
}
