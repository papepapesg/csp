<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OperationalCommandCoverageTest extends TestCase
{
    public function test_extracted_capability_modules_register_operational_status_commands(): void
    {
        $commands = Artisan::all();

        foreach ([
            'sophix:billing-adjustments:ops-status',
            'sophix:billing-intent:ops-status',
            'sophix:catalog-discount:ops-status',
            'sophix:catalog-network:ops-status',
            'sophix:catalog-rating:ops-status',
            'sophix:catalog-tax:ops-status',
            'sophix:icn:ops-status',
            'sophix:procurement:ops-status',
            'sophix:osr-swap:ops-status',
            'sophix:field-audit:ops-status',
        ] as $command) {
            $this->assertArrayHasKey($command, $commands, "Missing operational command {$command}");
        }
    }
}
