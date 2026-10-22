<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Query;

use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;

/**
 * Controlled expression grammar for calculated fields (Chapter 10 §10.3). An expression is a JSON
 * AST — never SQL text:
 *
 *   {"field": "quantity_on_hand"} | {"value": 3} | {"param": "rate"} | {"fn": "mul", "args": [node, node]}
 *
 * Functions are allow-listed and type-checked; field references go through the compiler's
 * resolver, so permission checks apply to every field an expression touches (aggregates cannot
 * launder restricted fields). Literals and parameters are always bound.
 */
final class ExpressionCompiler
{
    private const NUMERIC = ['integer', 'decimal', 'money', 'quantity'];

    private const MAX_DEPTH = 12;

    /** fn => [min args, max args (null = unbounded), argument kind, result kind] */
    private const FUNCTIONS = [
        'add' => [2, null, 'numeric', 'decimal'], 'sub' => [2, 2, 'numeric', 'decimal'], 'mul' => [2, null, 'numeric', 'decimal'],
        'div' => [2, 2, 'numeric', 'decimal'], 'neg' => [1, 1, 'numeric', 'decimal'], 'abs' => [1, 1, 'numeric', 'decimal'],
        'round' => [1, 2, 'numeric', 'decimal'], 'greatest' => [2, null, 'numeric', 'decimal'], 'least' => [2, null, 'numeric', 'decimal'],
        'percent' => [2, 2, 'numeric', 'decimal'],
        'coalesce' => [2, null, 'same', 'same'],
        'eq' => [2, 2, 'same', 'boolean'], 'neq' => [2, 2, 'same', 'boolean'], 'gt' => [2, 2, 'same', 'boolean'], 'gte' => [2, 2, 'same', 'boolean'],
        'lt' => [2, 2, 'same', 'boolean'], 'lte' => [2, 2, 'same', 'boolean'], 'and' => [2, null, 'boolean', 'boolean'], 'or' => [2, null, 'boolean', 'boolean'],
        'not' => [1, 1, 'boolean', 'boolean'], 'is_null' => [1, 1, 'any', 'boolean'],
        'case' => [3, null, 'case', 'same'],
        'lower' => [1, 1, 'string', 'string'], 'upper' => [1, 1, 'string', 'string'], 'concat' => [2, null, 'any', 'string'], 'length' => [1, 1, 'string', 'integer'],
        'days_between' => [2, 2, 'date', 'integer'], 'year' => [1, 1, 'date', 'integer'], 'month' => [1, 1, 'date', 'integer'], 'today' => [0, 0, 'none', 'date'],
        'date_trunc' => [2, 2, 'date_trunc', 'date'],
    ];

    private const TRUNC_UNITS = ['day', 'week', 'month', 'quarter', 'year'];

    /**
     * @param  callable(string): array{sql: string, type: string}  $field  resolves (and authorizes) a field reference
     * @param  array<string, mixed>  $params  run parameter values
     * @param  array<int, mixed>  $bindings  appended in order
     * @return array{sql: string, type: string}
     */
    public function compile(mixed $node, callable $field, array $params, array &$bindings, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw $this->invalid('The expression is nested too deeply.');
        }
        if (! is_array($node)) {
            throw $this->invalid('Each expression node must be an object.');
        }
        if (array_key_exists('field', $node)) {
            return $field((string) $node['field']);
        }
        if (array_key_exists('value', $node)) {
            return $this->literal($node['value'], $bindings);
        }
        if (array_key_exists('param', $node)) {
            if (! array_key_exists($node['param'], $params)) {
                throw $this->invalid("Unknown parameter `{$node['param']}`.");
            }

            return $this->literal($params[$node['param']], $bindings);
        }
        $fn = $node['fn'] ?? null;
        if (! is_string($fn) || ! isset(self::FUNCTIONS[$fn])) {
            throw $this->invalid('Unknown function `'.(is_string($fn) ? $fn : '?').'`. Allowed: '.implode(', ', array_keys(self::FUNCTIONS)).'.');
        }
        [$min, $max, $argKind, $result] = self::FUNCTIONS[$fn];
        $rawArgs = array_values((array) ($node['args'] ?? []));
        if (count($rawArgs) < $min || ($max !== null && count($rawArgs) > $max)) {
            throw $this->invalid("`$fn` takes ".($max === $min ? $min : "$min".($max === null ? '+' : "–$max")).' argument(s).');
        }
        if ($fn === 'date_trunc') {
            $unit = $rawArgs[0]['value'] ?? null;
            if (! in_array($unit, self::TRUNC_UNITS, true)) {
                throw $this->invalid('date_trunc unit must be one of: '.implode(', ', self::TRUNC_UNITS).'.');
            }
            $arg = $this->compile($rawArgs[1], $field, $params, $bindings, $depth + 1);
            $this->expect($arg, ['date', 'datetime'], $fn);

            $x = $arg['sql'];

            return ['sql' => match ($unit) {   // first day of the period; weeks start on Monday (ISO)
                'day' => "DATE($x)",
                'week' => "(DATE($x) - INTERVAL WEEKDAY($x) DAY)",
                'month' => "CAST(DATE_FORMAT($x, '%Y-%m-01') AS DATE)",
                'quarter' => "(MAKEDATE(YEAR($x), 1) + INTERVAL (QUARTER($x) - 1) QUARTER)",
                default => "MAKEDATE(YEAR($x), 1)",
            }, 'type' => 'date'];
        }
        if ($fn === 'greatest' || $fn === 'least') {
            return $this->nullSkipping($fn, $rawArgs, $field, $params, $bindings, $depth);
        }
        $args = array_map(fn ($a) => $this->compile($a, $field, $params, $bindings, $depth + 1), $rawArgs);
        $s = array_column($args, 'sql');

        switch ($argKind) {
            case 'numeric':
                foreach ($args as $a) {
                    $this->expect($a, self::NUMERIC, $fn);
                }
                break;
            case 'boolean':
                foreach ($args as $a) {
                    $this->expect($a, ['boolean'], $fn);
                }
                break;
            case 'string':
                foreach ($args as $a) {
                    $this->expect($a, ['string', 'enum'], $fn);
                }
                break;
            case 'date':
                foreach ($args as $a) {
                    $this->expect($a, ['date', 'datetime'], $fn);
                }
                break;
            case 'same':
                $this->compatible($args, $fn);
                break;
        }

        $sql = match ($fn) {
            'add' => '('.implode(' + ', $s).')',
            'sub' => "({$s[0]} - {$s[1]})",
            'mul' => '('.implode(' * ', $s).')',
            'div' => "({$s[0]} / NULLIF({$s[1]}, 0))",
            'neg' => "(-{$s[0]})",
            'abs' => "abs({$s[0]})",
            'round' => 'round('.$s[0].', '.(isset($rawArgs[1]['value']) ? max(0, min(8, (int) $rawArgs[1]['value'])) : 2).')',
            'percent' => "round(100.0 * {$s[0]} / NULLIF({$s[1]}, 0), 2)",
            'coalesce' => 'coalesce('.implode(', ', $s).')',
            'eq' => "({$s[0]} = {$s[1]})", 'neq' => "({$s[0]} <> {$s[1]})", 'gt' => "({$s[0]} > {$s[1]})", 'gte' => "({$s[0]} >= {$s[1]})",
            'lt' => "({$s[0]} < {$s[1]})", 'lte' => "({$s[0]} <= {$s[1]})",
            'and' => '('.implode(' AND ', $s).')', 'or' => '('.implode(' OR ', $s).')', 'not' => "(NOT {$s[0]})",
            'is_null' => "({$s[0]} IS NULL)",
            'case' => $this->caseSql($args, $fn),
            'lower' => "lower({$s[0]})", 'upper' => "upper({$s[0]})", 'length' => "CHAR_LENGTH({$s[0]})",
            // CONCAT_WS skips NULL arguments, as the reporting grammar's concat always has.
            'concat' => "CONCAT_WS('', ".implode(', ', array_map(fn ($x) => "CAST($x AS CHAR)", $s)).')',
            'days_between' => "DATEDIFF({$s[1]}, {$s[0]})",
            'year' => "YEAR({$s[0]})", 'month' => "MONTH({$s[0]})",
            'today' => 'CURRENT_DATE',
        };
        $type = match ($result) {
            'same' => $fn === 'case' ? $args[1]['type'] : $args[0]['type'],
            default => $result,
        };
        if (in_array($fn, ['add', 'sub', 'mul', 'neg', 'abs', 'greatest', 'least'], true) && count(array_unique(array_column($args, 'type'))) === 1) {
            $type = $args[0]['type'];   // e.g. money + money stays money
        }

        return ['sql' => $sql, 'type' => $type];
    }

    /**
     * greatest/least ignoring NULL arguments (MySQL's GREATEST/LEAST return NULL when any argument is
     * NULL). Each argument falls back to the others — COALESCE(a, b, c), COALESCE(b, a, c), … — so a
     * NULL never decides the result, which is NULL only when every argument is. Arguments are compiled
     * separately so each repeated copy gets its own bound values, in placeholder order.
     *
     * @param  array<int, mixed>  $rawArgs
     * @param  array<string, mixed>  $params
     * @param  array<int, mixed>  $bindings
     * @return array{sql: string, type: string}
     */
    private function nullSkipping(string $fn, array $rawArgs, callable $field, array $params, array &$bindings, int $depth): array
    {
        $parts = [];
        foreach ($rawArgs as $raw) {
            $own = [];
            $arg = $this->compile($raw, $field, $params, $own, $depth + 1);
            $this->expect($arg, self::NUMERIC, $fn);
            $parts[] = ['arg' => $arg, 'bindings' => $own];
        }
        $pieces = [];
        foreach (array_keys($parts) as $i) {
            $order = array_merge([$i], array_values(array_diff(array_keys($parts), [$i])));
            $pieces[] = 'COALESCE('.implode(', ', array_map(fn (int $j) => $parts[$j]['arg']['sql'], $order)).')';
            foreach ($order as $j) {
                array_push($bindings, ...$parts[$j]['bindings']);
            }
        }
        $types = array_unique(array_map(fn (array $p) => $p['arg']['type'], $parts));

        return ['sql' => $fn.'('.implode(', ', $pieces).')', 'type' => count($types) === 1 ? $parts[0]['arg']['type'] : 'decimal'];
    }

    /** @param  array<int, array{sql: string, type: string}>  $args */
    private function caseSql(array $args, string $fn): string
    {
        $n = count($args);
        if ($n % 2 === 0) {
            throw $this->invalid('`case` takes condition/value pairs followed by a default value.');
        }
        $parts = [];
        for ($i = 0; $i < $n - 1; $i += 2) {
            $this->expect($args[$i], ['boolean'], $fn);
            $parts[] = "WHEN {$args[$i]['sql']} THEN {$args[$i + 1]['sql']}";
        }
        $values = array_merge(array_map(fn ($i) => $args[$i], range(1, $n - 2, 2)), [$args[$n - 1]]);
        $this->compatible($values, $fn);

        return '(CASE '.implode(' ', $parts)." ELSE {$args[$n - 1]['sql']} END)";
    }

    /** @return array{sql: string, type: string} */
    private function literal(mixed $value, array &$bindings): array
    {
        if (is_bool($value)) {
            $bindings[] = $value;

            return ['sql' => '(? = 1)', 'type' => 'boolean'];
        }
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d{1,14}(\.\d{1,8})?$/', $value))) {
            $bindings[] = (string) $value;

            // Exact decimal: a bare string operand would make MySQL compute in double precision.
            return ['sql' => 'CAST(? AS DECIMAL(22,8))', 'type' => 'decimal'];
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $bindings[] = $value;

            return ['sql' => 'CAST(? AS DATE)', 'type' => 'date'];
        }
        if (is_string($value) && mb_strlen($value) <= 500) {
            $bindings[] = $value;

            return ['sql' => '?', 'type' => 'string'];
        }
        if ($value === null) {
            return ['sql' => 'NULL', 'type' => 'any'];
        }

        throw $this->invalid('Unsupported literal value.');
    }

    /** @param  array{type: string}  $arg  @param  array<int, string>  $types */
    private function expect(array $arg, array $types, string $fn): void
    {
        if ($arg['type'] !== 'any' && ! in_array($arg['type'], $types, true)) {
            throw $this->invalid("`$fn` cannot use a {$arg['type']} value (expects ".implode('/', $types).').');
        }
    }

    /** @param  array<int, array{type: string}>  $args */
    private function compatible(array $args, string $fn): void
    {
        $families = array_unique(array_filter(array_map(fn ($a) => match (true) {
            in_array($a['type'], self::NUMERIC, true) => 'numeric',
            in_array($a['type'], ['date', 'datetime'], true) => 'date',
            in_array($a['type'], ['string', 'enum', 'uuid'], true) => 'text',
            $a['type'] === 'any' => null,
            default => $a['type'],
        }, $args)));
        if (count($families) > 1) {
            throw $this->invalid("`$fn` mixes incompatible types: ".implode(', ', $families).'.');
        }
    }

    private function invalid(string $message): DomainException
    {
        return DomainException::of(ErrorCode::REPORT_DEFINITION_INVALID, $message, ['field' => 'definition']);
    }
}
