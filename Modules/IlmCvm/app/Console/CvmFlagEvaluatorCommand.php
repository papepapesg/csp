<?php

namespace Modules\Ilm\Cvm\Console;

use App\Foundation\Support\Context;
use Illuminate\Console\Command;
use Modules\Ilm\Cvm\Services\CvmFlagEvaluatorService;

/** EM-03 / CVM daily flag-evaluator worker (rule-driven retention/risk flags). */
class CvmFlagEvaluatorCommand extends Command
{
    protected $signature = 'sophix:cvm:evaluate-flags {--operator=}';

    protected $description = 'Evaluate accounts against CVM flag rules and raise/clear flags';

    public function handle(CvmFlagEvaluatorService $evaluator): int
    {
        $operator = $this->option('operator') ?: config('sophix.default_operator', 'WIK');
        Context::setOperatorCode($operator);
        $r = $evaluator->run($operator);
        $this->info("cvm flag eval: scanned {$r['scanned']}, flagged {$r['flagged']}");

        return self::SUCCESS;
    }
}
