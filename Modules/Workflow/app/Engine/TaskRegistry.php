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

    /**
     * The IO signature of a step (node type): its label and declared input/output
     * ports. inputs()/outputs() are optional on a handler — absent = no declared
     * ports (the step still runs; it just isn't visually wireable/validatable).
     * Pure in-process reflection — no DB, safe to call per node.
     *
     * @return array{label:string, inputs:array<int,array<string,mixed>>, outputs:array<int,array<string,mixed>>}
     */
    public function signature(string $topic): array
    {
        $handler = $this->resolve($topic);
        if (! $handler) {
            return ['label' => $topic, 'inputs' => [], 'outputs' => []];
        }

        return [
            'label' => $handler->label(),
            'inputs' => method_exists($handler, 'inputs') ? $handler->inputs() : [],
            'outputs' => method_exists($handler, 'outputs') ? $handler->outputs() : [],
        ];
    }

    /** @return array<int,array{topic:string,label:string,description:string}> palette for the studio */
    public function palette(): array
    {
        $items = [];
        foreach ($this->handlers as $topic => $class) {
            $handler = app($class);
            // A handler MAY expose description() to explain "what this step is for"; otherwise we
            // derive an honest default from its label + topic so the inspector always has copy.
            $description = method_exists($handler, 'description')
                ? $handler->description()
                : sprintf("Runs the '%s' step (service task '%s'). Idempotent — the engine may retry it.", $handler->label(), $topic);
            $items[] = [
                'topic' => $topic,
                'label' => $handler->label(),
                'description' => $description,
                // The studio renders these as the node's config panel: each input row is a
                // value (literal/default) or a wire (mapping), each output a connectable port.
                'inputs' => method_exists($handler, 'inputs') ? $handler->inputs() : [],
                'outputs' => method_exists($handler, 'outputs') ? $handler->outputs() : [],
            ];
        }
        usort($items, fn ($a, $b) => $a['label'] <=> $b['label']);

        return $items;
    }
}
