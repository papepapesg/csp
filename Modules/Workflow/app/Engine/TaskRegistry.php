<?php

namespace Modules\Workflow\Engine;

use Modules\Workflow\Contracts\TaskHandler;

/**
 * The step toolbox. Module service providers register reusable TaskHandlers by
 * topic; the engine resolves a handler when a service task runs, and the studio
 * lists the palette of available steps. New step = register a handler (code) +
 * it appears in the studio. New flow = compose registered topics (config).
 */
class TaskRegistry
{
    /** @var array<string, class-string<TaskHandler>> */
    private array $handlers = [];

    /** @param class-string<TaskHandler> $handlerClass */
    public function register(string $handlerClass): void
    {
        $topic = app($handlerClass)->topic();
        $this->handlers[$topic] = $handlerClass;
    }

    public function has(string $topic): bool
    {
        return isset($this->handlers[$topic]);
    }

    public function resolve(string $topic): ?TaskHandler
    {
        return isset($this->handlers[$topic]) ? app($this->handlers[$topic]) : null;
    }

    /** @return array<string> registered topics (for worker subscription) */
    public function topics(): array
    {
        return array_keys($this->handlers);
    }

    /** @return array<int,array{topic:string,label:string}> palette for the studio */
    public function palette(): array
    {
        $items = [];
        foreach ($this->handlers as $topic => $class) {
            $items[] = ['topic' => $topic, 'label' => app($class)->label()];
        }
        usort($items, fn ($a, $b) => $a['label'] <=> $b['label']);

        return $items;
    }
}
