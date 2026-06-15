<?php

namespace Tests\Unit;

use App\Foundation\Validation\JsonSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The shared jsonb payload validator: type, required, enum, numeric ranges (incl. x>2 via
 * exclusiveMinimum), patterns, nested objects, additionalProperties. Used for WO note payloads
 * and any other schema_jsonb-backed column.
 */
class JsonSchemaValidatorTest extends TestCase
{
    private function v(): JsonSchemaValidator
    {
        return new JsonSchemaValidator;
    }

    public function test_required_and_type(): void
    {
        $schema = ['type' => 'object', 'required' => ['fault'], 'properties' => ['fault' => ['type' => 'string']]];
        $this->assertSame([], $this->v()->validate(['fault' => 'LOS'], $schema));
        $this->assertNotEmpty($this->v()->validate([], $schema));                         // missing required
        $this->assertNotEmpty($this->v()->validate(['fault' => 123], $schema));           // wrong type
    }

    public function test_numeric_ranges_including_x_gt_2(): void
    {
        $schema = ['type' => 'number', 'exclusiveMinimum' => 2];
        $this->assertSame([], $this->v()->validate(3, $schema));
        $this->assertNotEmpty($this->v()->validate(2, $schema));   // not > 2
        $this->assertNotEmpty($this->v()->validate(1, $schema));

        // GPON optical window: -40 < dBm <= -8
        $rx = ['type' => 'number', 'exclusiveMinimum' => -40, 'maximum' => -8];
        $this->assertSame([], $this->v()->validate(-18.2, $rx));
        $this->assertNotEmpty($this->v()->validate(-7.0, $rx));    // too high
        $this->assertNotEmpty($this->v()->validate(-41, $rx));     // too low
    }

    public function test_enum_pattern_and_additional_properties(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['fault', 'port'],
            'properties' => [
                'fault' => ['enum' => ['LOS', 'DROP_CUT']],
                'port' => ['type' => 'string', 'pattern' => '^[0-9]+/[0-9]+/[0-9]+$'],
            ],
            'additionalProperties' => false,
        ];
        $this->assertSame([], $this->v()->validate(['fault' => 'LOS', 'port' => '0/4/7'], $schema));
        $this->assertNotEmpty($this->v()->validate(['fault' => 'NOPE', 'port' => '0/4/7'], $schema));       // bad enum
        $this->assertNotEmpty($this->v()->validate(['fault' => 'LOS', 'port' => 'abc'], $schema));          // bad pattern
        $this->assertNotEmpty($this->v()->validate(['fault' => 'LOS', 'port' => '0/4/7', 'x' => 1], $schema)); // extra prop
    }

    public function test_nested_objects_and_arrays(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'serials' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
                'meta' => ['type' => 'object', 'required' => ['by'], 'properties' => ['by' => ['type' => 'string']]],
            ],
        ];
        $this->assertSame([], $this->v()->validate(['serials' => ['SN1'], 'meta' => ['by' => 'tech']], $schema));
        $this->assertNotEmpty($this->v()->validate(['serials' => [], 'meta' => ['by' => 'tech']], $schema));   // minItems
        $this->assertNotEmpty($this->v()->validate(['serials' => ['SN1'], 'meta' => []], $schema));            // nested required
    }
}
