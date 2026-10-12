<?php

declare(strict_types=1);

namespace App\Support\Resources;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Support\Exceptions\DomainException;
use App\Support\Schema\TableSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-driven create/update for simple master-data tables (units, lookups, tags, reorder rules,
 * vendor price lists, storage categories, put-away rules…). Payloads arrive already validated by the
 * global schema middleware (or the import validator); only schema columns are written, server-
 * controlled fields never; optimistic `version` checks apply where the table has one.
 */
final class ResourceWriter
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  class-string<TableSchema>  $schema
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $serverValues  server-derived columns (status, created_by…)
     */
    public function create(string $schema, string $model, array $data, ?UserAccount $actor, array $serverValues = []): Model
    {
        return DB::transaction(function () use ($schema, $model, $data, $actor, $serverValues) {
            /** @var Model $row */
            $row = new $model;
            $row->forceFill($schema::columns($data) + $serverValues + $this->actorColumns($row, $actor, true))->save();
            $this->audit->record($actor, $row->getTable().'.created', $row->getTable(), (string) $row->getKey(), null, $schema::columns($data));

            return $row->refresh();
        });
    }

    /**
     * @param  class-string<TableSchema>  $schema
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $data
     */
    public function update(string $schema, string $model, string $id, array $data, ?UserAccount $actor, array $serverValues = []): Model
    {
        return DB::transaction(function () use ($schema, $model, $id, $data, $actor, $serverValues) {
            /** @var Model $row */
            $row = $model::query()->lockForUpdate()->findOrFail($id);
            $hasVersion = Schema::hasColumn($row->getTable(), 'version');
            if ($hasVersion && isset($data['version']) && (int) $data['version'] !== (int) $row->getAttribute('version')) {
                throw DomainException::staleVersion((int) $data['version'], (int) $row->getAttribute('version'));
            }
            $changes = $schema::columns($data);
            $before = $row->only(array_keys($changes));
            $row->forceFill($changes + $serverValues + $this->actorColumns($row, $actor, false)
                + ($hasVersion ? ['version' => (int) $row->getAttribute('version') + 1] : []))->save();
            $this->audit->record($actor, $row->getTable().'.updated', $row->getTable(), (string) $row->getKey(), $before, $changes);

            return $row->refresh();
        });
    }

    /** @return array<string, string|null> */
    private function actorColumns(Model $row, ?UserAccount $actor, bool $creating): array
    {
        $cols = [];
        if (Schema::hasColumn($row->getTable(), 'updated_by')) {
            $cols['updated_by'] = $actor?->id;
        }
        if ($creating && Schema::hasColumn($row->getTable(), 'created_by')) {
            $cols['created_by'] = $actor?->id;
        }

        return $cols;
    }
}
