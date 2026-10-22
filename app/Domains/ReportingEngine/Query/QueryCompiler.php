<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Query;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\ReportingEngine\Catalog\DatasetCatalog;
use App\Domains\ReportingEngine\Catalog\DatasetDefinition;
use App\Domains\ReportingEngine\Catalog\DatasetField;
use App\Domains\ReportingEngine\Enums\RelativeDateRange;
use App\Domains\ReportingEngine\Enums\ReportAggregate;
use App\Domains\ReportingEngine\Enums\ReportFieldType as T;
use App\Domains\ReportingEngine\Enums\ReportFilterOperator as Op;
use App\Support\Database\Search;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Report definition → parameterized SQL over the reporting views (Chapter 10 §10.4):
 * validate dataset/fields/joins/operators/expressions/aggregation, inject the runner's warehouse /
 * branch / own-record scope for the base AND every joined dataset, enforce field permissions on
 * every reference, bind every value, allow-list every identifier, apply row caps.
 *
 * Definition shape (JSON):
 *   fields:     ["item_sku", "lot.lot_number", {"field": "quantity_on_hand", "label": "Qty"},
 *                {"expr": {...}, "as": "cover_days", "label": "Days of cover"}]
 *   joins:      ["lot", "warehouse"]                       relationship codes of the base dataset
 *   filters:    {"op": "and", "conditions": [{"field": "stock_condition", "operator": "eq", "value": "available"}, {"op": "or", ...}]}
 *   parameters: [{"code": "from", "type": "date", "label": "From", "required": true}]   values: {"param": "from"}
 *   group_by:   ["warehouse_code"]
 *   aggregates: [{"fn": "sum", "field": "quantity_on_hand", "as": "on_hand"}, {"fn": "count", "as": "positions"}]
 *   sort:       [{"field": "on_hand", "dir": "desc"}]
 *   limit:      500
 */
final class QueryCompiler
{
    private const IDENT = '/^[a-z][a-z0-9_]{0,62}$/';

    private const NUMERIC = [T::INTEGER, T::DECIMAL, T::MONEY, T::QUANTITY];

    /** Validate structure only (seed validation): no permission, feature or scope checks. */
    private bool $structuralOnly = false;

    public function __construct(
        private readonly DatasetCatalog $catalog,
        private readonly Access $access,
        private readonly PlatformConfig $config,
        private readonly ExpressionCompiler $expressions,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $paramValues
     */
    public function compile(string $datasetCode, array $definition, UserAccount $runner, array $paramValues = [], ?int $limit = null, int $offset = 0,
        bool $structuralOnly = false): CompiledQuery
    {
        $this->structuralOnly = $structuralOnly;
        $maxKb = $this->config->int('reporting.max_definition_kb');
        if (strlen(json_encode($definition, JSON_THROW_ON_ERROR)) > $maxKb * 1024) {
            throw $this->invalid("The report definition exceeds {$maxKb} KB.");
        }
        $base = $this->catalog->get($datasetCode);
        $scope = ['datasets' => []];
        $where = [];
        $bindings = [];
        $baseScope = $this->authorizeDataset($base, $runner, 't0', $scope);

        // ---- joins (one level from the base dataset) --------------------------------------
        $aliases = ['' => ['alias' => 't0', 'dataset' => $base]];
        $joinSql = [];
        $joinBindings = [];
        $joins = array_values(array_unique((array) ($definition['joins'] ?? [])));
        if (count($joins) > $this->config->int('reporting.max_joins')) {
            throw $this->invalid('Too many joins (maximum '.$this->config->int('reporting.max_joins').').');
        }
        foreach ($joins as $n => $code) {
            $rel = $base->relationships[$code] ?? throw $this->invalid("`$code` is not a relationship of {$base->label}.");
            $target = $this->catalog->get($rel->target);
            $alias = 't'.($n + 1);
            $on = array_map(fn ($k) => "t0.`{$this->col($base, $k[0])}` = $alias.`{$this->col($target, $k[1])}`", $rel->keys);
            $targetScope = $this->authorizeDataset($target, $runner, $alias, $scope);
            $on = array_merge($on, $targetScope['sql']);
            array_push($joinBindings, ...$targetScope['bindings']);
            $joinSql[] = "LEFT JOIN {$target->view()} $alias ON ".implode(' AND ', $on);
            $aliases[$code] = ['alias' => $alias, 'dataset' => $target];
        }

        $params = $this->parameters((array) ($definition['parameters'] ?? []), $paramValues);
        $resolve = function (string $ref) use ($aliases, $runner): array {
            [$rel, $code] = str_contains($ref, '.') ? explode('.', $ref, 2) : ['', $ref];
            $a = $aliases[$rel] ?? throw $this->invalid("`$ref` refers to a relationship that is not joined.");
            /** @var DatasetDefinition $ds */
            $ds = $a['dataset'];
            $field = $ds->fields[$code] ?? throw $this->invalid("Unknown field `$ref`.");
            $this->authorizeField($ds, $field, $runner, $ref);

            return ['sql' => "{$a['alias']}.`{$field->code}`", 'type' => $field->type->value, 'field' => $field];
        };

        // ---- select list -------------------------------------------------------------------
        $selects = [];    // key => [sql, column meta, aggregated]
        $fields = (array) ($definition['fields'] ?? []);
        if ($fields === [] && empty($definition['aggregates'])) {
            $fields = array_slice(array_keys(array_filter($base->fields, fn (DatasetField $f) => $f->requiredPermission() === null
                || $this->access->hasPermissionAnywhere($runner, $f->requiredPermission()))), 0, 40);
        }
        foreach ($fields as $i => $spec) {
            if (is_string($spec)) {
                $spec = ['field' => $spec];
            }
            if (isset($spec['expr'])) {
                $key = $this->alias($spec['as'] ?? null, "fields.$i.as");
                $b = [];
                $e = $this->expressions->compile($spec['expr'], $resolve, $params, $b);
                $selects[$key] = ['sql' => $e['sql'], 'bindings' => $b, 'agg' => false,
                    'meta' => $this->meta($key, $spec['label'] ?? Str::headline($key), $e['type'], null, $spec['format'] ?? null)];

                continue;
            }
            $ref = (string) ($spec['field'] ?? throw $this->invalid("fields.$i needs a field or an expression."));
            $r = $resolve($ref);
            $key = $this->alias($spec['as'] ?? str_replace('.', '__', $ref), "fields.$i.as");
            $selects[$key] = ['sql' => $r['sql'], 'bindings' => [], 'agg' => false, 'ref' => $ref,
                'meta' => $this->meta($key, $spec['label'] ?? $r['field']->label, $r['type'], $r['field']->enumClass, $spec['format'] ?? null)];
        }

        // ---- aggregation -------------------------------------------------------------------
        $groupBy = array_values((array) ($definition['group_by'] ?? []));
        $aggregates = array_values((array) ($definition['aggregates'] ?? []));
        $aggregated = $groupBy !== [] || $aggregates !== [];
        $groupSql = [];
        if ($aggregated) {
            $groupKeys = [];
            foreach ($groupBy as $g) {
                $key = isset($selects[$g]) ? $g : str_replace('.', '__', (string) $g);
                if (! isset($selects[$key])) {
                    $r = $resolve((string) $g);
                    if (! $r['field']->groupable) {
                        throw $this->invalid("`$g` cannot be grouped.");
                    }
                    $selects = [$key => ['sql' => $r['sql'], 'bindings' => [], 'agg' => false, 'ref' => $g,
                        'meta' => $this->meta($key, $r['field']->label, $r['type'], $r['field']->enumClass, null)]] + $selects;
                }
                $groupKeys[] = $key;
            }
            foreach ($selects as $key => $s) {
                if (! in_array($key, $groupKeys, true)) {
                    throw $this->invalid("`$key` must be grouped or aggregated.");
                }
            }
            foreach ($aggregates as $i => $a) {
                $fn = ReportAggregate::tryFrom((string) ($a['fn'] ?? '')) ?? throw $this->invalid("aggregates.$i.fn must be one of: ".implode(', ', ReportAggregate::values()).'.');
                $key = $this->alias($a['as'] ?? ($fn->value.(isset($a['field']) ? '_'.str_replace('.', '__', (string) $a['field']) : '')), "aggregates.$i.as");
                $b = [];
                if (isset($a['expr'])) {
                    $arg = $this->expressions->compile($a['expr'], $resolve, $params, $b);
                } elseif (isset($a['field'])) {
                    $arg = $resolve((string) $a['field']);
                    if (! in_array($fn, [ReportAggregate::COUNT, ReportAggregate::COUNT_DISTINCT], true) && ! $arg['field']->aggregatable
                        && ! in_array($fn, [ReportAggregate::MIN, ReportAggregate::MAX], true)) {
                        throw $this->invalid("`{$a['field']}` cannot be aggregated with {$fn->value}.");
                    }
                } elseif ($fn === ReportAggregate::COUNT) {
                    $arg = ['sql' => '*', 'type' => 'integer'];
                } else {
                    throw $this->invalid("aggregates.$i needs a field.");
                }
                if (in_array($fn, [ReportAggregate::SUM, ReportAggregate::AVG], true) && ! in_array($arg['type'], ['integer', 'decimal', 'money', 'quantity'], true)) {
                    throw $this->invalid("{$fn->value} needs a numeric field.");
                }
                $sql = match ($fn) {
                    ReportAggregate::COUNT => "count({$arg['sql']})",
                    ReportAggregate::COUNT_DISTINCT => "count(DISTINCT {$arg['sql']})",
                    ReportAggregate::SUM => "sum({$arg['sql']})",
                    ReportAggregate::AVG => "round(avg({$arg['sql']}), 6)",
                    ReportAggregate::MIN => "min({$arg['sql']})",
                    ReportAggregate::MAX => "max({$arg['sql']})",
                };
                $type = in_array($fn, [ReportAggregate::COUNT, ReportAggregate::COUNT_DISTINCT], true) ? 'integer' : ($fn === ReportAggregate::AVG ? 'decimal' : $arg['type']);
                $selects[$key] = ['sql' => $sql, 'bindings' => $b, 'agg' => true,
                    'meta' => $this->meta($key, $a['label'] ?? Str::headline($key), $type, null, $a['format'] ?? null)];
            }
            $positions = array_flip(array_keys($selects));
            foreach ($groupKeys as $key) {
                $groupSql[] = (string) ($positions[$key] + 1);   // positional: expressions are not re-bound
            }
        }
        if ($selects === []) {
            throw $this->invalid('Select at least one field or aggregate.');
        }

        // ---- filters -----------------------------------------------------------------------
        $nodes = 0;
        $filterBindings = [];
        if (! empty($definition['filters'])) {
            $where[] = $this->filter($definition['filters'], $resolve, $params, $filterBindings, $nodes);
        }

        // ---- sort ----------------------------------------------------------------------------
        $order = [];
        $orderBindings = [];
        $sort = (array) ($definition['sort'] ?? []);
        if ($sort === [] && ! $aggregated) {
            $sort = array_map(fn ($s) => ['field' => $s[0], 'dir' => $s[1]], array_filter($base->defaultSort, fn ($s) => isset($selects[$s[0]])));
        }
        foreach ($sort as $i => $s) {
            $dir = strtolower((string) ($s['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            $ref = (string) ($s['field'] ?? '');
            $key = str_replace('.', '__', $ref);
            if (isset($selects[$key])) {
                // Inside an expression MySQL would resolve the alias to a same-named view column, so the
                // NULL test repeats the select expression (and its bound values) instead.
                $order[] = $this->nullsLast("`$key`", $dir, $selects[$key]['sql']);
                if ($dir === 'ASC') {
                    array_push($orderBindings, ...$selects[$key]['bindings']);
                }

                continue;
            }
            if ($aggregated) {
                throw $this->invalid("sort.$i: sort by a grouped or aggregated column.");
            }
            $r = $resolve($ref);
            if (! $r['field']->sortable) {
                throw $this->invalid("`$ref` is not sortable.");
            }
            $order[] = $this->nullsLast($r['sql'], $dir);
        }
        if (! $aggregated) {
            $order[] = "t0.`{$base->primaryKey}` ASC";   // stable pagination
        }

        // ---- assemble -------------------------------------------------------------------------
        $max = $this->config->int('reporting.max_sync_rows');
        $limit = max(1, min($limit ?? $max, (int) ($definition['limit'] ?? PHP_INT_MAX), $max));
        $selectParts = [];
        $bindingsOut = [];
        foreach ($selects as $key => $s) {
            $selectParts[] = "{$s['sql']} AS `$key`";
            array_push($bindingsOut, ...$s['bindings']);
        }
        array_push($bindingsOut, ...$joinBindings);
        $where = array_merge($baseScope['sql'], $where);
        array_push($bindingsOut, ...$baseScope['bindings'], ...$filterBindings, ...$orderBindings);
        $sql = 'SELECT '.implode(', ', $selectParts)." FROM {$base->view()} t0 ".implode(' ', $joinSql)
            .($where === [] ? '' : ' WHERE '.implode(' AND ', $where));
        if ($aggregated && $groupSql !== []) {
            $sql .= ' GROUP BY '.implode(', ', $groupSql);
        }
        if ($order !== []) {
            $sql .= ' ORDER BY '.implode(', ', $order);
        }
        $sql .= ' LIMIT '.($limit + 1).' OFFSET '.max(0, $offset);   // +1 detects truncation

        return new CompiledQuery($sql, $bindingsOut, array_values(array_map(fn ($s) => $s['meta'], $selects)), $limit, max(0, $offset), $scope, $base->code);
    }

    /**
     * Dataset-level authorization: permission (or own-record variant), feature flag, and the scope
     * predicate for this alias.
     *
     * @return array{sql: array<int, string>, bindings: array<int, mixed>}
     */
    private function authorizeDataset(DatasetDefinition $d, UserAccount $runner, string $alias, array &$scope): array
    {
        if ($this->structuralOnly) {
            return ['sql' => [], 'bindings' => []];
        }
        $permissionFeature = app(PermissionRegistry::class)->get($d->permission)?->featureFlag;
        foreach (array_filter([$d->feature, $permissionFeature]) as $flag) {
            if (! $this->config->feature($flag)) {
                throw DomainException::featureDisabled($flag);
            }
        }
        $ownPermission = preg_replace('/\.read$/', '.read_own', $d->permission);
        $hasFull = $this->access->hasPermissionAnywhere($runner, $d->permission);
        if (! $hasFull) {
            if ($d->ownColumn !== null && $ownPermission !== $d->permission && $this->access->hasPermissionAnywhere($runner, (string) $ownPermission)) {
                $scope['datasets'][$d->code] = ['own_records' => true];

                return ['sql' => ["$alias.`{$d->ownColumn}` = ?"], 'bindings' => [$runner->id]];
            }
            throw DomainException::of(ErrorCode::PERMISSION_DENIED, "You may not report on {$d->label}.", ['dataset' => $d->code, 'permission' => $d->permission]);
        }
        $warehouses = $this->access->warehouseIdsFor($runner, $d->permission);
        $scope['datasets'][$d->code] = ['warehouses' => $warehouses];
        if ($warehouses === '*' || ($d->warehouseScope === [] && $d->branchScope === null)) {
            return ['sql' => [], 'bindings' => []];
        }
        if ($d->warehouseScope !== []) {
            $parts = array_map(fn ($c) => $this->inList("$alias.`$c`", $warehouses), $d->warehouseScope);

            return ['sql' => ['('.implode(' OR ', $parts).')'], 'bindings' => array_merge(...array_fill(0, count($parts), array_values($warehouses)))];
        }
        $branches = Warehouse::query()->whereIn('id', $warehouses)->distinct()->pluck('branch_id')->all();

        return ['sql' => [$this->inList("$alias.`{$d->branchScope}`", $branches)], 'bindings' => array_values($branches)];
    }

    /** `<column> IN (?, …)` for a scope list; an empty list matches nothing. @param  array<int, string>  $ids */
    private function inList(string $column, array $ids): string
    {
        return $ids === [] ? 'FALSE' : "$column IN (".implode(', ', array_fill(0, count($ids), '?')).')';
    }

    /**
     * `NULLS LAST` in both directions. MySQL already sorts NULLs last when descending; ascending needs
     * an explicit NULL test first, on $nullTest when the sort key is a select alias.
     */
    private function nullsLast(string $sortKey, string $dir, ?string $nullTest = null): string
    {
        return $dir === 'DESC' ? "$sortKey DESC" : '('.($nullTest ?? $sortKey)." IS NULL) ASC, $sortKey ASC";
    }

    private function authorizeField(DatasetDefinition $d, DatasetField $f, UserAccount $runner, string $ref): void
    {
        if ($this->structuralOnly) {
            return;
        }
        $perm = $f->requiredPermission();
        if ($perm !== null && ! $this->access->hasPermissionAnywhere($runner, $perm)) {
            throw DomainException::of(ErrorCode::REPORT_FIELD_FORBIDDEN, "You may not use the field `$ref` ({$f->classification->value}).",
                ['field' => $ref, 'permission' => $perm]);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, mixed>  $bindings
     */
    private function filter(array $node, callable $resolve, array $params, array &$bindings, int &$nodes): string
    {
        if (++$nodes > $this->config->int('reporting.max_filter_nodes')) {
            throw $this->invalid('The filter has too many conditions.');
        }
        if (isset($node['op']) && in_array($node['op'], ['and', 'or'], true)) {
            $parts = [];
            foreach ((array) ($node['conditions'] ?? []) as $c) {   // a loop, not a closure: $bindings/$nodes are by reference
                $parts[] = $this->filter((array) $c, $resolve, $params, $bindings, $nodes);
            }

            return $parts === [] ? 'true' : '('.implode($node['op'] === 'and' ? ' AND ' : ' OR ', $parts).')';
        }
        $ref = (string) ($node['field'] ?? throw $this->invalid('Each filter condition needs a field.'));
        $r = $resolve($ref);
        /** @var DatasetField $field */
        $field = $r['field'];
        if (! $field->filterable) {
            throw $this->invalid("`$ref` cannot be filtered.");
        }
        $op = Op::tryFrom((string) ($node['operator'] ?? '')) ?? throw $this->invalid("Unknown operator for `$ref`. Allowed: ".implode(', ', Op::values()).'.');
        $allowed = match (true) {
            in_array($field->type, self::NUMERIC, true) => [Op::EQ, Op::NEQ, Op::GT, Op::GTE, Op::LT, Op::LTE, Op::BETWEEN, Op::IN, Op::NOT_IN, Op::IS_EMPTY, Op::IS_NOT_EMPTY],
            in_array($field->type, [T::DATE, T::DATETIME], true) => [Op::EQ, Op::NEQ, Op::GT, Op::GTE, Op::LT, Op::LTE, Op::BETWEEN, Op::RELATIVE_DATE, Op::IS_EMPTY, Op::IS_NOT_EMPTY],
            $field->type === T::STRING => [Op::EQ, Op::NEQ, Op::IN, Op::NOT_IN, Op::CONTAINS, Op::STARTS_WITH, Op::ENDS_WITH, Op::IS_EMPTY, Op::IS_NOT_EMPTY],
            $field->type === T::JSON => [Op::IS_EMPTY, Op::IS_NOT_EMPTY],
            default => [Op::EQ, Op::NEQ, Op::IN, Op::NOT_IN, Op::IS_EMPTY, Op::IS_NOT_EMPTY],
        };
        if (! in_array($op, $allowed, true)) {
            throw $this->invalid("`{$op->value}` cannot be used on `$ref` ({$field->type->value}).");
        }
        $col = $r['sql'];
        $value = $node['value'] ?? null;
        $param = fn (mixed $x) => is_array($x) && isset($x['param'])
            ? (array_key_exists($x['param'], $params) ? $params[$x['param']] : throw $this->invalid("Unknown parameter `{$x['param']}`."))
            : $x;
        $value = is_array($value) && ! isset($value['param']) ? array_map($param, $value) : $param($value);
        $ph = $this->placeholder($field->type);
        $v = fn (mixed $x) => $this->value($field, $x, $ref);

        switch ($op) {
            case Op::IS_EMPTY:
                return $field->type === T::STRING ? "($col IS NULL OR $col = '')" : "$col IS NULL";
            case Op::IS_NOT_EMPTY:
                return $field->type === T::STRING ? "($col IS NOT NULL AND $col <> '')" : "$col IS NOT NULL";
            case Op::IN:
            case Op::NOT_IN:
                $list = array_values((array) $value);
                if ($list === [] || count($list) > 500) {
                    throw $this->invalid("`$ref`: provide 1–500 values.");
                }
                foreach ($list as $x) {
                    $bindings[] = $v($x);
                }
                $phs = implode(', ', array_fill(0, count($list), $ph));

                return $op === Op::IN ? "$col IN ($phs)" : "($col IS NULL OR $col NOT IN ($phs))";
            case Op::BETWEEN:
                $pair = array_values((array) $value);
                if (count($pair) !== 2) {
                    throw $this->invalid("`$ref`: between needs two values.");
                }
                array_push($bindings, $v($pair[0]), $v($pair[1]));

                return "$col BETWEEN $ph AND $ph";
            case Op::RELATIVE_DATE:
                $range = RelativeDateRange::tryFrom((string) $value) ?? throw $this->invalid("`$ref`: unknown relative range. Allowed: ".implode(', ', RelativeDateRange::values()).'.');
                [$from, $to] = $this->relative($range);
                array_push($bindings, $from, $to);

                return "($col >= CAST(? AS DATE) AND $col < CAST(? AS DATE))";
            case Op::CONTAINS:
            case Op::STARTS_WITH:
            case Op::ENDS_WITH:
                $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $v($value));
                $bindings[] = match ($op) {
                    Op::CONTAINS => "%$esc%", Op::STARTS_WITH => "$esc%", default => "%$esc"
                };

                return Search::likeSql($col);
            default:
                $bindings[] = $v($value);
                $sqlOp = match ($op) {
                    Op::EQ => '=', Op::NEQ => '<>', Op::GT => '>', Op::GTE => '>=', Op::LT => '<', Op::LTE => '<='
                };

                return "$col $sqlOp $ph";
        }
    }

    /** Validate and normalize a filter/parameter value for a field type (values are always bound). */
    private function value(DatasetField $f, mixed $x, string $ref): mixed
    {
        $bad = fn (string $why) => $this->invalid("`$ref`: $why");
        if ($x === null) {
            throw $bad('a value is required.');
        }

        return match ($f->type) {
            T::UUID => Str::isUuid((string) $x) ? (string) $x : throw $bad('expected a UUID.'),
            T::ENUM => $f->enumClass !== null && $f->enumClass::tryFrom((string) $x) !== null ? (string) $x
                : throw $bad('"'.$x.'" is not one of: '.implode(', ', $f->enumClass::values()).'.'),
            T::INTEGER, T::DECIMAL, T::MONEY, T::QUANTITY => is_numeric($x) ? (string) $x : throw $bad('expected a number.'),
            T::DATE => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $x) ? (string) $x : throw $bad('expected a date (YYYY-MM-DD).'),
            T::DATETIME => strtotime((string) $x) !== false ? CarbonImmutable::parse((string) $x)->utc()->format('Y-m-d H:i:s.u') : throw $bad('expected a date-time.'),
            T::BOOLEAN => is_bool($x) ? $x : (in_array($x, ['true', '1', 1], true) ? true : (in_array($x, ['false', '0', 0], true) ? false : throw $bad('expected true or false.'))),
            default => mb_substr((string) $x, 0, 500),
        };
    }

    /**
     * Typed placeholder for a bound filter value. Numbers are cast to an exact DECIMAL: MySQL compares a
     * DECIMAL column with a string as double-precision floats. Date-times are bound as UTC.
     */
    private function placeholder(T $type): string
    {
        return match ($type) {
            T::INTEGER, T::DECIMAL, T::MONEY, T::QUANTITY => 'CAST(? AS DECIMAL(65,20))', T::DATE => 'CAST(? AS DATE)',
            T::DATETIME => 'CAST(? AS DATETIME(6))', default => '?',
        };
    }

    /** @return array{0: string, 1: string} [from, to) */
    private function relative(RelativeDateRange $r): array
    {
        $now = CarbonImmutable::now((string) $this->config->get('locale.timezone', []) ?: 'UTC')->startOfDay();
        [$from, $to] = match ($r) {
            RelativeDateRange::TODAY => [$now, $now->addDay()],
            RelativeDateRange::YESTERDAY => [$now->subDay(), $now],
            RelativeDateRange::THIS_WEEK => [$now->startOfWeek(), $now->startOfWeek()->addWeek()],
            RelativeDateRange::LAST_WEEK => [$now->startOfWeek()->subWeek(), $now->startOfWeek()],
            RelativeDateRange::THIS_MONTH => [$now->startOfMonth(), $now->startOfMonth()->addMonth()],
            RelativeDateRange::LAST_MONTH => [$now->startOfMonth()->subMonth(), $now->startOfMonth()],
            RelativeDateRange::THIS_QUARTER => [$now->firstOfQuarter(), $now->firstOfQuarter()->addMonths(3)],
            RelativeDateRange::LAST_QUARTER => [$now->firstOfQuarter()->subMonths(3), $now->firstOfQuarter()],
            RelativeDateRange::THIS_YEAR => [$now->startOfYear(), $now->startOfYear()->addYear()],
            RelativeDateRange::LAST_YEAR => [$now->startOfYear()->subYear(), $now->startOfYear()],
            RelativeDateRange::LAST_7_DAYS => [$now->subDays(6), $now->addDay()],
            RelativeDateRange::LAST_30_DAYS => [$now->subDays(29), $now->addDay()],
            RelativeDateRange::LAST_90_DAYS => [$now->subDays(89), $now->addDay()],
            RelativeDateRange::NEXT_30_DAYS => [$now, $now->addDays(31)],
        };

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * Declared run parameters, validated against their type; missing optional ones take defaults.
     *
     * @param  array<int, array<string, mixed>>  $declared
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function parameters(array $declared, array $values): array
    {
        $out = [];
        foreach ($declared as $i => $p) {
            $code = $this->alias($p['code'] ?? null, "parameters.$i.code");
            $type = T::tryFrom((string) ($p['type'] ?? 'string')) ?? throw $this->invalid("parameters.$i.type is not a field type.");
            $value = $values[$code] ?? ($p['default'] ?? null);
            if ($value === null || $value === '') {
                if (! empty($p['required'])) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Enter a value for '.($p['label'] ?? $code).'.', ['field' => "parameters.$code"]);
                }

                continue;
            }
            $out[$code] = $this->value(new DatasetField($code, (string) ($p['label'] ?? $code), $type, $p['enum_class'] ?? null), $value, "parameter $code");
        }

        return $out;
    }

    private function col(DatasetDefinition $d, string $column): string
    {
        if (! preg_match(self::IDENT, $column)) {
            throw new \LogicException("Invalid column `$column` in dataset {$d->code}.");
        }

        return $column;
    }

    private function alias(mixed $alias, string $path): string
    {
        if (! is_string($alias) || ! preg_match(self::IDENT, $alias)) {
            throw $this->invalid("$path must be a lowercase identifier (letters, digits, underscore).");
        }

        return $alias;
    }

    /** @return array{key: string, label: string, type: string, enum: ?string, format: ?array} */
    private function meta(string $key, string $label, string $type, ?string $enumClass, mixed $format): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'enum' => $enumClass !== null ? $enumClass::enumCode() : null,
            'format' => is_array($format) ? $format : null];
    }

    private function invalid(string $message): DomainException
    {
        return DomainException::of(ErrorCode::REPORT_DEFINITION_INVALID, $message, ['field' => 'definition']);
    }
}
