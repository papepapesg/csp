<?php

namespace Modules\ItOps\Logging;

use App\Foundation\Support\Context;
use Illuminate\Support\Facades\Schema;
use Modules\ItOps\Models\SystemLog;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/**
 * Monolog handler that persists log records to system_log so IT-Ops can search
 * them. Silently no-ops if the table is absent (boot/migration) so logging never
 * breaks the app.
 */
class DatabaseLogHandler extends AbstractProcessingHandler
{
    private static ?bool $tableExists = null;

    protected function write(LogRecord $record): void
    {
        try {
            if (self::$tableExists === null) {
                self::$tableExists = Schema::hasTable('system_log');
            }
            if (! self::$tableExists) {
                return;
            }

            SystemLog::query()->create([
                'level' => strtolower($record->level->getName()),
                'channel' => $record->channel,
                'message' => $record->message,
                'context' => $record->context ?: null,
                'correlation_id' => Context::correlationId(),
                'logged_at' => $record->datetime,
            ]);
        } catch (\Throwable) {
            // Never let logging failures cascade.
        }
    }
}
