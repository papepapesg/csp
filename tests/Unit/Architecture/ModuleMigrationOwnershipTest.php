<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Every deployable capability owns a migration history in its module boundary. */
class ModuleMigrationOwnershipTest extends TestCase
{
    #[Test]
    public function every_module_contains_documentation_and_tests(): void
    {
        $root = dirname(__DIR__, 3);
        $missingDocumentation = [];
        $missingTests = [];

        foreach (glob($root.'/Modules/*/module.json') ?: [] as $manifest) {
            $modulePath = dirname($manifest);
            $module = basename($modulePath);

            if (! is_file($modulePath.'/README.md')) {
                $missingDocumentation[] = $module;
            } else {
                $documentation = (string) file_get_contents($modulePath.'/README.md');
                foreach (['Exposed APIs', 'Data models', 'Services', 'Events', 'Commands'] as $section) {
                    if (! str_contains($documentation, "## {$section}")) {
                        $missingDocumentation[] = "{$module}:{$section}";
                    }
                }
            }
            if (! is_dir($modulePath.'/tests')) {
                $missingTests[] = $module;
            }
        }

        self::assertSame([], $missingDocumentation, 'Missing module README.md: '.implode(', ', $missingDocumentation));
        self::assertSame([], $missingTests, 'Missing module tests directory: '.implode(', ', $missingTests));
    }

    #[Test]
    public function every_module_contains_at_least_one_migration(): void
    {
        $missing = [];
        $root = dirname(__DIR__, 3);

        foreach (glob($root.'/Modules/*/module.json') ?: [] as $manifest) {
            $modulePath = dirname($manifest);

            if ((glob($modulePath.'/database/migrations/*.php') ?: []) === []) {
                $missing[] = basename($modulePath);
            }
        }

        self::assertSame(
            [],
            $missing,
            'Every module must own at least one database migration. Missing: '.implode(', ', $missing),
        );
    }

    #[Test]
    public function migration_names_are_globally_unique(): void
    {
        $owners = [];
        $root = dirname(__DIR__, 3);

        foreach (glob($root.'/Modules/*/database/migrations/*.php') ?: [] as $migration) {
            $owners[basename($migration)][] = $migration;
        }

        $duplicates = array_filter($owners, static fn (array $paths): bool => count($paths) > 1);

        self::assertSame(
            [],
            $duplicates,
            'Migration basenames are global Laravel identifiers and must remain unique.',
        );
    }
}
