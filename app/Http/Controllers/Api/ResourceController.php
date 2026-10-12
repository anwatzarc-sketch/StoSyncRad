<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Platform\Config\PlatformConfig;
use App\Http\Support\ResourceRegistry;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Resources\ResourceWriter;
use App\Support\Schema\PropertyType;
use App\Support\Schema\TableSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Generic list/show/create/update for every registered resource (Chapter 11 §11.2, §11.6):
 *   GET  /{resource}?filter[status]=draft,submitted&filter[warehouse_id]=…&q=text&sort=-created_at&per_page=50&cursor=…
 *   GET  /{resource}/{id}
 *   POST /{resource}            (schema-validated; domain action/workflow writer)
 *   PATCH /{resource}/{id}
 * Lists are cursor-paginated and always constrained to the caller's warehouse scope; filters,
 * search and sort accept only schema-declared properties (identifiers are never taken from input).
 */
final class ResourceController extends ApiController
{
    public function __construct(private readonly Access $access, private readonly PlatformConfig $config) {}

    public function index(Request $request): JsonResponse
    {
        [$key, $cfg] = $this->resource($request);
        $actor = $this->actor($request);
        /** @var Builder $query */
        $query = $cfg['model']::query();
        $table = $query->getModel()->getTable();
        $this->scope($query, $cfg, $actor, $request->boolean('mine'));
        $schema = $cfg['schema'];
        $columns = Schema::getColumnListing($table);

        foreach ((array) $request->query('filter', []) as $field => $value) {
            if (! is_string($field) || ! in_array($field, $columns, true) || ! $this->filterable($schema, $field)) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "You cannot filter by `$field`.", ['field' => "filter.$field"]);
            }
            $values = is_array($value) ? $value : explode(',', (string) $value);
            $values = array_map(fn ($v) => $this->filterValue($schema, $field, (string) $v), $values);
            count($values) === 1 ? ($values[0] === null ? $query->whereNull($field) : $query->where($field, $values[0])) : $query->whereIn($field, $values);
        }
        foreach (['from' => '>=', 'to' => '<='] as $param => $op) {
            if ($request->filled("date_$param") && in_array('document_date', $columns, true)) {
                $date = (string) $request->query("date_$param");
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Dates use YYYY-MM-DD.', ['field' => "date_$param"]);
                $query->where('document_date', $op, $date);
            }
        }
        if ($request->filled('q')) {
            $searchable = $schema !== null ? $schema::searchable() : array_values(array_intersect(['code', 'name', 'document_number', 'sku', 'lot_number', 'serial_number', 'movement_number'], $columns));
            $searchable = array_values(array_intersect($searchable, $columns));
            if ($searchable !== []) {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr((string) $request->query('q'), 0, 100)).'%';
                $query->where(fn ($w) => array_map(fn ($c) => $w->orWhere($c, 'ILIKE', $term), $searchable));
            }
        }
        $sortable = array_merge($schema !== null ? $schema::sortable() : [], ['created_at', 'updated_at']);
        foreach (array_filter(explode(',', (string) $request->query('sort', ''))) as $s) {
            $field = ltrim($s, '-');
            if (! in_array($field, $sortable, true) || ! in_array($field, $columns, true)) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "You cannot sort by `$field`.", ['field' => 'sort']);
            }
            $query->orderBy($field, str_starts_with($s, '-') ? 'desc' : 'asc');
        }
        if (! $request->filled('sort')) {
            $query->orderByDesc(in_array('created_at', $columns, true) ? 'created_at' : $query->getModel()->getKeyName());
        }
        $query->orderBy($query->getModel()->getKeyName());
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $page = $query->cursorPaginate($perPage);

        return response()->json([
            'data' => $this->presenter()->collection($page->items()),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page->nextCursor()?->encode(), 'prev_cursor' => $page->previousCursor()?->encode(), 'resource' => $key],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        [, $cfg] = $this->resource($request);
        $model = $this->find($cfg, $id, $request);

        return response()->json(['data' => $this->presenter()->model($model->load($this->relations($cfg)), $this->relations($cfg))]);
    }

    public function store(Request $request): JsonResponse
    {
        [, $cfg] = $this->resource($request);
        $actor = $this->actor($request);
        $data = $this->payload($request);
        $model = $this->write($cfg, $data, $actor, null);

        return response()->json(['data' => $this->presenter()->model($model->load($this->relations($cfg)), $this->relations($cfg))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        [, $cfg] = $this->resource($request);
        $actor = $this->actor($request);
        $this->find($cfg, $id, $request);   // scope check before writing
        $model = $this->write($cfg, $this->payload($request), $actor, $id);

        return response()->json(['data' => $this->presenter()->model($model->load($this->relations($cfg)), $this->relations($cfg))]);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function resource(Request $request): array
    {
        $key = (string) $request->route()->getAction('resource_key');
        $cfg = ResourceRegistry::get($key);
        if (! empty($cfg['feature']) && ! $this->config->feature($cfg['feature'])) {
            throw DomainException::featureDisabled($cfg['feature']);
        }

        return [$key, $cfg];
    }

    private function find(array $cfg, string $id, Request $request): Model
    {
        Str::isUuid($id) || throw DomainException::notFound('Record');
        $query = $cfg['model']::query()->whereKey($id);
        $this->scope($query, $cfg, $this->actor($request), false);

        return $query->first() ?? throw DomainException::notFound(Str::headline(class_basename($cfg['model'])));
    }

    /** Warehouse (and own-record) scope: rows outside it simply do not exist for the caller. */
    private function scope(Builder $query, array $cfg, $actor, bool $mineOnly): void
    {
        $readable = $this->access->hasPermissionAnywhere($actor, $cfg['read']);
        $own = $cfg['own'] ?? null;
        if (! $readable || $mineOnly) {
            if ($own !== null && $this->access->hasPermissionAnywhere($actor, $own[0])) {
                $query->where($own[1], $actor->id);

                return;
            }
            if (! $readable) {
                throw DomainException::permissionDenied($cfg['read']);
            }
        }
        $columns = $cfg['warehouse'] ?? [];
        if ($columns === []) {
            return;
        }
        $allowed = $this->access->warehouseIdsFor($actor, $cfg['read']);
        if ($allowed === '*') {
            return;
        }
        $query->where(function ($w) use ($columns, $allowed, $own, $actor) {
            $ids = $allowed === [] ? ['00000000-0000-0000-0000-000000000000'] : $allowed;
            foreach ($columns as $c) {
                if (str_contains($c, '@')) {   // `fk@table.warehouse_column`: scope through the parent document
                    [$fk, $ref] = explode('@', $c, 2);
                    [$table, $column] = explode('.', $ref, 2);
                    $w->orWhereIn($fk, fn ($q) => $q->select('id')->from($table)->whereIn($column, $ids));

                    continue;
                }
                $w->orWhereIn($c, $ids);
            }
            if ($own !== null) {
                $w->orWhere($own[1], $actor->id);
            }
        });
    }

    private function write(array $cfg, array $data, $actor, ?string $id): Model
    {
        $writer = $cfg['writer'] ?? null;
        if ($writer === false || $cfg['schema'] === null) {
            throw DomainException::of(ErrorCode::NOT_FOUND, 'This resource is changed only through its workflow actions.');
        }
        if (is_array($writer)) {
            [$class, $method] = $writer;
            $params = (new ReflectionMethod($class, $method))->getParameters();
            $third = $params[2] ?? null;
            $type = $third?->getType();
            $service = app($class);
            if ($type instanceof ReflectionNamedType && $type->getName() === 'string') {
                return $service->{$method}($data, $actor, $id);
            }
            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Model::class)) {
                return $service->{$method}($data, $actor, $id === null ? null : $type->getName()::query()->findOrFail($id));
            }
            if ($id === null) {
                return $service->{$method}($data, $actor);   // create-only action; updates are schema-driven below
            }
        }
        $writerService = app(ResourceWriter::class);

        return $id === null
            ? $writerService->create($cfg['schema'], $cfg['model'], $data, $actor, $cfg['server'] ?? [])
            : $writerService->update($cfg['schema'], $cfg['model'], $id, $data, $actor);
    }

    /** @return array<int, string> */
    private function relations(array $cfg): array
    {
        return $cfg['with'] ?? [];
    }

    /** @param  class-string<TableSchema>|null  $schema */
    private function filterable(?string $schema, string $field): bool
    {
        if ($schema === null) {
            return $field === 'id' || $field === 'status' || str_ends_with($field, '_id') || str_ends_with($field, '_type');
        }
        $p = $schema::properties()[$field] ?? null;

        return ($p !== null && $p->filterable) || in_array($field, ['id', 'status', 'warehouse_id', 'branch_id', 'item_id'], true);
    }

    /** @param  class-string<TableSchema>|null  $schema */
    private function filterValue(?string $schema, string $field, string $value): ?string
    {
        if ($value === 'null') {
            return null;
        }
        $p = $schema !== null ? ($schema::properties()[$field] ?? null) : null;
        if ($p?->type === PropertyType::ENUM && $p->enumClass !== null && $p->enumClass::tryFrom($value) === null) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "`$value` is not a valid $field. Allowed: ".implode(', ', $p->enumClass::values()).'.', ['field' => "filter.$field"]);
        }
        if (($p?->type === PropertyType::UUID || str_ends_with($field, '_id') || $field === 'id') && ! Str::isUuid($value)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "`$field` filters take UUIDs.", ['field' => "filter.$field"]);
        }

        return $value;
    }
}
