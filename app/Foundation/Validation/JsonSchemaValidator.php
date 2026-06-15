<?php

namespace App\Foundation\Validation;

/**
 * Lightweight JSON Schema validator (a JSON-Schema Draft-07 keyword SUBSET) for validating the
 * jsonb payloads stored across the platform — WO note payloads (wo_note.payload against
 * wo_note_kind_registry.schema_jsonb), field-audit snapshots, finalization configs, and any other
 * structured jsonb column — against a stored schema definition.
 *
 * Supported keywords (everything the platform's payloads need):
 *   structure : type (incl. unions ["string","null"]), properties (nested), additionalProperties,
 *               required, items
 *   strings   : minLength, maxLength, pattern (regex)
 *   numbers   : minimum, maximum, exclusiveMinimum, exclusiveMaximum, multipleOf
 *   any       : enum, const
 *
 * Expressiveness note (the "can we write x > 2?" question):
 *   - x > 2   →  {"type":"number","exclusiveMinimum":2}
 *   - x >= 2  →  {"type":"number","minimum":2}
 *   - 2..8    →  {"type":"integer","minimum":2,"maximum":8}
 *   - one of  →  {"enum":["A","B"]}     - shape →  {"type":"string","pattern":"^[A-Z]{3}$"}
 *
 * SCOPE: this validates STRUCTURE / SHAPE of a single document only. CROSS-FIELD or BUSINESS rules
 * (e.g. "x > y", "if fault=LOS then splice_db required", lookups against other tables) are NOT
 * expressible in JSON Schema and belong in the decision-table RULE ENGINE, not here.
 *
 * Contract is drop-in compatible with opis/json-schema, so this can be swapped for a full Draft
 * implementation later without touching callers: validate($data, $schema) => list<string> of errors.
 */
class JsonSchemaValidator
{
    /**
     * @param  array<string,mixed>  $schema
     * @return array<int,string>  human-readable violations; empty array == valid
     */
    public function validate(mixed $data, array $schema, string $path = '$'): array
    {
        if (isset($schema['type']) && ! $this->typeMatches($data, $schema['type'])) {
            return [sprintf('%s: expected %s, got %s', $path, implode('|', (array) $schema['type']), $this->typeOf($data))];
        }

        $errors = [];
        if (array_key_exists('const', $schema) && $data !== $schema['const']) {
            $errors[] = "{$path}: must equal ".json_encode($schema['const']);
        }
        if (isset($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $errors[] = "{$path}: must be one of ".json_encode($schema['enum']);
        }
        if (is_int($data) || is_float($data)) {
            $errors = array_merge($errors, $this->numeric($data, $schema, $path));
        }
        if (is_string($data)) {
            $errors = array_merge($errors, $this->string($data, $schema, $path));
        }
        if ($this->isObjectSchema($schema)) {
            $errors = array_merge($errors, $this->object($data, $schema, $path));
        }
        if (is_array($data) && array_is_list($data) && ($schema['type'] ?? null) === 'array') {
            $errors = array_merge($errors, $this->arrayItems($data, $schema, $path));
        }

        return $errors;
    }

    /** @return array<int,string> */
    private function numeric(int|float $d, array $s, string $p): array
    {
        $e = [];
        if (isset($s['minimum']) && $d < $s['minimum']) {
            $e[] = "{$p}: must be >= {$s['minimum']}";
        }
        if (isset($s['maximum']) && $d > $s['maximum']) {
            $e[] = "{$p}: must be <= {$s['maximum']}";
        }
        if (isset($s['exclusiveMinimum']) && $d <= $s['exclusiveMinimum']) {
            $e[] = "{$p}: must be > {$s['exclusiveMinimum']}";
        }
        if (isset($s['exclusiveMaximum']) && $d >= $s['exclusiveMaximum']) {
            $e[] = "{$p}: must be < {$s['exclusiveMaximum']}";
        }
        if (isset($s['multipleOf']) && $s['multipleOf'] > 0 && fmod((float) $d, (float) $s['multipleOf']) !== 0.0) {
            $e[] = "{$p}: must be a multiple of {$s['multipleOf']}";
        }

        return $e;
    }

    /** @return array<int,string> */
    private function string(string $d, array $s, string $p): array
    {
        $e = [];
        if (isset($s['minLength']) && mb_strlen($d) < $s['minLength']) {
            $e[] = "{$p}: must be at least {$s['minLength']} characters";
        }
        if (isset($s['maxLength']) && mb_strlen($d) > $s['maxLength']) {
            $e[] = "{$p}: must be at most {$s['maxLength']} characters";
        }
        if (isset($s['pattern']) && @preg_match('/'.str_replace('/', '\/', $s['pattern']).'/', $d) !== 1) {
            $e[] = "{$p}: must match pattern {$s['pattern']}";
        }

        return $e;
    }

    /** @return array<int,string> */
    private function object(mixed $d, array $s, string $p): array
    {
        if (! is_array($d)) {
            return ["{$p}: expected object"];
        }
        $e = [];
        foreach (($s['required'] ?? []) as $req) {
            if (! array_key_exists($req, $d)) {
                $e[] = "{$p}.{$req}: required";
            }
        }
        $props = $s['properties'] ?? [];
        foreach ($props as $key => $sub) {
            if (array_key_exists($key, $d)) {
                $e = array_merge($e, $this->validate($d[$key], $sub, "{$p}.{$key}"));
            }
        }
        if (($s['additionalProperties'] ?? true) === false) {
            foreach (array_diff(array_keys($d), array_keys($props)) as $extra) {
                $e[] = "{$p}.{$extra}: unexpected property";
            }
        }

        return $e;
    }

    /** @return array<int,string> */
    private function arrayItems(array $d, array $s, string $p): array
    {
        $e = [];
        if (isset($s['minItems']) && count($d) < $s['minItems']) {
            $e[] = "{$p}: must have at least {$s['minItems']} items";
        }
        if (isset($s['maxItems']) && count($d) > $s['maxItems']) {
            $e[] = "{$p}: must have at most {$s['maxItems']} items";
        }
        if (isset($s['items'])) {
            foreach ($d as $i => $it) {
                $e = array_merge($e, $this->validate($it, $s['items'], "{$p}[{$i}]"));
            }
        }

        return $e;
    }

    private function isObjectSchema(array $s): bool
    {
        return ($s['type'] ?? null) === 'object'
            || isset($s['properties']) || isset($s['required']) || array_key_exists('additionalProperties', $s);
    }

    private function typeMatches(mixed $d, string|array $type): bool
    {
        foreach ((array) $type as $t) {
            if ($this->isType($d, $t)) {
                return true;
            }
        }

        return false;
    }

    private function isType(mixed $d, string $t): bool
    {
        return match ($t) {
            'object' => is_array($d) && (! array_is_list($d) || $d === []),
            'array' => is_array($d) && (array_is_list($d) || $d === []),
            'string' => is_string($d),
            'integer' => is_int($d),
            'number' => is_int($d) || is_float($d),
            'boolean' => is_bool($d),
            'null' => $d === null,
            default => true,
        };
    }

    private function typeOf(mixed $d): string
    {
        return match (true) {
            is_array($d) && array_is_list($d) => 'array',
            is_array($d) => 'object',
            is_int($d) => 'integer',
            is_float($d) => 'number',
            is_string($d) => 'string',
            is_bool($d) => 'boolean',
            is_null($d) => 'null',
            default => gettype($d),
        };
    }
}
