<?php

namespace Modules\Billing\Tax;

use Modules\Billing\Tax\Models\TaxOperatorConfig;

/**
 * Resolves the TaxInvoiceSigner for an operator from
 * tax_operator_config.signing_service_implementation_ref. The impl -> class map is CONFIG
 * (sophix.tax.signer_implementations), so onboarding a new authority is a signer class + a
 * config entry + the operator's config row — no framework edit. register() allows runtime
 * registration (e.g. a deployment shipping its own signer).
 */
class TaxSignerRegistry
{
    /** @var array<string,class-string<TaxInvoiceSigner>> */
    private array $registered = [];

    /** @var array<string,TaxInvoiceSigner> */
    private array $cache = [];

    public function register(string $implCode, string $signerClass): void
    {
        $this->registered[$implCode] = $signerClass;
    }

    /** @return array<string,class-string<TaxInvoiceSigner>> */
    private function implementations(): array
    {
        return $this->registered + config('sophix.tax.signer_implementations', []);
    }

    public function for(string $operator): ?TaxInvoiceSigner
    {
        $ref = TaxOperatorConfig::forOperator($operator)?->signing_service_implementation_ref ?? 'stub';

        return $this->byImpl($ref);
    }

    public function byImpl(string $implCode): ?TaxInvoiceSigner
    {
        if (isset($this->cache[$implCode])) {
            return $this->cache[$implCode];
        }
        $class = $this->implementations()[$implCode] ?? null;
        if (! $class) {
            return null;
        }

        return $this->cache[$implCode] = new $class();
    }
}
