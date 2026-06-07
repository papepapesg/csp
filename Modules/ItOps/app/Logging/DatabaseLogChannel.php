<?php

namespace Modules\ItOps\Logging;

use Monolog\Logger;

/** Custom Monolog channel factory writing to the system_log table. */
class DatabaseLogChannel
{
    public function __invoke(array $config): Logger
    {
        return new Logger('database', [new DatabaseLogHandler($config['level'] ?? 'debug')]);
    }
}
