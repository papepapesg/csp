<?php

namespace App\Foundation\Operations;

use Illuminate\Console\Command;

/**
 * Shared presentation and exit-code contract for module-owned operational checks.
 * Implementations remain read-only and return metrics; they never repair state.
 */
abstract class OpsStatusCommand extends Command
{
    /** @return list<array{key:string,label:string,count:int,severity?:string,hint?:string}> */
    abstract protected function metrics(?string $operator): array;

    abstract protected function moduleLabel(): string;

    public function handle(): int
    {
        $operator = $this->option('operator');
        $metrics = $this->metrics(is_string($operator) && $operator !== '' ? $operator : null);
        $alerts = array_values(array_filter(
            $metrics,
            fn (array $metric): bool => $metric['count'] > 0 && in_array($metric['severity'] ?? 'info', ['warning', 'critical'], true),
        ));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'module' => $this->moduleLabel(),
                'operator' => $operator ?: null,
                'healthy' => $alerts === [],
                'metrics' => $metrics,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($this->moduleLabel().' ops status'.($operator ? " — operator {$operator}" : ' — all operators'));
            $this->table(['Check', 'Count', 'Severity'], array_map(
                fn (array $metric): array => [$metric['label'], $metric['count'], strtoupper($metric['severity'] ?? 'info')],
                $metrics,
            ));

            foreach ($alerts as $alert) {
                if (! empty($alert['hint'])) {
                    $this->line('Hint: '.$alert['hint']);
                }
            }
        }

        return $alerts !== [] && (bool) $this->option('fail-on-alert') ? self::FAILURE : self::SUCCESS;
    }
}
