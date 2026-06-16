<?php

namespace Modules\Workflow\Contracts;

/**
 * Declarative IO-port builders for a TaskHandler's signature (the "function shape"
 * of a node type). A handler MAY expose inputs()/outputs() returning arrays of
 * these descriptors; the studio reads them to render the node's config panel
 * (description, default, mapping) and the engine + GraphValidator use them to
 * resolve and validate the data wiring (source.output -> target.input).
 *
 * A node INSTANCE in a flow binds each input either by a literal in data.config,
 * or by a wire in data.inputMappings (see WorkflowEngine::resolveInputs). Outputs
 * are what the handler returns via TaskResult::success([...]).
 */
final class Io
{
    public const STRING = 'string';

    public const NUMBER = 'number';

    public const BOOLEAN = 'boolean';

    public const ENUM = 'enum';

    public const OBJECT = 'object';

    /**
     * An input port.
     *
     * @param  array<int,string>|null  $options  allowed values when type = enum
     * @return array<string,mixed>
     */
    public static function in(string $name, string $type, string $description, bool $required = false, mixed $default = null, ?array $options = null): array
    {
        $port = [
            'name' => $name,
            'type' => $type,
            'description' => $description,
            'required' => $required,
            'default' => $default,
        ];
        if ($options !== null) {
            $port['options'] = $options;
        }

        return $port;
    }

    /**
     * An output port (a variable the handler publishes back into the instance).
     *
     * @return array<string,mixed>
     */
    public static function out(string $name, string $type, string $description): array
    {
        return ['name' => $name, 'type' => $type, 'description' => $description];
    }
}
