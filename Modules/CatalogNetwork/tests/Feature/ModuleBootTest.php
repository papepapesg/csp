<?php

namespace Modules\CatalogNetwork\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ModuleBootTest extends TestCase
{
    public function test_module_registers_its_operations_command(): void
    {
        self::assertArrayHasKey('sophix:catalog-network:ops-status', Artisan::all());
    }
}
