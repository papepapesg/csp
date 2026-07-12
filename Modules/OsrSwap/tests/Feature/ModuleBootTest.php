<?php

namespace Modules\OsrSwap\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ModuleBootTest extends TestCase
{
    public function test_module_registers_its_operations_command(): void
    {
        self::assertArrayHasKey('sophix:osr-swap:ops-status', Artisan::all());
    }
}
