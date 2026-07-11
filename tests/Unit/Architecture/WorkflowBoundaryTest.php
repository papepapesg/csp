<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

class WorkflowBoundaryTest extends TestCase
{
    public function test_capability_modules_depend_on_workflow_contract_not_engine_or_models(): void
    {
        $root = dirname(__DIR__, 3).'/Modules';
        $violations = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile()
                || $file->getExtension() !== 'php'
                || str_contains($file->getPathname(), '/Workflow/')
                || str_contains($file->getPathname(), '/tests/')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (str_contains($source, 'Modules\\Workflow\\Engine\\WorkflowEngine')) {
                $violations[] = str_replace(dirname(__DIR__, 3).'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $violations, 'Workflow boundary violations: '.implode(', ', $violations));
    }
}
